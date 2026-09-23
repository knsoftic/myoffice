<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Materials;

use App\DataObjects\Files\FileRules;
use App\Enums\AssignmentStatus;
use App\Enums\CourseResourceType;
use App\Enums\MaterialAccessAction;
use App\Enums\MaterialStatus;
use App\Enums\MaterialTargetType;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Support\PermissionRegistry;
use App\Support\Schema\RawSchema;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The surface this phase adds, asserted against what actually shipped (phase-19-23 §11).
 *
 * **This file exists because of D98 and D109.** Phase 17 renamed a route with a search-and-replace and
 * silently took Phase 7's with it; Phase 18 shipped a sidebar offering two links that 404. Both were
 * found by asking whether the manifest and the application still agreed — so the asking is now a test.
 */
final class MaterialManifestTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function this_phase_creates_exactly_its_six_tables(): void
    {
        foreach ([
            'course_materials',
            'course_material_targets',
            'course_material_downloads',
            'assignments',
            'assignment_submissions',
            'assignment_submission_files',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' is missing.');
        }
    }

    /**
     * The three generated columns are the phase's load-bearing guards. A migration that quietly
     * skipped one would leave a unique index that does not bite, which is worse than none because
     * nobody would look for it.
     */
    #[Test]
    public function the_three_generated_guards_exist_and_the_indexes_that_read_them_do_too(): void
    {
        $this->assertTrue(Schema::hasColumn('course_material_targets', 'target_key'));
        $this->assertTrue(Schema::hasColumn('assignment_submissions', 'current_guard'));
        $this->assertTrue(Schema::hasColumn('assignment_submissions', 'final_marks'));

        $this->assertTrue(RawSchema::indexExists('course_material_targets', 'uq_cmt', true));
        $this->assertTrue(RawSchema::indexExists('assignment_submissions', 'uq_as_live', true));
        $this->assertTrue(RawSchema::indexExists('assignment_submissions', 'uq_as_attempt', true));
        $this->assertTrue(RawSchema::indexExists('assignment_submissions', 'uq_as_superseded', true));
    }

    #[Test]
    public function every_check_the_contract_names_is_on_the_table(): void
    {
        foreach ([
            'chk_cm_payload', 'chk_cm_window', 'chk_cm_size', 'chk_cm_counts',
            'chk_cmt_one',
            'chk_cmd_action',
            'chk_as_total', 'chk_as_pass', 'chk_as_cutoff', 'chk_as_penalty', 'chk_as_attempts', 'chk_as_counts',
            'chk_asub_marks', 'chk_asub_penalty', 'chk_asub_total', 'chk_asub_graded',
            'chk_asub_submitted', 'chk_asub_pct', 'chk_asub_attempt',
            'chk_asf_size',
        ] as $check) {
            $this->assertTrue(RawSchema::checkExists($check), $check.' is missing.');
        }
    }

    /**
     * §3 and D19: the append-only tables carry **no `deleted_at`**. A nullable one on a log lets a
     * single `->delete()` hide a row from every aggregate.
     */
    #[Test]
    public function the_append_only_tables_have_no_soft_delete_column(): void
    {
        foreach (['course_material_targets', 'course_material_downloads', 'assignment_submission_files'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'deleted_at'),
                $table.' is append-only and must never gain a deleted_at.',
            );
        }
    }

    /** Every `*_percentage` and `*_rate` is `decimal(8,4)` (`CLAUDE.md` §3 — no exceptions). */
    #[Test]
    public function every_percentage_column_is_decimal_eight_four(): void
    {
        $columns = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('course_materials', 'assignments', 'assignment_submissions')
                AND (COLUMN_NAME LIKE '%_percentage' OR COLUMN_NAME LIKE '%_rate')"
        );

        $this->assertNotEmpty($columns, 'The query itself must find something, or it is proving nothing.');

        foreach ($columns as $column) {
            $this->assertSame(
                'decimal(8,4)',
                $column->COLUMN_TYPE,
                $column->TABLE_NAME.'.'.$column->COLUMN_NAME.' is a percentage and must be decimal(8,4).',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_new_module_is_registered_with_the_abilities_the_contract_names(): void
    {
        $modules = PermissionRegistry::modules();

        $this->assertArrayHasKey('assignment_submissions', $modules);

        $abilities = collect($modules['assignment_submissions']['abilities'])
            ->map(static fn ($ability): string => $ability->value)
            ->all();

        foreach (['view_any', 'view', 'create', 'edit', 'download', 'change_status', 'view_reports', 'export', 'view_logs'] as $ability) {
            $this->assertContains($ability, $abilities, 'assignment_submissions.'.$ability.' is missing.');
        }

        // §4.1 and §2.7. An ability that is never registered cannot be granted by mistake.
        $this->assertNotContains('delete', $abilities, 'Marked work is never deleted, so the ability does not exist.');
        $this->assertNotContains('restore', $abilities, 'Nothing is deleted, so nothing is restored.');
    }

    #[Test]
    public function the_targeting_and_reporting_abilities_were_added_to_the_existing_modules(): void
    {
        $modules = PermissionRegistry::modules();

        $materials = collect($modules['course_materials']['abilities'])->map(static fn ($a): string => $a->value)->all();

        foreach (['assign', 'change_status', 'upload', 'download', 'view_reports', 'export', 'view_logs'] as $ability) {
            $this->assertContains($ability, $materials, 'course_materials.'.$ability.' is missing.');
        }

        $assignments = collect($modules['assignments']['abilities'])->map(static fn ($a): string => $a->value)->all();

        foreach (['print', 'view_reports', 'view_logs'] as $ability) {
            $this->assertContains($ability, $assignments, 'assignments.'.$ability.' is missing.');
        }
    }

    /** The collision that cost Phase 18 two failing tests (D111). */
    #[Test]
    public function the_new_module_does_not_collide_with_an_existing_sort_order(): void
    {
        $sorts = array_map(
            static fn (array $definition): int => (int) $definition['sort'],
            PermissionRegistry::modules(),
        );

        $this->assertSame(count($sorts), count(array_unique($sorts)), 'Two modules share a sort order.');
    }

    #[Test]
    public function the_student_download_permission_is_separate_from_the_library(): void
    {
        $abilities = PermissionRegistry::modules()['student_portal']['abilities'];

        $this->assertContains('materials', $abilities);
        $this->assertContains('material_download', $abilities, '§4.3: the list and the files are separately grantable.');
    }

    #[Test]
    public function the_new_module_declares_its_dependency(): void
    {
        $this->assertSame(
            ['assignments'],
            PermissionRegistry::modules()['assignment_submissions']['depends_on'] ?? null,
            'Marking cannot be switched on while the module that sets the work is off.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_route_the_contract_names_exists(): void
    {
        foreach ([
            'admin.course-materials.index', 'admin.course-materials.create', 'admin.course-materials.store',
            'admin.course-materials.show', 'admin.course-materials.edit', 'admin.course-materials.update',
            'admin.course-materials.file.replace', 'admin.course-materials.download',
            'admin.course-materials.targets.store', 'admin.course-materials.targets.destroy',
            'admin.course-materials.status', 'admin.course-materials.destroy',
            'admin.course-materials.engagement', 'admin.course-materials.export',

            'admin.assignments.index', 'admin.assignments.create', 'admin.assignments.store',
            'admin.assignments.show', 'admin.assignments.edit', 'admin.assignments.update',
            'admin.assignments.status', 'admin.assignments.duplicate', 'admin.assignments.brief.download',
            'admin.assignments.print', 'admin.assignments.destroy',

            'admin.assignment-submissions.index', 'admin.assignment-submissions.show',
            'admin.assignment-submissions.grade', 'admin.assignment-submissions.return',
            'admin.assignment-submissions.amend', 'admin.assignment-submissions.grade-bulk',
            'admin.assignment-submissions.release', 'admin.assignment-submissions.mark-missed',
            'admin.assignment-submissions.store', 'admin.assignment-submissions.file.download',
            'admin.assignment-submissions.feedback.download', 'admin.assignment-submissions.export',

            'student.materials.index', 'student.materials.show', 'student.materials.download', 'student.materials.open',
            'student.assignments.index', 'student.assignments.show', 'student.assignments.brief',
            'student.assignments.submission.start', 'student.submissions.submit', 'student.submissions.resubmit',
            'student.submissions.withdraw', 'student.submissions.file', 'student.submissions.feedback',

            'teacher.materials.index', 'teacher.materials.create', 'teacher.materials.store',
            'teacher.materials.update', 'teacher.materials.targets', 'teacher.materials.status',
            'teacher.materials.download',
            'teacher.assignments.index', 'teacher.assignments.create', 'teacher.assignments.store',
            'teacher.assignments.update', 'teacher.assignments.status',
            'teacher.submissions.index', 'teacher.submissions.show', 'teacher.submissions.grade',
            'teacher.submissions.grade-bulk', 'teacher.submissions.release', 'teacher.submissions.file',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name.' does not exist.');
        }
    }

    /**
     * **D98's lesson.** Phase 17 renamed routes with a search-and-replace and rewrote Phase 7's by
     * accident. Nothing this phase added may have taken a name another phase already answers to.
     */
    #[Test]
    public function no_route_of_this_phase_points_at_another_phases_controller(): void
    {
        foreach ([
            'admin.course-materials.index' => 'Admin\\Institute\\CourseMaterialController',
            'admin.assignments.index' => 'Admin\\Institute\\AssignmentController',
            'admin.assignment-submissions.show' => 'Admin\\Institute\\AssignmentSubmissionController',
            'student.materials.index' => 'Student\\MaterialController',
            'student.assignments.index' => 'Student\\AssignmentController',
            'teacher.materials.index' => 'Teacher\\MaterialController',
            'teacher.assignments.index' => 'Teacher\\AssignmentController',
            'teacher.submissions.index' => 'Teacher\\SubmissionController',
        ] as $name => $controller) {
            $action = (string) (Route::getRoutes()->getByName($name)?->getActionName() ?? '');

            $this->assertStringContainsString($controller, $action, $name.' has been repointed.');
        }
    }

    /** Every `can:` on a Phase 19 route has to name a permission that actually exists. */
    #[Test]
    public function every_permission_a_route_demands_is_registered(): void
    {
        $registered = collect(PermissionRegistry::permissions())->pluck('name')->all();
        $missing = [];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! str_contains($name, 'material') && ! str_contains($name, 'assignment') && ! str_contains($name, 'submission')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $arguments = explode(',', substr($middleware, 4));

                // `can:ability,model` is a policy check against a bound model — the first part is an
                // ability name, not a permission, so there is nothing in the registry to look up.
                // Only the single-argument form names a permission.
                if (count($arguments) > 1) {
                    continue;
                }

                if (! in_array($arguments[0], $registered, true)) {
                    $missing[] = $name.' => '.$arguments[0];
                }
            }
        }

        $this->assertSame([], $missing, "These routes demand a permission nobody can hold:\n".implode("\n", $missing));
    }

    /** There is no destroy route for a submission, on any panel (§2.7). */
    #[Test]
    public function nothing_anywhere_offers_to_delete_a_submission(): void
    {
        $offenders = collect(Route::getRoutes()->getRoutesByName())
            ->keys()
            ->filter(static fn (string $name): bool => str_contains($name, 'submission') && str_contains($name, 'destroy'))
            ->reject(static fn (string $name): bool => $name === 'student.submissions.withdraw')
            ->all();

        $this->assertSame([], $offenders);
    }

    /*
    |--------------------------------------------------------------------------
    | Screens
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_view_this_phase_renders_exists(): void
    {
        foreach ([
            'admin.course-materials.index', 'admin.course-materials.create', 'admin.course-materials.edit',
            'admin.course-materials.show', 'admin.course-materials.engagement',
            'admin.assignments.index', 'admin.assignments.create', 'admin.assignments.edit',
            'admin.assignments.show', 'admin.assignments.print',
            'admin.assignment-submissions.index', 'admin.assignment-submissions.show',
            'student.materials.index', 'student.materials.show',
            'student.assignments.index', 'student.assignments.show',
            'teacher.materials.index', 'teacher.materials.create',
            'teacher.assignments.index', 'teacher.assignments.create',
            'teacher.submissions.index', 'teacher.submissions.show',
        ] as $view) {
            $this->assertTrue(view()->exists($view), $view.' is missing.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Settings and enums
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_setting_the_contract_names_is_registered(): void
    {
        $fields = SettingsRegistry::fields('institute');

        foreach ([
            'material_max_upload_mb', 'material_allowed_types', 'material_extra_extensions',
            'material_visible_after_batch_end_days', 'material_download_log_retention_days',
            'material_notify_on_publish', 'material_block_on_outstanding_fee',
            'assignment_submission_max_mb', 'assignment_max_files_default', 'assignment_max_attempts_default',
            'assignment_late_submission_default', 'assignment_late_penalty_default_percentage',
            'assignment_deadline_reminder_hours', 'assignment_auto_close_on_deadline',
            'assignment_release_marks_immediately',
        ] as $key) {
            $this->assertArrayHasKey($key, $fields, 'institute.'.$key.' is missing.');
        }
    }

    /**
     * **D114.** `numeric` accepts exponent notation; `decimal:0,N` is what refuses it. Every decimal
     * setting this phase adds carries the rule that actually works.
     */
    #[Test]
    public function every_decimal_setting_this_phase_adds_refuses_exponent_notation(): void
    {
        $rules = SettingsRegistry::fields('institute')['assignment_late_penalty_default_percentage']['rules'];

        $this->assertContains('decimal:0,4', $rules, 'Without it, 1E1 validates and stores.');
    }

    /**
     * **D113.** A multiselect default declared in a different order from its options makes an
     * untouched save look like an edit.
     */
    #[Test]
    public function the_multiselect_default_follows_the_enums_own_case_order(): void
    {
        $default = SettingsRegistry::fields('institute')['material_allowed_types']['default'];

        $this->assertSame(
            array_map(static fn (CourseResourceType $t): string => $t->value, CourseResourceType::cases()),
            $default,
            'The checkboxes render in case order, so the default must be written in it.',
        );
    }

    /** §5.1 defaults every kind to on, so every kind must actually be uploadable (D116). */
    #[Test]
    public function every_material_kind_that_carries_a_file_can_actually_be_uploaded(): void
    {
        foreach (CourseResourceType::cases() as $type) {
            $offered = FileRules::courseMaterial($type)->allowedExtensions();

            if (! $type->isFile()) {
                $this->assertSame([], $offered, 'A link carries no file, so it offers no extension.');

                continue;
            }

            $this->assertNotEmpty(
                $offered,
                sprintf('%s is on by default but offers no extension at all — it cannot be uploaded.', $type->value),
            );
        }
    }

    #[Test]
    public function every_enum_this_phase_adds_answers_the_questions_its_screens_ask(): void
    {
        foreach ([MaterialStatus::class, MaterialTargetType::class, MaterialAccessAction::class,
            AssignmentStatus::class, SubmissionType::class, SubmissionStatus::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label(), $enum.'::'.$case->name.' has no label.');
                $this->assertNotSame('', $case->color(), $enum.'::'.$case->name.' has no colour.');
            }
        }
    }

    /** INV-19-5's mechanism, stated as a property rather than assumed from the migration. */
    #[Test]
    public function exactly_one_submission_status_is_not_live(): void
    {
        $notLive = array_filter(SubmissionStatus::cases(), static fn (SubmissionStatus $s): bool => ! $s->isLive());

        $this->assertSame([SubmissionStatus::Superseded], array_values($notLive));
    }

    /** `closed` keeps an assignment visible — that is the whole reason it is not `archived`. */
    #[Test]
    public function a_closed_assignment_is_visible_and_an_archived_one_is_not(): void
    {
        $this->assertTrue(AssignmentStatus::Closed->isVisibleToStudents());
        $this->assertFalse(AssignmentStatus::Closed->acceptsSubmissions());
        $this->assertFalse(AssignmentStatus::Archived->isVisibleToStudents());
    }

    /** §2.5: a link open counts, or every link material looks untouched. */
    #[Test]
    public function a_link_open_counts_towards_the_download_total(): void
    {
        $this->assertTrue(MaterialAccessAction::LinkOpen->countsAsDownload());
        $this->assertTrue(MaterialAccessAction::Download->countsAsDownload());
        $this->assertFalse(MaterialAccessAction::View->countsAsDownload());
    }
}
