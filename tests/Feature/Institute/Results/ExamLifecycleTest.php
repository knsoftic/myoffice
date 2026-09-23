<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Enums\ExamStatus;
use App\Models\Institute\Exam;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\NoGradeScale;
use App\Services\Institute\ScheduleClashDetector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * The exam's status ladder, its slot, and the four columns publication freezes
 * (phase-19-23 §2.11, §2.28.4, §11).
 */
final class ExamLifecycleTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | The ladder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_new_exam_is_a_draft_and_takes_its_course_and_branch_from_the_batch(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);

        $exam = $this->draftExam($batch, [], $staff);

        $this->assertSame(ExamStatus::Draft, $exam->status);
        $this->assertSame((int) $batch->getAttribute('course_id'), (int) $exam->getAttribute('course_id'));
        $this->assertSame(
            $batch->getAttribute('branch_id') === null ? null : (int) $batch->getAttribute('branch_id'),
            $exam->getAttribute('branch_id') === null ? null : (int) $exam->getAttribute('branch_id'),
        );
    }

    #[Test]
    public function the_ladder_runs_draft_scheduled_ongoing_conducted(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        $this->assertSame(ExamStatus::Scheduled, $this->examService()->schedule($exam, $staff)->status);
        $this->assertSame(ExamStatus::Ongoing, $this->examService()->markOngoing($exam->refresh(), $staff)->status);
        $this->assertSame(ExamStatus::Conducted, $this->examService()->markConducted($exam->refresh(), $staff)->status);
    }

    #[Test]
    public function marks_cannot_be_entered_before_the_exam_has_happened(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessage('Mark it conducted first');

        $this->resultService()->saveSheet($exam, [], $staff);
    }

    #[Test]
    public function a_conducted_exam_cannot_be_called_off_because_it_happened(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->expectException(CourseRuleException::class);

        $this->examService()->cancel($exam, 'Changed our minds', $staff);
    }

    #[Test]
    public function cancelling_demands_a_reason_and_keeps_it(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        try {
            $this->examService()->cancel($exam, '', $staff);
            $this->fail('An exam was called off without a reason.');
        } catch (CourseRuleException) {
            // expected
        }

        $cancelled = $this->examService()->cancel($exam->refresh(), 'The room flooded', $staff);

        $this->assertSame(ExamStatus::Cancelled, $cancelled->status);
        $this->assertSame('The room flooded', $cancelled->getAttribute('cancellation_reason'));
        // Not deleted: "what happened to the exam I was told about" is a question a student asks.
        $this->assertNull($cancelled->getAttribute('deleted_at'));
    }

    /*
    |--------------------------------------------------------------------------
    | The slot — uq_ex_batch_slot and the clash detector
    |--------------------------------------------------------------------------
    */

    /**
     * Phase 20 must **register** itself with `ScheduleClashDetector`. It ships a `register()` hook for
     * exactly this, and for one commit nothing called it: the check ran, found no exam source, and
     * reported clean. Every exam was clash-checked against classes and demos and against no other
     * exam at all.
     */
    #[Test]
    public function exams_are_one_of_the_things_the_clash_detector_scans(): void
    {
        $this->assertArrayHasKey('exam', ScheduleClashDetector::occupants());

        $spec = ScheduleClashDetector::occupants()['exam'];

        $this->assertSame('exams', $spec['table']);
        $this->assertSame('batch_id', $spec['batch']);
        $this->assertSame('teacher_id', $spec['teacher']);
        $this->assertSame('classroom_id', $spec['classroom']);

        // The live list is derived from `holdsTheSlot()`, so it is the same rule `active_guard`
        // encodes and cannot drift when a status is added.
        $live = $spec['live'][0][2];
        $this->assertContains(ExamStatus::Draft->value, $live);
        $this->assertContains(ExamStatus::ResultsPublished->value, $live);
        $this->assertNotContains(ExamStatus::Cancelled->value, $live);
    }

    #[Test]
    public function a_second_exam_overlapping_the_first_is_refused(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $date = now()->addDays(7)->toDateString();

        $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '10:00', 'end_time' => '12:00'], $staff);

        $this->expectException(CourseRuleException::class);

        // 10:30–11:30 sits inside 10:00–12:00. Different start time, so the unique index would miss it.
        $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '10:30', 'end_time' => '11:30'], $staff);
    }

    /** Back-to-back is not a clash: the comparison is strict on both sides. */
    #[Test]
    public function an_exam_starting_when_the_last_one_ends_is_allowed(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $date = now()->addDays(7)->toDateString();

        $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00'], $staff);
        $second = $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '11:00', 'end_time' => '12:00'], $staff);

        $this->assertSame(ExamStatus::Draft, $second->status);
    }

    /**
     * `active_guard` is NULL for a cancelled exam, and MariaDB tolerates unlimited NULLs in a unique
     * index — so the cancelled row keeps its date, its reason and any marks already entered while the
     * slot reads as free for the replacement.
     */
    #[Test]
    public function a_cancelled_exam_releases_its_slot_without_being_deleted(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $date = now()->addDays(7)->toDateString();

        $first = $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '10:00', 'end_time' => '12:00'], $staff);
        $this->examService()->cancel($first, 'The examiner is unwell', $staff);

        $replacement = $this->draftExam($batch, [
            'scheduled_date' => $date, 'start_time' => '10:00', 'end_time' => '12:00',
        ], $staff);

        $this->assertSame(ExamStatus::Draft, $replacement->status);
        $this->assertDatabaseHas('exams', ['id' => $first->getKey(), 'scheduled_date' => $date, 'deleted_at' => null]);
        $this->assertNull(DB::table('exams')->where('id', $first->getKey())->value('active_guard'));
        $this->assertSame(1, (int) DB::table('exams')->where('id', $replacement->getKey())->value('active_guard'));
    }

    #[Test]
    public function two_live_exams_in_the_exact_same_slot_are_impossible_at_the_database(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $date = now()->addDays(7)->toDateString();

        $first = $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00'], $staff);
        $second = $this->draftExam($batch, ['scheduled_date' => $date, 'start_time' => '14:00', 'end_time' => '15:00'], $staff);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('exams')->where('id', $second->getKey())->update(['start_time' => '10:00:00']);
    }

    /*
    |--------------------------------------------------------------------------
    | The publication freeze — in the model, because Gate::before waves a Super Admin past a policy
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function frozenColumns(): array
    {
        return [
            'total_marks' => ['total_marks', '200'],
            'passing_marks' => ['passing_marks', '90'],
        ];
    }

    #[DataProvider('frozenColumns')]
    #[Test]
    public function publication_freezes_what_the_class_was_measured_against(string $column, string $value): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($column);

        $this->examService()->update($exam->refresh(), [$column => $value], $staff);
    }

    /**
     * **The date is frozen by `reschedule()`, not by the model hook**, because `update()` never writes
     * `scheduled_date` at all — moving an exam takes a reason and re-runs the clash check, so it has
     * its own entry point. The refusal is therefore a `CourseRuleException` rather than the
     * `LogicException` the other three throw, which is worth asserting rather than assuming.
     */
    #[Test]
    public function a_published_exam_refuses_to_be_moved(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessage('printed result card');

        $this->examService()->reschedule(
            $exam->refresh(),
            ['scheduled_date' => now()->addDays(30)->toDateString()],
            'We would like it later',
            $staff,
        );
    }

    /**
     * `update()` is not a second way to move an exam, and a screen must not offer the field as though
     * it were: a coordinator who changed the date there would be told "Exam updated" and see the old
     * date. The edit form marks it read-only and points at the reschedule panel.
     */
    #[Test]
    public function update_never_moves_the_date_even_when_one_is_submitted(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);
        $was = (string) $exam->getAttribute('scheduled_date')->toDateString();

        $updated = $this->examService()->update($exam, [
            'name' => 'Renamed',
            'scheduled_date' => now()->addDays(90)->toDateString(),
        ], $staff);

        $this->assertSame('Renamed', $updated->getAttribute('name'));
        $this->assertSame($was, (string) $updated->getAttribute('scheduled_date')->toDateString());
    }

    /** Everything else stays editable: the freeze is about reproducibility, not about locking a row. */
    #[Test]
    public function a_published_exam_can_still_be_renamed_and_re_roomed(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $updated = $this->examService()->update($exam->refresh(), [
            'name' => 'Midterm 1 (renamed)',
            'notes' => 'Moved to the annexe at the last minute.',
        ], $staff);

        $this->assertSame('Midterm 1 (renamed)', $updated->getAttribute('name'));
    }

    /*
    |--------------------------------------------------------------------------
    | Deletion
    |--------------------------------------------------------------------------
    */

    /**
     * **The model refuses, not the policy.** `Gate::before` allows a Super Admin everything before a
     * policy is consulted, and a soft delete is an UPDATE that `restrictOnDelete` never sees — so
     * `ExamPolicy::delete()` would have stopped everyone except the one role most able to do damage.
     * That is D124, learned in Phase 19 and applied here from the start.
     */
    #[Test]
    public function an_exam_with_results_refuses_to_be_deleted_even_by_a_super_admin(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $this->assertTrue($staff->can('delete', $exam), 'A Super Admin passes the policy, as Gate::before guarantees.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cancel it with a reason');

        $exam->refresh()->delete();
    }

    #[Test]
    public function an_exam_nobody_sat_deletes_normally(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        $exam->delete();

        $this->assertSoftDeleted('exams', ['id' => $exam->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | The grade scale an exam resolves to
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_exam_with_no_scale_falls_through_to_the_institute_default(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        $this->assertSame('DEFAULT', $this->examService()->scaleFor($exam)->getAttribute('code'));
    }

    #[Test]
    public function an_exam_that_names_a_scale_uses_it(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $scale = $this->gradeScale(null, ['code' => 'OWN'], $staff);
        $exam = $this->draftExam($batch, ['grade_scale_id' => $scale->getKey()], $staff);

        $this->assertSame('OWN', $this->examService()->scaleFor($exam)->getAttribute('code'));
    }

    /**
     * No scale is a **refusal**, not a default guess. Grading a class on an assumption is worse than
     * telling somebody to choose.
     */
    #[Test]
    public function an_exam_with_no_scale_anywhere_is_refused_rather_than_guessed(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->draftExam($batch, [], $staff);

        settings_repo()->asSystem(static fn ($repo) => $repo->set('institute.default_grade_scale_id', ''));
        DB::table('grade_scales')->update(['is_default' => false, 'is_active' => false]);

        $this->expectException(NoGradeScale::class);

        $this->examService()->scaleFor($exam->refresh());
    }
}
