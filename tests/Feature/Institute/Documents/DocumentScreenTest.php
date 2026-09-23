<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\CertificateStatus;
use App\Enums\IdCardStatus;
use App\Enums\PrintTemplateType;
use App\Models\Institute\PrintTemplate;
use App\Models\Institute\StudentIdCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Documents\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Every Phase 21 screen renders (phase-19-23 §7.6, §11).
 *
 * **This is a crash test, not a correctness test, and that distinction is D133.** A view that names a
 * column the model does not have, a component prop that does not exist, or a relation that was never
 * loaded fails here with a stack trace rather than in front of somebody mid-task. What it cannot tell
 * you is whether the right number is on the page — the lifecycle and eligibility tests do that.
 *
 * It is deliberately exhaustive over *states*, not just routes: a certificate screen renders
 * differently for a draft, an issued certificate and a revoked one, and two of those three have
 * branches this file is the only thing exercising.
 */
final class DocumentScreenTest extends TestCase
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
    | Print templates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_template_screens_render(): void
    {
        $admin = $this->createSuperAdmin();
        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $this->actingAs($admin)->get(route('admin.print-templates.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.print-templates.show', $template))->assertOk();
        $this->actingAs($admin)->get(route('admin.print-templates.edit', $template))->assertOk();
    }

    #[Test]
    public function the_create_screen_renders_for_every_kind_of_document(): void
    {
        $admin = $this->createSuperAdmin();

        foreach (PrintTemplateType::cases() as $type) {
            $this->actingAs($admin)
                ->get(route('admin.print-templates.create', ['type' => $type->value]))
                ->assertOk()
                ->assertSee($type->label());
        }
    }

    #[Test]
    public function a_fresh_install_already_lists_a_template_for_every_printable_kind(): void
    {
        // Not an empty state: `PrintTemplateSeeder` runs with the rest, so the first screen a new
        // install shows has one default of each kind on it. That is the point of the seeder — the
        // first issue attempt must not fail on a missing template rather than on anything the user
        // did.
        $response = $this->actingAs($this->createSuperAdmin())
            ->get(route('admin.print-templates.index'))
            ->assertOk();

        foreach (['DEFAULT-CERTIFICATE', 'DEFAULT-ID-CARD', 'DEFAULT-RESULT-CARD'] as $code) {
            $response->assertSee($code);
        }
    }

    #[Test]
    public function the_preview_renders_with_example_values_and_no_student(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $response = $this->actingAs($admin)
            ->get(route('admin.print-templates.preview', $template))
            ->assertOk()
            ->assertSee('SPECIMEN');

        // The point of the preview, asserted rather than assumed: a real student's name is never on it.
        $response->assertDontSee((string) $certificate->getAttribute('student_name_snapshot'));
        $response->assertDontSee((string) $certificate->getAttribute('certificate_number'));
    }

    #[Test]
    public function every_kind_of_template_previews(): void
    {
        $admin = $this->createSuperAdmin();

        foreach (PrintTemplateType::cases() as $type) {
            $this->actingAs($admin)
                ->get(route('admin.print-templates.preview', $this->seededTemplate($type)))
                ->assertOk();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Certificates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_certificate_register_and_candidate_screens_render(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $this->draftCertificate($seat, [], $admin);

        $this->actingAs($admin)->get(route('admin.certificates.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.certificates.eligible'))->assertOk();
    }

    #[Test]
    public function the_candidate_screen_names_the_rule_and_the_number_behind_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $report = $this->eligibilityService()->check($seat);

        $response = $this->actingAs($admin)->get(route('admin.certificates.eligible'))->assertOk();

        // "Not eligible" is never the whole answer — §84's requirement, asserted on the screen that
        // has to satisfy it.
        foreach ($report->rules as $rule) {
            $response->assertSee($rule->message, false);
        }
    }

    #[Test]
    public function the_certificate_screen_renders_for_a_draft_an_issued_and_a_revoked_one(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($seat, [], $admin);
        $this->actingAs($admin)->get(route('admin.certificates.show', $draft))->assertOk();

        $issued = $this->certificateService()->issue($draft->refresh(), $admin, 'Issued for a screen test.');
        $this->actingAs($admin)
            ->get(route('admin.certificates.show', $issued))
            ->assertOk()
            ->assertSee((string) $issued->getAttribute('certificate_number'));

        $revoked = $this->certificateService()->revoke($issued->refresh(), 'Issued to the wrong student.', $admin);
        $this->actingAs($admin)
            ->get(route('admin.certificates.show', $revoked))
            ->assertOk()
            ->assertSee('Issued to the wrong student.');
    }

    #[Test]
    public function an_issued_certificate_prints_and_downloads(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->actingAs($admin)
            ->get(route('admin.certificates.print', $certificate))
            ->assertOk()
            ->assertSee((string) $certificate->getAttribute('student_name_snapshot'));

        $response = $this->actingAs($admin)->get(route('admin.certificates.pdf', $certificate));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        // A certificate names a student, so it is never held in a shared cache.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function a_revoked_certificate_still_prints_and_says_so(): void
    {
        // Somebody has to be able to produce the document a dispute is about. It just never leaves
        // the building looking valid.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $revoked = $this->certificateService()->revoke($certificate, 'Withdrawn after an appeal.', $admin);

        $this->actingAs($admin)
            ->get(route('admin.certificates.print', $revoked))
            ->assertOk()
            ->assertSee('REVOKED');
    }

    #[Test]
    public function a_draft_cannot_be_printed_even_by_a_super_admin(): void
    {
        // **The policy is not enough here, and that is D124.** `CertificatePolicy::print()` refuses a
        // draft, but `Gate::before` allows a Super Admin everything before a policy runs — so the
        // one role most able to hand out an unverifiable document was the one role the policy did
        // not stop. The refusal now lives in the service, below the gate.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $draft = $this->draftCertificate($seat, [], $admin);

        $this->actingAs($admin)
            ->get(route('admin.certificates.print', $draft))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, (int) $draft->refresh()->getAttribute('print_count'));

        // And for anybody who is not a Super Admin the policy answers first, as it should.
        $printer = $this->createUserWithPermissions([
            'certificates.view_any', 'certificates.view', 'certificates.print',
        ]);

        $this->actingAs($printer)->get(route('admin.certificates.print', $draft))->assertForbidden();
    }

    #[Test]
    public function a_reprint_says_which_copy_it_is(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        // The first print is the original and announces nothing.
        $this->actingAs($admin)
            ->get(route('admin.certificates.print', $certificate))
            ->assertOk()
            ->assertDontSee('Reprint');

        $this->actingAs($admin)
            ->get(route('admin.certificates.print', $certificate))
            ->assertOk()
            ->assertSee('Reprint #2');
    }

    #[Test]
    public function the_verification_log_screen_renders(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->actingAs($admin)
            ->get(route('admin.certificates.verifications', $certificate))
            ->assertOk()
            ->assertSee('Nobody has checked it yet');

        $this->get(route('site.verify.show', ['code' => $certificate->getAttribute('verification_code')]))->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.certificates.verifications', $certificate))
            ->assertOk()
            ->assertDontSee('Nobody has checked it yet');
    }

    #[Test]
    public function the_certificate_export_streams_a_csv(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $response = $this->actingAs($admin)
            ->get(route('admin.certificates.export', ['format' => 'csv']))
            ->assertOk();

        $this->assertStringContainsString(
            (string) $certificate->getAttribute('certificate_number'),
            $response->streamedContent(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Student ID cards
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_card_register_and_candidate_screens_render(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        // Before anything is issued, the student is a candidate and the register is empty.
        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.create'))
            ->assertOk()
            ->assertSee((string) $seat->student?->getAttribute('name'));

        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.index'))
            ->assertOk()
            ->assertSee('No cards yet');

        $card = $this->issuedCard($seat, [], $admin);

        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.index'))
            ->assertOk()
            ->assertSee((string) $card->getAttribute('card_number'));

        // …and now they are not a candidate, because they hold a live card.
        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.create'))
            ->assertOk()
            ->assertSee('Everybody has a card');
    }

    #[Test]
    public function the_card_screen_renders_for_a_live_card_and_for_a_replaced_one(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $card = $this->issuedCard($seat, [], $admin);
        $this->actingAs($admin)->get(route('admin.student-id-cards.show', $card))->assertOk();

        // `replace()` is for a card that has stopped being live. An *active* one is replaced by
        // issuing another, which retires it in the same transaction — the service says so, and
        // `IdCardStatus::isReplaceable()` is what the screen reads before offering the button.
        $this->cardService()->changeStatus($card, IdCardStatus::Lost, 'Left on a bus.', $admin);

        $replacement = $this->cardService()->replace($card->refresh(), 'Snapped in half.', $admin);

        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.show', $card->refresh()))
            ->assertOk()
            ->assertSee((string) $replacement->getAttribute('card_number'));

        $this->actingAs($admin)->get(route('admin.student-id-cards.show', $replacement))->assertOk();
    }

    #[Test]
    public function a_card_prints_one_at_a_time_and_in_a_batch(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);
        $second = $this->seat($batch, null, [], $admin);
        $third = $this->seat($batch, null, [], $admin);

        $one = $this->issuedCard($second, [], $admin);
        $two = $this->issuedCard($third, [], $admin);

        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.print', $one))
            ->assertOk()
            ->assertSee((string) $one->getAttribute('student_name_snapshot'));

        $this->actingAs($admin)
            ->post(route('admin.student-id-cards.batch-print'), ['card_ids' => [$one->getKey(), $two->getKey()]])
            ->assertOk()
            ->assertSee((string) $one->getAttribute('student_name_snapshot'))
            ->assertSee((string) $two->getAttribute('student_name_snapshot'));
    }

    #[Test]
    public function a_card_that_is_no_longer_live_prints_with_its_state_across_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        $this->cardService()->changeStatus($card, IdCardStatus::Lost, 'Left on a bus.', $admin);

        $this->actingAs($admin)
            ->get(route('admin.student-id-cards.print', $card->refresh()))
            ->assertOk()
            ->assertSee('REPORTED LOST');
    }

    #[Test]
    public function the_card_export_streams_a_csv(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $card = $this->issuedCard($seat, [], $admin);

        $response = $this->actingAs($admin)
            ->get(route('admin.student-id-cards.export', ['format' => 'csv']))
            ->assertOk();

        $this->assertStringContainsString(
            (string) $card->getAttribute('card_number'),
            $response->streamedContent(),
        );
    }


    /*
    |--------------------------------------------------------------------------
    | The screens added to match the contract's route table
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_token_reference_and_the_single_enrolment_report_render(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $this->actingAs($admin)
            ->get(route('admin.print-templates.tokens', $template))
            ->assertOk()
            ->assertSee('{student_name}');

        $this->actingAs($admin)
            ->get(route('admin.certificates.eligibility', $seat))
            ->assertOk()
            ->assertSee((string) $seat->student?->getAttribute('name'));
    }

    #[Test]
    public function the_draft_form_renders_with_and_without_an_enrolment(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        // With nobody chosen it points at the list that can answer "which student?" properly.
        $this->actingAs($admin)
            ->get(route('admin.certificates.create'))
            ->assertOk()
            ->assertSee('Which student?');

        $this->actingAs($admin)
            ->get(route('admin.certificates.create', ['enrollment' => $seat->getKey()]))
            ->assertOk()
            ->assertSee('Create the draft');
    }

    #[Test]
    public function duplicating_a_template_lands_on_a_retired_copy(): void
    {
        $admin = $this->createSuperAdmin();
        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $this->actingAs($admin)
            ->post(route('admin.print-templates.duplicate', $template))
            ->assertRedirect();

        $copy = PrintTemplate::query()
            ->where('code', $template->getAttribute('code').'-COPY')
            ->firstOrFail();

        // A redesign that went live the moment it was created would print on the next certificate
        // issued, which is the opposite of why somebody duplicated instead of editing.
        $this->assertFalse((bool) $copy->getAttribute('is_active'));
        $this->assertFalse((bool) $copy->getAttribute('is_default'));
        $this->assertSame($template->getAttribute('body_html'), $copy->getAttribute('body_html'));
    }

    #[Test]
    public function a_draft_can_be_edited_and_an_issued_certificate_cannot(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        $draft = $this->draftCertificate($this->seat($batch, null, [], $admin), [], $admin);

        $this->actingAs($admin)
            ->put(route('admin.certificates.update', $draft), ['notes' => 'Collected in person.'])
            ->assertRedirect();

        $this->assertSame('Collected in person.', (string) $draft->refresh()->getAttribute('notes'));

        $issued = $this->issuedCertificate($this->seat($batch, null, [], $admin), [], $admin);
        $before = (string) $issued->getAttribute('notes');

        // D124 again: the policy refuses this and a Super Admin never reaches the policy, so what
        // actually holds is `updateDraft()`'s own refusal. Either way nothing changes.
        $this->actingAs($admin)
            ->put(route('admin.certificates.update', $issued), ['notes' => 'Changing my mind.'])
            ->assertSessionHasErrors('status');

        $this->assertSame($before, (string) $issued->refresh()->getAttribute('notes'));
    }

    #[Test]
    public function a_bulk_issue_issues_what_it_can_and_leaves_the_rest_as_drafts(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        $first = $this->draftCertificate($this->seat($batch, null, [], $admin), [], $admin);
        $second = $this->draftCertificate($this->seat($batch, null, [], $admin), [], $admin);

        // No override is accepted in bulk, and a freshly seated student meets nothing — so both are
        // refused, individually, and both are still drafts afterwards.
        $this->actingAs($admin)
            ->post(route('admin.certificates.bulk-issue'), [
                'certificate_ids' => [$first->getKey(), $second->getKey()],
            ])
            ->assertRedirect();

        $this->assertSame(CertificateStatus::Draft, $first->refresh()->status);
        $this->assertSame(CertificateStatus::Draft, $second->refresh()->status);
    }

    #[Test]
    public function a_card_pdf_streams_and_a_bulk_issue_skips_the_student_with_no_photograph(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);
        $this->seedTemplates();

        $withPhoto = $this->seat($batch, null, [], $admin);
        $this->givePhoto($withPhoto->student);

        $withoutPhoto = $this->seat($batch, null, [], $admin);

        $this->actingAs($admin)
            ->post(route('admin.student-id-cards.bulk-issue'), [
                'enrollment_ids' => [$withPhoto->getKey(), $withoutPhoto->getKey()],
            ])
            ->assertRedirect();

        // One student's missing photograph must not cost the rest of the batch their cards.
        $this->assertSame(1, StudentIdCard::query()->count());

        $card = StudentIdCard::query()->firstOrFail();

        $response = $this->actingAs($admin)->get(route('admin.student-id-cards.pdf', $card));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /*
    |--------------------------------------------------------------------------
    | The student and teacher panels
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_student_sees_their_own_issued_certificates_and_their_card(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);

        $certificate = $this->issuedCertificate($seat, [], $admin);
        $card = $this->issuedCard($seat, [], $admin);

        $user = $this->loginFor($seat);

        $this->actingAs($user)
            ->get(route('student.certificates.index'))
            ->assertOk()
            ->assertSee((string) $certificate->getAttribute('certificate_number'));

        $this->actingAs($user)
            ->get(route('student.id-card.show'))
            ->assertOk()
            ->assertSee((string) $card->getAttribute('card_number'));

        $this->actingAs($user)->get(route('student.certificates.pdf', $certificate))->assertOk();
        $this->actingAs($user)->get(route('student.id-card.pdf'))->assertOk();
    }

    #[Test]
    public function a_student_never_sees_a_draft_of_their_own_or_another_students_certificate(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch] = $this->batchWithOneStudent($admin);

        $mine = $this->seat($batch, null, [], $admin);
        $theirs = $this->seat($batch, null, [], $admin);

        $myDraft = $this->draftCertificate($mine, [], $admin);
        $theirCertificate = $this->issuedCertificate($theirs, [], $admin);

        $user = $this->loginFor($mine);

        // Both are a 404, and they are the *same* 404: a student learns nothing from the difference
        // between "not yours" and "not issued yet".
        $this->actingAs($user)->get(route('student.certificates.pdf', $myDraft))->assertNotFound();
        $this->actingAs($user)->get(route('student.certificates.pdf', $theirCertificate))->assertNotFound();

        $this->actingAs($user)
            ->get(route('student.certificates.index'))
            ->assertOk()
            ->assertDontSee((string) $theirCertificate->getAttribute('certificate_number'));
    }

    #[Test]
    public function a_teacher_sees_the_candidates_on_their_own_batch_and_nothing_else(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch, $seat] = $this->batchWithOneStudent($admin);

        $user = $this->teacherWithLogin($batch);
        $this->assertNotNull($user, 'The fixture batch has no teacher, so the panel cannot be reached.');

        $this->actingAs($user)
            ->get(route('teacher.certificates.candidates', $batch))
            ->assertOk()
            ->assertSee((string) $seat->student?->getAttribute('name'));

        $other = $this->runningBatch($this->courseWithOutline([1], $admin), [], $admin);

        // A batch that is not theirs is a 404, not a 403: a 403 confirms it exists.
        $this->actingAs($user)
            ->get(route('teacher.certificates.candidates', $other))
            ->assertNotFound();
    }

    #[Test]
    public function a_teacher_is_refused_every_write_in_the_phase(): void
    {
        $admin = $this->createSuperAdmin();
        [$batch, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $user = $this->teacherWithLogin($batch);

        // §9.3, in as many words: 403 on issuing, revoking, replacing, on the register and on
        // templates. The candidate list is the whole of their access.
        $this->actingAs($user)->get(route('admin.certificates.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.certificates.revoke', $certificate))->assertForbidden();
        $this->actingAs($user)->get(route('admin.print-templates.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.student-id-cards.index'))->assertForbidden();
    }


    /**
     * A login for the student on this enrolment, created the way the office creates one.
     *
     * The register seats a student without an account — plenty of institutes never give one — so a
     * panel test has to ask for it explicitly rather than assume the fixture made one.
     */
    private function loginFor(\App\Models\Institute\StudentBatchEnrollment $enrollment): \App\Models\User
    {
        $student = $enrollment->student;

        $user = $student?->user ?? app(\App\Services\Institute\StudentService::class)
            ->createLogin($student, $this->createSuperAdmin());

        $this->assertNotNull($user, 'The student could not be given a login, so the panel is unreachable.');

        $user->forceFill(['must_change_password' => false])->saveQuietly();

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | The public verification page
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_public_verification_screens_render(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $this->get(route('site.verify.index'))->assertOk();

        $this->get(route('site.verify.show', ['code' => $certificate->getAttribute('verification_code')]))
            ->assertOk()
            ->assertSee((string) $certificate->getAttribute('student_name_snapshot'));

        // A code nobody issued is a rendered page with a genuine 404 — not a stack trace, and not a
        // 200 with sad wording. A crawler, a monitor and a screen reader should all agree nothing
        // is there.
        $this->get(route('site.verify.show', ['code' => 'ZZZZZZZZZZZZZZZZ']))
            ->assertNotFound()
            ->assertSee('Verify');
    }

    #[Test]
    public function the_public_form_survives_being_submitted_empty(): void
    {
        // The CHECK constraint that once made this a 500 is gone for exactly this reason — somebody
        // pressing the button on an empty box is the most ordinary thing that can happen to a form.
        $this->post(route('site.verify.submit'), ['code' => ''])->assertStatus(302);
    }
}
