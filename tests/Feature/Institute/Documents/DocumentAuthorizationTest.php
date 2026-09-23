<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\PrintTemplateType;
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
 * Who may reach what (phase-19-23 §4.1, §4.2, §9, §11).
 *
 * **`print_templates` being its own module is the interesting case here**, and §4.1 asks for it by
 * name: `body_html` is powerful whatever it prints, so a designer can hold the certificate layout
 * with no sight of a student record — and somebody who issues certificates all day has no say in
 * what HTML a PDF renderer is handed. Both halves of that are asserted below, because a permission
 * split nobody tests is a permission split that quietly becomes one permission.
 */
final class DocumentAuthorizationTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsDocuments;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_staff_user_with_no_permissions_reaches_none_of_it(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);
        $card = $this->issuedCard($seat, [], $admin);
        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $nobody = $this->createUserWithPermissions([]);

        $this->actingAs($nobody)->get(route('admin.certificates.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.certificates.show', $certificate))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.certificates.eligible'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.student-id-cards.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.student-id-cards.show', $card))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.print-templates.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.print-templates.show', $template))->assertForbidden();
    }

    #[Test]
    public function a_designer_can_hold_the_templates_without_seeing_a_single_student(): void
    {
        // §4.1's reason for the module existing, asserted rather than assumed.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);
        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $designer = $this->createUserWithPermissions([
            'print_templates.view_any', 'print_templates.view', 'print_templates.edit',
            'print_templates.create', 'print_templates.print',
        ]);

        $this->actingAs($designer)->get(route('admin.print-templates.index'))->assertOk();
        $this->actingAs($designer)->get(route('admin.print-templates.edit', $template))->assertOk();

        // The preview renders with example values, which is what makes the split safe.
        $this->actingAs($designer)
            ->get(route('admin.print-templates.preview', $template))
            ->assertOk()
            ->assertDontSee((string) $certificate->getAttribute('student_name_snapshot'));

        $this->actingAs($designer)->get(route('admin.certificates.index'))->assertForbidden();
        $this->actingAs($designer)->get(route('admin.certificates.show', $certificate))->assertForbidden();
    }

    #[Test]
    public function a_registrar_can_issue_certificates_without_touching_a_template(): void
    {
        // The other half of the split. Somebody issuing certificates all day has no reason to hold
        // the ability that decides what HTML a PDF renderer is handed.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);
        $template = $this->seededTemplate(PrintTemplateType::Certificate);

        $registrar = $this->createUserWithPermissions([
            'certificates.view_any', 'certificates.view', 'certificates.create',
            'certificates.change_status', 'certificates.print',
        ]);

        $this->actingAs($registrar)->get(route('admin.certificates.index'))->assertOk();
        $this->actingAs($registrar)->get(route('admin.certificates.show', $certificate))->assertOk();

        $this->actingAs($registrar)->get(route('admin.print-templates.index'))->assertForbidden();
        $this->actingAs($registrar)->get(route('admin.print-templates.edit', $template))->assertForbidden();
    }

    #[Test]
    public function printing_is_a_separate_grant_from_reading(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $reader = $this->createUserWithPermissions(['certificates.view_any', 'certificates.view']);

        $this->actingAs($reader)->get(route('admin.certificates.show', $certificate))->assertOk();
        $this->actingAs($reader)->get(route('admin.certificates.print', $certificate))->assertForbidden();
        $this->actingAs($reader)->get(route('admin.certificates.pdf', $certificate))->assertForbidden();
    }

    #[Test]
    public function the_scan_log_is_behind_its_own_permission(): void
    {
        // It is a different question from "what does this certificate say": that somebody tried four
        // hundred codes from one address is a fact about the endpoint, not about a student.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        $reader = $this->createUserWithPermissions(['certificates.view_any', 'certificates.view']);
        $auditor = $this->createUserWithPermissions([
            'certificates.view_any', 'certificates.view', 'certificates.view_logs',
        ]);

        $this->actingAs($reader)->get(route('admin.certificates.verifications', $certificate))->assertForbidden();
        $this->actingAs($auditor)->get(route('admin.certificates.verifications', $certificate))->assertOk();
    }

    #[Test]
    public function issuing_against_a_failing_rule_needs_the_approve_permission(): void
    {
        // Issuing and overriding are two decisions, and §4.2 gates them separately.
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $draft = $this->draftCertificate($seat, [], $admin);

        $issuer = $this->createUserWithPermissions([
            'certificates.view_any', 'certificates.view', 'certificates.change_status',
        ]);

        $this->actingAs($issuer)
            ->post(route('admin.certificates.issue', $draft), [
                'override_reason' => 'We decided to let it through.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('certificates', [
            'id' => $draft->getKey(),
            'status' => 'draft',
        ]);
    }

    #[Test]
    public function the_public_verification_page_needs_no_account_at_all(): void
    {
        $admin = $this->createSuperAdmin();
        [, $seat] = $this->batchWithOneStudent($admin);
        $certificate = $this->issuedCertificate($seat, [], $admin);

        // The whole point: an employer holding a printed certificate has no login and needs none.
        $this->get(route('site.verify.index'))->assertOk();
        $this->get(route('site.verify.show', ['code' => $certificate->getAttribute('verification_code')]))->assertOk();
    }

    #[Test]
    public function a_card_cannot_be_deleted_by_anybody_because_the_ability_does_not_exist(): void
    {
        // There is no `student_id_cards.delete` in the registry and no destroy route. Every row is a
        // card that existed in the world; it is marked lost, damaged, replaced or revoked instead.
        $this->assertNotContains(
            'student_id_cards.delete',
            \App\Support\PermissionRegistry::permissionNames(),
            'A delete ability exists for ID cards. The register is a history, not a list.',
        );

        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('admin.student-id-cards.destroy'),
            'A destroy route exists for ID cards.',
        );
    }
}
