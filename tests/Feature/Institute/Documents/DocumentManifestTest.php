<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Documents;

use App\Enums\CertificateStatus;
use App\Enums\IdCardStatus;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Enums\PrintTemplateType;
use App\Support\PermissionRegistry;
use App\Support\PrintTokenRegistry;
use App\Support\Schema\RawSchema;
use App\Support\Sidebar;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The surface Phase 21 adds, asserted against what actually shipped (phase-19-23 §11).
 *
 * The same job D98, D109 and D111 gave this file a reason to exist in Phase 20: a route renamed with
 * a search-and-replace, a sidebar offering links that 404, two modules colliding on a sort order.
 * Here it also carries D126 — an enum that did not fit its own column — and the rule that no
 * document a student's name appears on is ever indexed or cached.
 */
final class DocumentManifestTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_four_tables_exist_with_the_columns_the_contract_names(): void
    {
        foreach (['print_templates', 'certificates', 'certificate_verifications', 'student_id_cards'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "$table is missing.");
        }

        $this->assertTrue(Schema::hasColumns('print_templates', [
            'type', 'code', 'name', 'description', 'branch_id', 'paper_size', 'orientation',
            'width_mm', 'height_mm', 'margin_mm', 'background_image_path', 'logo_path',
            'body_html', 'custom_css', 'tokens_used', 'signatories', 'show_qr', 'qr_size_mm',
            'is_default', 'is_active', 'sort_order', 'preview_path', 'default_guard',
        ]));

        $this->assertTrue(Schema::hasColumns('certificates', [
            'certificate_number', 'verification_code', 'branch_id', 'student_id', 'course_id',
            'batch_id', 'student_batch_enrollment_id', 'teacher_id', 'print_template_id',
            'grade_scale_id', 'student_name_snapshot', 'father_name_snapshot',
            'student_code_snapshot', 'registration_number_snapshot', 'course_name_snapshot',
            'batch_name_snapshot', 'teacher_name_snapshot', 'branch_name_snapshot',
            'course_start_date', 'completion_date', 'grade', 'grade_point', 'percentage',
            'attendance_percentage', 'progress_percentage', 'eligibility_snapshot', 'issued_on',
            'issued_by', 'status', 'revoked_at', 'revoked_by', 'revocation_reason',
            'reissue_of_id', 'reissue_reason', 'qr_payload', 'pdf_path', 'pdf_generated_at',
            'print_count', 'last_printed_at', 'last_printed_by', 'verification_count',
            'last_verified_at', 'is_publicly_verifiable', 'notes',
        ]));

        $this->assertTrue(Schema::hasColumns('student_id_cards', [
            'card_number', 'verification_code', 'branch_id', 'student_id',
            'student_batch_enrollment_id', 'course_id', 'batch_id', 'print_template_id',
            'student_name_snapshot', 'father_name_snapshot', 'student_code_snapshot',
            'registration_number_snapshot', 'course_name_snapshot', 'batch_name_snapshot',
            'joining_date_snapshot', 'guardian_phone_snapshot', 'photo_path', 'issued_on',
            'valid_until', 'status', 'replacement_of_id', 'replacement_reason', 'revoked_at',
            'revoked_by', 'revocation_reason', 'qr_payload', 'pdf_path', 'print_count',
            'last_printed_at', 'last_printed_by', 'notes',
        ]));

        $this->assertTrue(Schema::hasColumns('certificate_verifications', [
            'certificate_id', 'submitted_code', 'result', 'ip_address', 'user_agent', 'device',
            'referer', 'created_at',
        ]));
    }

    /**
     * **CLAUDE.md §3 and D19.** `certificate_verifications` is an append-only log, so it carries no
     * `deleted_at`: a nullable one on an immutable record lets a single `->delete()` hide a row from
     * every aggregate the rate limiter and the office depend on.
     */
    #[Test]
    public function the_append_only_log_has_no_soft_delete_column(): void
    {
        $this->assertFalse(Schema::hasColumn('certificate_verifications', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('certificate_verifications', 'updated_at'));

        // The three mutable registers keep theirs.
        foreach (['print_templates', 'certificates', 'student_id_cards'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'), "$table lost its deleted_at.");
        }
    }

    /** D126: an enum that does not fit its own column makes one case unreachable. */
    #[Test]
    public function every_enum_fits_the_column_it_is_stored_in(): void
    {
        $pairs = [
            ['print_templates', 'type', PrintTemplateType::class],
            ['print_templates', 'paper_size', PaperSize::class],
            ['print_templates', 'orientation', PageOrientation::class],
            ['certificates', 'status', CertificateStatus::class],
            ['student_id_cards', 'status', IdCardStatus::class],
            ['certificate_verifications', 'result', \App\Enums\VerificationResult::class],
        ];

        foreach ($pairs as [$table, $column, $enum]) {
            $width = (int) DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            )?->len;

            $this->assertSame(32, $width, "$table.$column is not string(32).");

            foreach ($enum::cases() as $case) {
                $this->assertLessThanOrEqual(
                    $width,
                    mb_strlen($case->value),
                    sprintf('%s.%s is varchar(%d) and cannot hold "%s".', $table, $column, $width, $case->value),
                );
            }
        }
    }

    #[Test]
    public function the_guards_and_checks_the_contract_names_are_present(): void
    {
        foreach ([
            ['print_templates', 'uq_pt_code'],
            ['print_templates', 'uq_pt_default'],
            ['certificates', 'uq_ce_number'],
            ['certificates', 'uq_ce_code'],
            ['certificates', 'uq_ce_reissue'],
            ['student_id_cards', 'uq_sic_number'],
            ['student_id_cards', 'uq_sic_code'],
            ['student_id_cards', 'uq_sic_replacement'],
            ['student_id_cards', 'uq_sic_live'],
        ] as [$table, $index]) {
            $this->assertTrue(RawSchema::indexExists($table, $index, true), "$table.$index is missing.");
        }

        foreach (['chk_pt_custom', 'chk_pt_dims', 'chk_pt_qr'] as $check) {
            $this->assertTrue(RawSchema::checkExists($check), "$check is missing.");
        }
    }

    /**
     * MariaDB permits unlimited NULLs in a unique index, which is the whole mechanism: the guard
     * column is 1 for the one row that must be unique and NULL for every other.
     */
    #[Test]
    public function the_generated_guard_columns_are_stored_and_nullable(): void
    {
        foreach ([['print_templates', 'default_guard'], ['certificates', 'live_guard'], ['student_id_cards', 'live_guard']] as [$table, $column]) {
            $meta = DB::selectOne(
                'SELECT IS_NULLABLE AS nullable, EXTRA AS extra FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );

            $this->assertNotNull($meta, "$table.$column is missing.");
            $this->assertSame('YES', $meta->nullable, "$table.$column is not nullable.");
            $this->assertStringContainsString('STORED', (string) $meta->extra, "$table.$column is not STORED.");
        }
    }

    /** CLAUDE.md §3: a percentage is decimal(8,4). There is no reported-percentage exception. */
    #[Test]
    public function the_numeric_columns_carry_the_right_precision(): void
    {
        $expected = [
            ['certificates', 'percentage', 8, 4],
            ['certificates', 'attendance_percentage', 8, 4],
            ['certificates', 'progress_percentage', 8, 4],
            ['print_templates', 'margin_mm', 5, 2],
            ['print_templates', 'qr_size_mm', 5, 2],
            ['print_templates', 'width_mm', 6, 2],
            ['print_templates', 'height_mm', 6, 2],
        ];

        foreach ($expected as [$table, $column, $precision, $scale]) {
            $meta = DB::selectOne(
                'SELECT NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );

            $this->assertSame($precision, (int) $meta?->p, "$table.$column precision");
            $this->assertSame($scale, (int) $meta?->s, "$table.$column scale");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_three_modules_are_declared_with_the_abilities_the_contract_names(): void
    {
        $modules = PermissionRegistry::modules();

        foreach (['certificates', 'student_id_cards', 'print_templates'] as $slug) {
            $this->assertArrayHasKey($slug, $modules, "$slug is not a declared module.");
        }

        $abilities = static fn (string $slug): array => array_map(
            static fn ($ability): string => $ability->value,
            $modules[$slug]['abilities'],
        );

        foreach (['view_any', 'view', 'create', 'edit', 'delete', 'approve', 'change_status', 'print', 'export', 'view_logs'] as $ability) {
            $this->assertContains($ability, $abilities('certificates'), "certificates.$ability is missing.");
        }

        // A card is never deleted, so the ability that would delete one does not exist.
        $this->assertNotContains('delete', $abilities('student_id_cards'));
        $this->assertNotContains('restore', $abilities('student_id_cards'));

        // A used template is retired rather than deleted, so there is nothing to bring back.
        $this->assertNotContains('restore', $abilities('print_templates'));
        $this->assertContains('print', $abilities('print_templates'));
    }

    /** D111: two modules on one sort order is two sidebar items in an arbitrary order. */
    #[Test]
    public function no_two_modules_share_a_sort_order(): void
    {
        $sorts = [];

        foreach (PermissionRegistry::modules() as $slug => $definition) {
            $sort = (int) $definition['sort'];

            $this->assertArrayNotHasKey(
                $sort,
                $sorts,
                sprintf('%s and %s both sort at %d.', $slug, $sorts[$sort] ?? '?', $sort),
            );

            $sorts[$sort] = $slug;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_route_the_phase_declares_exists_and_carries_its_permission(): void
    {
        $expected = [
            'admin.print-templates.index' => 'print_templates.view_any',
            'admin.print-templates.create' => 'print_templates.create',
            'admin.print-templates.store' => 'print_templates.create',
            'admin.print-templates.show' => 'print_templates.view',
            'admin.print-templates.edit' => 'print_templates.edit',
            'admin.print-templates.update' => 'print_templates.edit',
            // The preview renders example values and no student data, so the contract makes it
            // part of *reading* a template rather than printing a document.
            'admin.print-templates.preview' => 'print_templates.view',
            'admin.print-templates.tokens' => 'print_templates.view',
            'admin.print-templates.duplicate' => 'print_templates.create',
            'admin.print-templates.default' => 'print_templates.change_status',
            'admin.print-templates.deactivate' => 'print_templates.change_status',
            'admin.print-templates.destroy' => 'print_templates.delete',

            'admin.certificates.index' => 'certificates.view_any',
            'admin.certificates.eligible' => 'certificates.create',
            'admin.certificates.eligibility' => 'certificates.create',
            'admin.certificates.create' => 'certificates.create',
            'admin.certificates.store' => 'certificates.create',
            'admin.certificates.update' => 'certificates.edit',
            'admin.certificates.bulk-issue' => 'certificates.change_status',
            'admin.certificates.pdf.regenerate' => 'certificates.edit',
            'admin.certificates.show' => 'certificates.view',
            'admin.certificates.issue' => 'certificates.change_status',
            'admin.certificates.revoke' => 'certificates.change_status',
            'admin.certificates.reissue' => 'certificates.create',
            'admin.certificates.print' => 'certificates.print',
            'admin.certificates.pdf' => 'certificates.print',
            'admin.certificates.verifications' => 'certificates.view_logs',
            'admin.certificates.export' => 'certificates.export',
            'admin.certificates.destroy' => 'certificates.delete',

            'admin.student-id-cards.index' => 'student_id_cards.view_any',
            'admin.student-id-cards.create' => 'student_id_cards.create',
            'admin.student-id-cards.store' => 'student_id_cards.create',
            'admin.student-id-cards.show' => 'student_id_cards.view',
            'admin.student-id-cards.status' => 'student_id_cards.change_status',
            'admin.student-id-cards.replace' => 'student_id_cards.create',
            'admin.student-id-cards.print' => 'student_id_cards.print',
            'admin.student-id-cards.batch-print' => 'student_id_cards.print',
            'admin.student-id-cards.bulk-issue' => 'student_id_cards.create',
            'admin.student-id-cards.pdf' => 'student_id_cards.print',
            'admin.student-id-cards.export' => 'student_id_cards.export',

            // §7.9 and §7.10. A student reads their own documents; a teacher reads which of
            // their own students could be certified. Nothing on either panel writes anything.
            'student.certificates.index' => 'student_portal.certificates',
            'student.certificates.pdf' => 'student_portal.certificates',
            'student.id-card.show' => 'student_portal.id_card',
            'student.id-card.pdf' => 'student_portal.id_card',
            'teacher.certificates.candidates' => 'teacher_portal.certificates',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route $name does not exist.");
            $this->assertContains(
                "can:$permission",
                $route->gatherMiddleware(),
                "Route $name is not behind can:$permission.",
            );
        }
    }

    /** The public verification routes are the one part of this phase behind no permission at all. */
    #[Test]
    public function the_verification_routes_are_public_and_outside_the_admin_panel(): void
    {
        foreach (['site.verify.index', 'site.verify.submit', 'site.verify.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route $name does not exist.");

            $middleware = $route->gatherMiddleware();

            $this->assertNotContains('auth', $middleware, "$name requires a login. An employer has none.");

            foreach ($middleware as $item) {
                $this->assertStringNotContainsString('can:', (string) $item, "$name is behind a permission.");
            }
        }
    }

    #[Test]
    public function nothing_routes_to_deleting_a_card(): void
    {
        $this->assertFalse(Route::has('admin.student-id-cards.destroy'));
    }

    /*
    |--------------------------------------------------------------------------
    | Views and the sidebar
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_view_the_controllers_name_exists(): void
    {
        // D109 in one assertion: a screen that was renamed and a controller that was not is a 500
        // nobody sees until they open the page.
        $views = [
            'layouts.document',
            'admin.print-templates.index', 'admin.print-templates.create', 'admin.print-templates.edit',
            'admin.print-templates.show', 'admin.print-templates.preview', 'admin.print-templates._form',
            'admin.print-templates.tokens',
            'admin.certificates.index', 'admin.certificates.eligible', 'admin.certificates.show',
            'admin.certificates.create', 'admin.certificates.eligibility', 'admin.certificates._eligibility',
            'admin.certificates.print', 'admin.certificates.verifications',
            'admin.student-id-cards.index', 'admin.student-id-cards.create',
            'admin.student-id-cards.show', 'admin.student-id-cards.print',
            'student.certificates.index', 'student.id-card.show',
            'teacher.certificates.candidates',
            'site.verify.show',
        ];

        foreach ($views as $view) {
            $this->assertTrue(View::exists($view), "View $view is missing.");
        }
    }

    #[Test]
    public function the_sidebar_offers_the_phase_and_every_link_resolves(): void
    {
        $found = [];

        $walk = static function (array $items) use (&$walk, &$found): void {
            foreach ($items as $item) {
                if (isset($item['module'])) {
                    $found[$item['module']] = $item['route'] ?? null;
                }

                foreach (['items', 'children'] as $key) {
                    if (is_array($item[$key] ?? null)) {
                        $walk($item[$key]);
                    }
                }
            }
        };

        $walk(Sidebar::tree(\App\Enums\PanelType::Admin));

        foreach (['certificates', 'student_id_cards', 'print_templates'] as $module) {
            $this->assertArrayHasKey($module, $found, "The sidebar offers no $module entry.");
            $this->assertTrue(
                Route::has((string) $found[$module]),
                sprintf('The sidebar links %s to %s, which does not exist.', $module, (string) $found[$module]),
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Settings, enums and tokens
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_institute_settings_the_phase_adds_are_declared_with_their_defaults(): void
    {
        $fields = SettingsRegistry::fields('institute');

        foreach ([
            'certificate_prefix', 'certificate_next_number', 'certificate_footer_note',
            'certificate_verification_reveals', 'certificate_verification_rate_limit_per_minute',
            'id_card_prefix', 'id_card_next_number', 'id_card_validity_months',
            'id_card_require_photo', 'id_card_batch_print_max',
        ] as $key) {
            $this->assertArrayHasKey($key, $fields, "institute.$key is not declared.");
        }

        // INV-21-3: narrow by default. A verification page confirms a document; it does not publish
        // a student record.
        $this->assertSame(
            ['student_name', 'course_name', 'completion_date', 'grade'],
            $fields['certificate_verification_reveals']['default'],
        );
    }

    /**
     * No phone, email, CNIC, address, guardian, fee or collaborator field is even *offered* as
     * something the public page could reveal. Widening the setting cannot reach them.
     */
    #[Test]
    public function the_public_reveal_list_cannot_be_widened_to_anything_sensitive(): void
    {
        $options = array_keys(SettingsRegistry::fields('institute')['certificate_verification_reveals']['options']);

        foreach (['phone', 'email', 'cnic', 'address', 'guardian', 'fee', 'collaborator', 'notes'] as $forbidden) {
            foreach ($options as $option) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $option,
                    sprintf('The public verification page offers to reveal "%s".', $option),
                );
            }
        }
    }

    #[Test]
    public function every_enum_case_has_a_label_and_a_colour(): void
    {
        foreach ([CertificateStatus::class, IdCardStatus::class, PrintTemplateType::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label(), $enum.'::'.$case->name.' has no label.');
                $this->assertNotSame('', $case->color(), $enum.'::'.$case->name.' has no colour.');
            }
        }
    }

    /**
     * §3.3 names three certificate statuses and no more. A fourth would let one row claim two
     * histories and make "which certificate does this QR code resolve to?" ambiguous.
     */
    #[Test]
    public function a_certificate_has_exactly_three_statuses(): void
    {
        $this->assertCount(3, CertificateStatus::cases());

        $public = array_values(array_filter(CertificateStatus::cases(), static fn (CertificateStatus $s): bool => $s->isPublic()));

        // A draft has no number and no QR payload, so it is the one that cannot be printed.
        $this->assertSame([CertificateStatus::Issued, CertificateStatus::Revoked], $public);
    }

    #[Test]
    public function every_token_the_registry_declares_has_a_label_an_example_and_a_group(): void
    {
        foreach (PrintTemplateType::cases() as $type) {
            foreach (PrintTokenRegistry::tokens($type) as $token => $spec) {
                $this->assertNotSame('', $spec['label'], "$token has no label.");
                $this->assertNotSame('', $spec['example'], "$token has no example.");
                $this->assertArrayHasKey(
                    $spec['group'],
                    PrintTokenRegistry::GROUPS,
                    sprintf('%s is in group "%s", which the editor does not show.', $token, $spec['group']),
                );
            }
        }
    }

    /** A result card carries no QR code, so it declares none — and the enum agrees. */
    #[Test]
    public function only_the_documents_that_carry_a_qr_code_declare_one(): void
    {
        foreach (PrintTemplateType::cases() as $type) {
            $this->assertSame(
                $type->supportsQr(),
                array_key_exists('qr', PrintTokenRegistry::tokens($type)),
                sprintf('%s disagrees with its own token list about carrying a QR code.', $type->value),
            );
        }
    }

    /** CR80 is the card standard: 85.60 × 53.98 mm, and dompdf takes points. */
    #[Test]
    public function the_card_paper_is_the_size_a_card_printer_expects(): void
    {
        $this->assertSame(85.60, PaperSize::Cr80->widthMm());
        $this->assertSame(53.98, PaperSize::Cr80->heightMm());

        $this->assertSame(
            [0.0, 0.0, 242.65, 153.01],
            PaperSize::points((float) PaperSize::Cr80->widthMm(), (float) PaperSize::Cr80->heightMm()),
        );
    }
}
