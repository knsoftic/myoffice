<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Enums\ExamAttendanceStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Enums\VerificationResult;
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
 * **This file exists because of D98, D109 and D111.** A route renamed with a search-and-replace took
 * another phase's with it; a sidebar shipped offering two links that 404; two modules collided on one
 * sort order. All three were found by asking whether the manifest and the application still agreed.
 */
final class ResultManifestTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_three_tables_exist_with_the_columns_the_contract_names(): void
    {
        $this->assertTrue(Schema::hasTable('grade_scales'));
        $this->assertTrue(Schema::hasTable('grade_scale_bands'));
        $this->assertTrue(Schema::hasTable('exams'));
        $this->assertTrue(Schema::hasTable('exam_results'));

        $this->assertTrue(Schema::hasColumns('exams', [
            'branch_id', 'course_id', 'batch_id', 'exam_type', 'name', 'course_topic_id',
            'teacher_id', 'classroom_id', 'delivery_mode', 'meeting_url', 'scheduled_date',
            'start_time', 'end_time', 'duration_minutes', 'total_marks', 'passing_marks',
            'weight_percentage', 'grade_scale_id', 'status', 'results_published_at',
            'results_published_by', 'results_verified_at', 'results_verified_by',
            'expected_count', 'results_entered_count', 'appeared_count', 'absent_count',
            'passed_count', 'failed_count', 'highest_marks', 'lowest_marks', 'average_marks',
            'average_percentage', 'cancellation_reason', 'active_guard',
        ]));

        $this->assertTrue(Schema::hasColumns('exam_results', [
            'exam_id', 'student_id', 'student_batch_enrollment_id', 'batch_id', 'course_id',
            'attendance_status', 'obtained_marks', 'total_marks', 'percentage', 'grade_scale_id',
            'grade_scale_band_id', 'grade', 'grade_point', 'is_passed', 'position_in_batch',
            'remarks', 'entered_by', 'entered_at', 'verified_by', 'verified_at', 'published_at',
            'amended_at', 'amended_by', 'amendment_reason',
        ]));
    }

    /**
     * **`exams.status` must be wide enough for its own longest case.** It shipped as varchar(16) and
     * `results_published` is seventeen characters: every status fitted except the one that matters,
     * so an institute could mark a sheet, have it checked and then never publish it. CLAUDE.md §3
     * says string(32) exactly because nobody measures their enum.
     */
    #[Test]
    public function every_enum_fits_the_column_it_is_stored_in(): void
    {
        $pairs = [
            ['exams', 'status', ExamStatus::class],
            ['exams', 'exam_type', ExamType::class],
            ['exam_results', 'attendance_status', ExamAttendanceStatus::class],
        ];

        foreach ($pairs as [$table, $column, $enum]) {
            $width = (int) DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            )?->len;

            foreach ($enum::cases() as $case) {
                $this->assertLessThanOrEqual(
                    $width,
                    mb_strlen($case->value),
                    sprintf('%s.%s is varchar(%d) and cannot hold "%s".', $table, $column, $width, $case->value),
                );
            }
        }
    }

    /** CLAUDE.md §3: a status column is string(32), full stop. */
    #[Test]
    public function the_status_columns_are_thirty_two_wide(): void
    {
        foreach ([['exams', 'status'], ['exam_results', 'attendance_status']] as [$table, $column]) {
            $width = (int) DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            )?->len;

            $this->assertSame(32, $width, "$table.$column is not string(32).");
        }
    }

    #[Test]
    public function the_guards_and_checks_the_contract_names_are_present(): void
    {
        foreach ([
            ['grade_scales', 'uq_gs_code'],
            ['grade_scales', 'uq_gs_default'],
            ['grade_scale_bands', 'uq_gsb_grade'],
            ['exams', 'uq_ex_batch_slot'],
            ['exam_results', 'uq_er_exam_student'],
        ] as [$table, $index]) {
            $this->assertTrue(RawSchema::indexExists($table, $index, true), "$table.$index is missing.");
        }

        foreach ([
            'chk_gs_pass', 'chk_gsb_range', 'chk_ex_total', 'chk_ex_pass', 'chk_ex_times',
            'chk_ex_cancelled', 'chk_er_marks', 'chk_er_appeared', 'chk_er_absent', 'chk_er_amendment',
        ] as $check) {
            $this->assertTrue(RawSchema::checkExists($check), "$check is missing.");
        }
    }

    /** Money is decimal(15,2); marks are decimal(8,2); a percentage is decimal(8,4). CLAUDE.md §3. */
    #[Test]
    public function the_numeric_columns_carry_the_right_precision(): void
    {
        $expected = [
            ['grade_scales', 'pass_percentage', 8, 4],
            ['grade_scale_bands', 'min_percentage', 8, 4],
            ['grade_scale_bands', 'max_percentage', 8, 4],
            ['exams', 'total_marks', 8, 2],
            ['exams', 'passing_marks', 8, 2],
            ['exams', 'weight_percentage', 8, 4],
            ['exams', 'average_percentage', 8, 4],
            ['exam_results', 'obtained_marks', 8, 2],
            ['exam_results', 'total_marks', 8, 2],
            ['exam_results', 'percentage', 8, 4],
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

        foreach (['grade_scales', 'exams', 'results'] as $slug) {
            $this->assertArrayHasKey($slug, $modules, "$slug is not a declared module.");
        }

        $abilities = static fn (string $slug): array => array_map(
            static fn ($ability): string => $ability->value,
            $modules[$slug]['abilities'],
        );

        $this->assertEqualsCanonicalizing(
            ['view_any', 'view', 'create', 'edit', 'delete', 'change_status'],
            $abilities('grade_scales'),
        );

        foreach (['view_any', 'view', 'create', 'edit', 'delete', 'change_status', 'assign', 'restore', 'export', 'view_reports', 'view_logs'] as $ability) {
            $this->assertContains($ability, $abilities('exams'), "exams.$ability is missing.");
        }

        // `delete` and `restore` are absent on purpose: a result is amended, never removed.
        $this->assertNotContains('delete', $abilities('results'));
        $this->assertNotContains('restore', $abilities('results'));

        foreach (['view_any', 'view', 'create', 'edit', 'approve', 'reject', 'change_status', 'print', 'import', 'export', 'view_reports', 'view_logs'] as $ability) {
            $this->assertContains($ability, $abilities('results'), "results.$ability is missing.");
        }
    }

    /** D111: two modules on one sort order is two sidebar items in an arbitrary order. */
    #[Test]
    public function no_two_institute_modules_share_a_sort_order(): void
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

    #[Test]
    public function a_grade_scale_depends_on_nothing_and_says_so(): void
    {
        $modules = PermissionRegistry::modules();

        $this->assertArrayHasKey('depends_on', $modules['grade_scales']);
        $this->assertSame([], $modules['grade_scales']['depends_on']);
        $this->assertSame(['batches'], $modules['exams']['depends_on']);
        $this->assertSame(['exams'], $modules['results']['depends_on']);
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
            'admin.grade-scales.index' => 'grade_scales.view_any',
            'admin.grade-scales.create' => 'grade_scales.create',
            'admin.grade-scales.store' => 'grade_scales.create',
            'admin.grade-scales.show' => 'grade_scales.view',
            'admin.grade-scales.edit' => 'grade_scales.edit',
            'admin.grade-scales.update' => 'grade_scales.edit',
            'admin.grade-scales.default' => 'grade_scales.change_status',
            'admin.grade-scales.deactivate' => 'grade_scales.change_status',
            'admin.grade-scales.destroy' => 'grade_scales.delete',

            'admin.exams.index' => 'exams.view_any',
            'admin.exams.create' => 'exams.create',
            'admin.exams.store' => 'exams.create',
            'admin.exams.show' => 'exams.view',
            'admin.exams.edit' => 'exams.edit',
            'admin.exams.update' => 'exams.edit',
            'admin.exams.status' => 'exams.change_status',
            'admin.exams.reschedule' => 'exams.change_status',
            'admin.exams.destroy' => 'exams.delete',
            'admin.exams.export' => 'exams.export',

            'admin.results.index' => 'results.view_any',
            'admin.exam-results.sheet' => 'results.create',
            'admin.exam-results.save' => 'results.create',
            'admin.exam-results.verify' => 'results.approve',
            'admin.exam-results.publish' => 'results.change_status',
            'admin.exam-results.unpublish' => 'results.change_status',
            'admin.exam-results.amend' => 'results.edit',
            'admin.exam-results.export' => 'results.export',
            'admin.result-cards.index' => 'results.view_reports',
            'admin.result-cards.show' => 'results.print',

            'student.exams.index' => 'student_portal.exams',
            'student.exams.show' => 'student_portal.exams',
            'student.results.index' => 'student_portal.results',
            'student.results.show' => 'student_portal.results',
            'student.results.card' => 'student_portal.results',

            'teacher.exams.index' => 'teacher_portal.exams',
            'teacher.exams.show' => 'teacher_portal.exams',
            'teacher.results.index' => 'teacher_portal.results',
            'teacher.results.sheet' => 'teacher_portal.results_entry',
            'teacher.results.save' => 'teacher_portal.results_entry',
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

    /** There is no destroy route for a result, and there never will be. */
    #[Test]
    public function nothing_routes_to_deleting_a_result(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => (string) $route->getName())
            ->filter(static fn (string $name): bool => str_contains($name, 'exam-results') || str_contains($name, 'results.'))
            ->values()
            ->all();

        foreach ($names as $name) {
            $this->assertStringNotContainsString('destroy', $name, "$name deletes a result.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Settings and enums
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_institute_settings_the_phase_adds_are_declared_with_their_defaults(): void
    {
        $fields = SettingsRegistry::fields('institute');

        $expected = [
            'default_grade_scale_id' => null,
            'exam_default_passing_percentage' => '40.0000',
            'result_publish_requires_verification' => true,
            'result_card_show_position' => true,
            'result_card_show_attendance' => true,
            'result_card_show_all_exams' => true,
            'progress_from_assessment' => false,
        ];

        foreach ($expected as $key => $default) {
            $this->assertArrayHasKey($key, $fields, "institute.$key is not declared.");
            $this->assertSame($default, $fields[$key]['default'], "institute.$key has drifted from its default.");
        }
    }

    #[Test]
    public function every_enum_case_has_a_label_and_a_colour(): void
    {
        foreach ([ExamStatus::class, ExamType::class, ExamAttendanceStatus::class, VerificationResult::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label(), $enum.'::'.$case->name.' has no label.');
                $this->assertNotSame('', $case->color(), $enum.'::'.$case->name.' has no colour.');
            }
        }
    }

    /**
     * §3.2 names **one** status in which a student may see a mark. Adding a case here has to be a
     * decision somebody takes on purpose, not something that happens because a range widened.
     */
    #[Test]
    public function exactly_one_status_makes_a_result_visible_to_a_student(): void
    {
        $visible = array_filter(ExamStatus::cases(), static fn (ExamStatus $s): bool => $s->resultsVisible());

        $this->assertCount(1, $visible);
        $this->assertSame(ExamStatus::ResultsPublished, array_values($visible)[0]);
    }

    /** The sheet opens for exactly two statuses: the exam happened, and it is being marked. */
    #[Test]
    public function exactly_two_statuses_accept_marks(): void
    {
        $accepting = array_values(array_filter(
            ExamStatus::cases(),
            static fn (ExamStatus $s): bool => $s->acceptsResultEntry(),
        ));

        $this->assertSame([ExamStatus::Conducted, ExamStatus::Marking], $accepting);
    }

    /** A cancelled exam is the only one that does not hold its slot — the same rule `active_guard` encodes. */
    #[Test]
    public function only_a_cancelled_exam_releases_its_slot(): void
    {
        foreach (ExamStatus::cases() as $case) {
            $this->assertSame(
                $case !== ExamStatus::Cancelled,
                $case->holdsTheSlot(),
                $case->value.' disagrees with active_guard about holding a slot.',
            );
        }
    }

    #[Test]
    public function the_default_grade_scale_is_seeded_and_its_bands_cover_zero_to_one_hundred(): void
    {
        $scale = DB::table('grade_scales')->where('code', 'DEFAULT')->first();

        $this->assertNotNull($scale, 'GradeScaleSeeder did not run.');
        $this->assertTrue((bool) $scale->is_default);

        $bands = DB::table('grade_scale_bands')
            ->where('grade_scale_id', $scale->id)
            ->orderBy('min_percentage')
            ->get();

        $this->assertCount(7, $bands);
        $this->assertSame('0.0000', (string) $bands->first()->min_percentage);
        $this->assertSame('100.0000', (string) $bands->last()->max_percentage);
    }
}
