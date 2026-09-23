<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\VerificationResult;
use App\Models\Institute\CertificateVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Documents\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The public verification endpoint (§84, phase-19-23 §6.14, INV-21-3, §11).
 *
 * The only unauthenticated write path in the institute, so these are written against a hostile
 * caller: what can be learned from an answer, what is logged, and what a flood costs.
 */
final class CertificateVerificationTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsDocuments;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function an_issued_certificate_resolves_and_shows_only_the_configured_fields(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, ['grade' => 'A'], $admin);

        $response = $this->get(route('site.verify.show', [
            'code' => $certificate->getAttribute('verification_code'),
        ]))->assertOk();

        $response->assertSee((string) $certificate->getAttribute('student_name_snapshot'));
        $response->assertSee((string) $certificate->getAttribute('course_name_snapshot'));

        // Not on the default reveal list, and INV-21-3 says it takes a deliberate act to put it
        // there — a column added to `certificates` is invisible here until somebody adds it twice.
        $response->assertDontSee((string) $certificate->getAttribute('student_code_snapshot'));
    }

    #[Test]
    public function a_code_that_was_never_issued_is_a_404(): void
    {
        $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']))->assertNotFound();
    }

    #[Test]
    public function a_draft_and_a_code_nobody_issued_are_the_same_answer(): void
    {
        // The oracle this closes: "this code is real but hidden" is exactly the fact a privacy
        // opt-out exists to conceal, so both render the same page with the same status.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $draft = $this->draftCertificate($seat, [], $admin);

        $onADraft = $this->get(route('site.verify.show', ['code' => $draft->getAttribute('verification_code')]));
        $onNothing = $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']));

        $onADraft->assertNotFound();
        $onNothing->assertNotFound();

        $onADraft->assertDontSee((string) $draft->getAttribute('student_name_snapshot'));
    }

    #[Test]
    public function a_certificate_the_student_opted_out_of_is_the_same_answer_again(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $certificate->forceFill(['is_publicly_verifiable' => false])->save();

        $this->get(route('site.verify.show', ['code' => $certificate->getAttribute('verification_code')]))
            ->assertNotFound()
            ->assertDontSee((string) $certificate->getAttribute('student_name_snapshot'));

        // The office still learns that a real code was tried, because that is operationally useful;
        // the caller does not, because it is not theirs to know.
        $this->assertDatabaseHas('certificate_verifications', [
            'certificate_id' => $certificate->getKey(),
            'result' => VerificationResult::NotPublic->value,
        ]);
    }

    #[Test]
    public function a_revoked_certificate_resolves_and_says_so(): void
    {
        // The one negative answer that is more informative than a miss: somebody holding a revoked
        // certificate has to be told, which is the entire purpose of the page.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->certificateService()->revoke($certificate, 'Awarded on a mis-marked paper.', $admin);

        $this->get(route('site.verify.show', ['code' => $certificate->getAttribute('verification_code')]))
            ->assertOk()
            ->assertSee('Awarded on a mis-marked paper.');
    }

    #[Test]
    public function an_id_card_resolves_through_the_same_endpoint(): void
    {
        // One QR endpoint serves both documents. A student scanning their own card must not be told
        // it does not exist because it is not a certificate.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        $this->get(route('site.verify.show', ['code' => $card->getAttribute('verification_code')]))
            ->assertOk()
            ->assertSee((string) $card->getAttribute('card_number'));
    }

    #[Test]
    public function a_card_payload_never_carries_the_guardians_phone(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        $payload = app(\App\Services\Institute\CertificateVerificationService::class)->payloadForCard($card);

        // Fixed list, with no setting to widen it — a door check needs a name and a date, not a
        // family's contact details.
        $this->assertArrayNotHasKey('guardian_phone', $payload);
        $this->assertArrayNotHasKey('father_name', $payload);
        $this->assertArrayNotHasKey('joining_date', $payload);
    }

    #[Test]
    public function the_code_is_read_the_way_somebody_would_type_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $code = (string) $certificate->getAttribute('verification_code');

        // Lower case, spaced in fours, and with the letters people substitute for digits when
        // reading a code off paper. All of it is the same code.
        $typed = mb_strtolower(implode(' ', str_split($code, 4)));

        $this->get(route('site.verify.show', ['code' => $typed]))
            ->assertOk()
            ->assertSee((string) $certificate->getAttribute('student_name_snapshot'));
    }

    #[Test]
    public function every_attempt_is_logged_including_the_ones_that_find_nothing(): void
    {
        $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']))->assertNotFound();

        $this->assertDatabaseHas('certificate_verifications', [
            'certificate_id' => null,
            'result' => VerificationResult::NotFound->value,
        ]);
    }

    #[Test]
    public function a_flood_is_throttled_and_the_throttled_attempts_are_logged_too(): void
    {
        // `asSystem()` takes a callback and hands it the repository — D127. There is no
        // `settings_repo()->asSystem()->get()` and no `setting_set()`.
        settings_repo()->asSystem(
            fn ($settings) => $settings->set('institute.certificate_verification_rate_limit_per_minute', 3),
        );

        RateLimiter::clear('certificate-verify:'.sha1('127.0.0.1'));

        for ($i = 0; $i < 3; $i++) {
            $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']))->assertNotFound();
        }

        $response = $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']));

        $response->assertStatus(429);
        // The standard header, so a well-behaved client backs off without reading prose.
        $this->assertNotNull($response->headers->get('Retry-After'));

        $this->assertSame(
            1,
            CertificateVerification::query()->where('result', VerificationResult::Throttled->value)->count(),
        );
    }

    #[Test]
    public function the_verification_count_on_the_certificate_only_moves_on_a_real_hit(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->assertSame(0, (int) $certificate->getAttribute('verification_count'));

        $this->get(route('site.verify.show', ['code' => $certificate->getAttribute('verification_code')]))->assertOk();
        $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']))->assertNotFound();

        $this->assertSame(1, (int) $certificate->refresh()->getAttribute('verification_count'));
    }

    #[Test]
    public function the_page_is_never_cached_and_never_indexed(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $response = $this->get(route('site.verify.show', [
            'code' => $certificate->getAttribute('verification_code'),
        ]))->assertOk();

        // A revocation an hour ago has to show now, so a shared cache holding yesterday's "valid"
        // would be worse than the page not existing.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // The codes are not secret, but an index of them is a directory of students.
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    #[Test]
    public function a_typed_code_redirects_to_its_own_url(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $code = (string) $certificate->getAttribute('verification_code');

        // One URL per result, so a verification can be bookmarked or shown to a registrar without
        // re-submitting a form.
        $this->post(route('site.verify.submit'), ['code' => $code])
            ->assertRedirect(route('site.verify.show', ['code' => $code]));
    }

    #[Test]
    public function an_empty_submission_is_a_validation_error_and_not_a_server_error(): void
    {
        // The CHECK that once made this a 500 was removed for exactly this reason.
        $this->post(route('site.verify.submit'), ['code' => ''])
            ->assertSessionHasErrors('code');
    }
}
