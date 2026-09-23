<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\CertificateStatus;
use App\Models\Institute\Certificate;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Documents\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Drafting, issuing, revoking and replacing a certificate (§84, phase-19-23 §6.14, INV-21-4, §11).
 *
 * The invariant underneath all of it: **an issued certificate is a document somebody was handed**,
 * so from the moment it is issued nothing about what it says can change. Everything here is either
 * that rule or an exception to it that had to be argued for.
 */
final class CertificateLifecycleTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsDocuments;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Drafting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_draft_has_a_code_but_no_number(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($seat, [], $admin);

        $this->assertSame(CertificateStatus::Draft, $draft->status);
        // The number is the institute's sequence and is spent when a document exists. A draft that
        // took one would burn a number every time somebody changed their mind.
        $this->assertNull($draft->getAttribute('certificate_number'));
        // The code is generated now so the draft screen can show it, and stored.
        $this->assertSame(16, mb_strlen((string) $draft->getAttribute('verification_code')));
    }

    #[Test]
    public function a_draft_records_what_was_true_when_it_was_drafted(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($seat, [], $admin);

        $snapshot = (array) $draft->getAttribute('eligibility_snapshot');

        $this->assertNotEmpty($snapshot['rules'] ?? []);
    }

    #[Test]
    public function the_snapshots_are_taken_from_the_record_and_then_stop_following_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $certificate = $this->issuedCertificate($seat, [], $admin);
        $printedName = (string) $certificate->getAttribute('student_name_snapshot');

        $student = $seat->student;
        $student->forceFill(['name' => 'A Completely Different Person'])->save();

        // A certificate in somebody's hand cannot be re-worded by an edit to a profile. This is the
        // whole reason the snapshot columns exist rather than the print reading the relations.
        $this->assertSame($printedName, (string) $certificate->refresh()->getAttribute('student_name_snapshot'));
        $this->assertNotSame('A Completely Different Person', $printedName);
    }

    /*
    |--------------------------------------------------------------------------
    | Issuing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function issuing_assigns_a_number_a_date_and_a_stored_qr_payload(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $issued = $this->issuedCertificate($seat, [], $admin);

        $this->assertSame(CertificateStatus::Issued, $issued->status);
        $this->assertNotNull($issued->getAttribute('certificate_number'));
        $this->assertNotNull($issued->getAttribute('issued_on'));

        // Stored, not computed at print time: the URL a printed QR code points at must not move
        // because the application's own URL did (INV-21-4).
        $this->assertStringContainsString(
            (string) $issued->getAttribute('verification_code'),
            (string) $issued->getAttribute('qr_payload'),
        );
    }

    #[Test]
    public function numbers_are_sequential_and_never_reused(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $seat = $this->seat($batch, null, [], $admin);
            $numbers[] = (string) $this->issuedCertificate($seat, [], $admin)->getAttribute('certificate_number');
        }

        $this->assertSame($numbers, array_values(array_unique($numbers)));
    }

    #[Test]
    public function a_certificate_cannot_be_issued_twice(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $issued = $this->issuedCertificate($seat, [], $admin);

        $this->expectException(CourseRuleException::class);

        $this->certificateService()->issue($issued->refresh(), $admin, 'Trying again.');
    }

    #[Test]
    public function a_second_certificate_for_the_same_enrolment_is_refused_and_no_override_gets_past_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $this->issuedCertificate($seat, [], $admin);

        $second = $this->draftCertificate($seat, [], $admin);

        // Every other rule is a judgement an institute may override with a reason. This one is not:
        // `uq_ce_live` enforces it, so an override would produce a 1062 with no explanation attached
        // rather than a second certificate. The person gets the sentence instead.
        try {
            $this->certificateService()->issue($second, $admin, 'We would like two, please.');
            $this->fail('A second live certificate was issued for one enrolment.');
        } catch (CourseRuleException $e) {
            $this->assertStringContainsString(
                'certificate',
                mb_strtolower(implode(' ', $e->validator->errors()->all())),
            );
        }

        $this->assertSame(
            1,
            Certificate::query()
                ->where('student_batch_enrollment_id', $seat->getKey())
                ->where('status', CertificateStatus::Issued->value)
                ->count(),
        );
    }

    #[Test]
    public function issuing_against_a_failing_rule_needs_a_reason_and_keeps_the_failures(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($seat, [], $admin);

        // A freshly seated student meets nothing yet, so the unforced attempt is refused.
        try {
            $this->certificateService()->issue($draft, $admin, '');
            $this->fail('An ineligible certificate was issued with no reason given.');
        } catch (CourseRuleException) {
            // Expected.
        }

        $issued = $this->certificateService()->issue(
            $draft->refresh(),
            $admin,
            'Board approved on appeal, minute 14/2026.',
        );

        $snapshot = (array) $issued->getAttribute('eligibility_snapshot');

        $this->assertSame('Board approved on appeal, minute 14/2026.', $snapshot['override_reason'] ?? null);

        // An override that quietly rewrote the report to say "eligible" would be worse than no
        // record at all, so the failures are still in it.
        $failed = array_filter((array) ($snapshot['rules'] ?? []), static fn (array $r): bool => $r['passed'] === false);
        $this->assertNotEmpty($failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Immutability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_issued_certificate_refuses_a_change_to_what_it_says(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        // A model hook rather than a policy, because `Gate::before` lets a Super Admin past every
        // policy in the system before one runs (D124). A hard invariant has to live below that.
        $this->expectException(LogicException::class);

        $certificate->forceFill(['grade' => 'A+'])->save();
    }

    #[Test]
    public function an_issued_certificate_still_counts_its_prints_and_its_scans(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        // The named exceptions to immutability: none of them changes what the document says, and
        // without them a certificate could never be printed twice.
        $this->certificateService()->markPrinted($certificate, $admin);

        $this->assertSame(1, (int) $certificate->refresh()->getAttribute('print_count'));
    }

    #[Test]
    public function a_draft_can_be_discarded_and_an_issued_certificate_cannot(): void
    {
        // D138. INV-21-1 protects a certificate *number*, and a draft has none — no number, no QR
        // payload, no public page. There is nothing of it for the invariant to protect.
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($this->seat($batch, null, [], $admin), [], $admin);
        $draft->delete();
        $this->assertSoftDeleted('certificates', ['id' => $draft->getKey()]);

        $issued = $this->issuedCertificate($this->seat($batch, null, [], $admin), [], $admin);

        $this->expectException(LogicException::class);
        $issued->delete();
    }

    #[Test]
    public function a_discarded_draft_keeps_its_verification_code_reserved(): void
    {
        // Soft, never hard: a code allocated once is never handed to a second document, even a
        // document nobody was given. `freshCode()` checks `withTrashed()` for exactly this.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($seat, [], $admin);
        $code = (string) $draft->getAttribute('verification_code');

        $draft->delete();

        $this->assertDatabaseHas('certificates', ['verification_code' => $code]);
    }

    #[Test]
    public function nothing_can_erase_a_certificate_outright(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($seat, [], $admin);
        $draft->delete();

        // Refused in the model as well as the policy, because `Gate::before` hands a Super Admin
        // past every policy before one runs (D124).
        $this->expectException(LogicException::class);

        $draft->forceDelete();
    }

    /*
    |--------------------------------------------------------------------------
    | Revoking and replacing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function revoking_needs_a_reason_and_keeps_the_certificate(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        try {
            $this->certificateService()->revoke($certificate, '   ', $admin);
            $this->fail('A certificate was revoked with no reason.');
        } catch (CourseRuleException) {
            // Expected — the reason is published, so "wrong" is not an explanation.
        }

        $revoked = $this->certificateService()->revoke($certificate->refresh(), 'Marks were mis-entered.', $admin);

        $this->assertSame(CertificateStatus::Revoked, $revoked->status);
        $this->assertSame('Marks were mis-entered.', $revoked->getAttribute('revocation_reason'));
        // Nothing is deleted. The row, the number and the code all survive.
        $this->assertDatabaseHas('certificates', [
            'id' => $revoked->getKey(),
            'status' => CertificateStatus::Revoked->value,
        ]);
    }

    #[Test]
    public function a_draft_cannot_be_revoked(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $draft = $this->draftCertificate($seat, [], $admin);

        $this->expectException(CourseRuleException::class);

        $this->certificateService()->revoke($draft, 'Never mind.', $admin);
    }

    #[Test]
    public function a_replacement_can_only_follow_a_revocation(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        // A replacement exists because the one before it was withdrawn. Offering one while the
        // original is still valid would put two live certificates on one enrolment.
        try {
            $this->certificateService()->reissue($certificate, 'Lost in the post.', $admin);
            $this->fail('A live certificate was replaced without being revoked.');
        } catch (CourseRuleException) {
            // Expected.
        }

        $this->certificateService()->revoke($certificate->refresh(), 'Lost in the post.', $admin);

        $replacement = $this->certificateService()->reissue($certificate->refresh(), 'Lost in the post.', $admin);

        $this->assertSame(CertificateStatus::Draft, $replacement->status);
        $this->assertSame((int) $certificate->getKey(), (int) $replacement->getAttribute('reissue_of_id'));
        // Its own code, so the old certificate's QR still resolves to the old certificate.
        $this->assertNotSame(
            (string) $certificate->getAttribute('verification_code'),
            (string) $replacement->getAttribute('verification_code'),
        );
    }

    #[Test]
    public function one_certificate_can_only_be_replaced_once(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->certificateService()->revoke($certificate->refresh(), 'Printed on the wrong paper.', $admin);
        $this->certificateService()->reissue($certificate->refresh(), 'Printed on the wrong paper.', $admin);

        // `uq_ce_reissue` — a chain, not a fan. Two replacements of one original would make "which
        // certificate replaced this one?" a question with two answers.
        $this->expectException(\Throwable::class);

        $this->certificateService()->reissue($certificate->refresh(), 'And again.', $admin);
    }

    #[Test]
    public function a_replacement_is_issued_on_its_own_and_both_stay_on_the_register(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->certificateService()->revoke($certificate->refresh(), 'Damaged in the post.', $admin);
        $replacement = $this->certificateService()->reissue($certificate->refresh(), 'Damaged in the post.', $admin);

        $issued = $this->certificateService()->issue($replacement, $admin, 'Replacement for a damaged certificate.');

        $this->assertSame(CertificateStatus::Issued, $issued->status);
        $this->assertNotSame(
            (string) $certificate->getAttribute('certificate_number'),
            (string) $issued->getAttribute('certificate_number'),
        );

        $this->assertSame(2, Certificate::query()->where('student_batch_enrollment_id', $seat->getKey())->count());
    }
}
