<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Materials;

use App\Enums\SubmissionStatus;
use App\Models\Institute\AssignmentSubmission;
use App\Services\Institute\AssignmentGradeCalculator;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Materials\Concerns\BuildsMaterials;
use Tests\TestCase;

/**
 * INV-19-5, INV-19-6 and INV-19-7 through the real service path (phase-19-23 §6.7, §6.8, §11).
 */
final class AssignmentLifecycleTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsMaterials;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /*
    |--------------------------------------------------------------------------
    | INV-19-5 — one live submission, and a history that stacks
    |--------------------------------------------------------------------------
    */

    /**
     * A resubmission **supersedes**; it does not overwrite. The old attempt keeps its marks, its files
     * and its timestamps, which is what makes "I handed this in on the 3rd" answerable a term later.
     */
    #[Test]
    public function a_resubmission_supersedes_the_previous_attempt_and_keeps_everything_it_had(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['max_attempts' => 3]);

        $first = $this->handedIn($assignment, $student, $staff, withFile: true);
        $this->submissionService()->grade($first, ['obtained_marks' => '40'], $staff);

        $second = $this->submissionService()->resubmit($first->refresh(), ['submission_text' => 'Second go'], [], $staff);
        $first->refresh();

        $this->assertSame(SubmissionStatus::Superseded, $first->status);
        $this->assertFalse($first->isLive());
        $this->assertSame((int) $second->getKey(), (int) $first->getAttribute('superseded_by_id'));

        $this->assertSame('40.00', (string) $first->getAttribute('obtained_marks'), 'The old mark is kept.');
        $this->assertSame(1, $first->files()->count(), 'The old files are kept.');
        $this->assertNotNull($first->getAttribute('submitted_at'));

        $this->assertTrue($second->isLive());
        $this->assertSame(2, (int) $second->getAttribute('attempt_no'));
        $this->assertSame(
            1,
            $assignment->liveSubmissions()->where('student_id', $student->getKey())->count(),
            'INV-19-5: exactly one live submission per student.',
        );
    }

    /** `uq_as_live` is the guarantee, not the service — so it is asserted at the database. */
    #[Test]
    public function the_database_refuses_a_second_live_submission(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student, , $enrollment] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $this->handedIn($assignment, $student, $staff);

        $this->expectException(UniqueConstraintViolationException::class);

        $duplicate = new AssignmentSubmission;
        $duplicate->forceFill([
            'assignment_id' => (int) $assignment->getKey(),
            'student_id' => (int) $student->getKey(),
            'student_batch_enrollment_id' => (int) $enrollment->getKey(),
            'batch_id' => (int) $batch->getKey(),
            'attempt_no' => 2,
            'status' => SubmissionStatus::Submitted->value,
            'submitted_at' => Carbon::now(),
            'total_marks' => '50.00',
        ])->save();
    }

    #[Test]
    public function a_student_cannot_exceed_the_attempt_limit(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['max_attempts' => 2]);

        $first = $this->handedIn($assignment, $student, $staff);
        $second = $this->submissionService()->resubmit($first, ['submission_text' => 'Second'], [], $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/all 2 attempts/');

        $this->submissionService()->resubmit($second, ['submission_text' => 'Third'], [], $staff);
    }

    #[Test]
    public function a_superseded_attempt_cannot_be_marked(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $first = $this->handedIn($assignment, $student, $staff);
        $this->submissionService()->resubmit($first, ['submission_text' => 'Second'], [], $staff);

        $this->expectException(CourseRuleException::class);

        $this->submissionService()->grade($first->refresh(), ['obtained_marks' => '10'], $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | INV-19-6 — the ceiling, at three layers
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_service_refuses_a_mark_above_the_total(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submission = $this->handedIn($assignment, $student, $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/out of 50\.00/');

        $this->submissionService()->grade($submission, ['obtained_marks' => '50.01'], $staff);
    }

    /** `chk_asub_marks` compares two columns of the same row, so it holds whoever writes it. */
    #[Test]
    public function the_database_refuses_a_mark_above_the_total(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submission = $this->handedIn($assignment, $student, $staff);

        $this->expectException(QueryException::class);

        DB::table('assignment_submissions')
            ->where('id', $submission->getKey())
            ->update(['obtained_marks' => '50.01']);
    }

    /**
     * **The snapshot is the point.** A mark is checked against what the *submission* was set out of,
     * so amending the assignment's total later cannot retroactively invalidate a mark already given.
     */
    #[Test]
    public function the_ceiling_is_the_rows_snapshot_and_not_the_assignments_current_total(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submission = $this->handedIn($assignment, $student, $staff);
        $graded = $this->submissionService()->grade($submission, ['obtained_marks' => '45'], $staff);

        $this->assignmentService()->amend($assignment, ['total_marks' => '20'], 'Rescoped after the moderation meeting', $staff);

        $this->assertSame('50.00', (string) $graded->refresh()->getAttribute('total_marks'), 'The row still knows what it was marked out of.');
        $this->assertSame('45.00', (string) $graded->getAttribute('obtained_marks'), 'And the mark stands.');
    }

    /*
    |--------------------------------------------------------------------------
    | INV-19-7 — lateness is decided once
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function lateness_is_decided_at_hand_in_and_survives_a_deadline_extension(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);

        // Published with a future deadline, then the clock moves — which is what happens in life.
        $assignment->forceFill(['deadline_at' => Carbon::now()->subHours(3)])->saveQuietly();

        $late = $this->handedIn($assignment->refresh(), $student, $staff);

        $this->assertTrue((bool) $late->getAttribute('is_late'));
        $this->assertEqualsWithDelta(180, (int) $late->getAttribute('minutes_late'), 1);

        $this->assignmentService()->amend($assignment, ['deadline_at' => Carbon::now()->addDays(3)], 'Extended after a power cut', $staff);

        $late->refresh();

        $this->assertTrue((bool) $late->getAttribute('is_late'), 'A later deadline is not a claim that nobody was ever late.');
        $this->assertEqualsWithDelta(180, (int) $late->getAttribute('minutes_late'), 1);
    }

    /**
     * The model refuses to move it, where `Gate::before` cannot wave a Super Admin past the rule.
     */
    #[Test]
    public function nothing_can_move_is_late_after_the_fact(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submission = $this->handedIn($assignment, $student, $staff);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/INV-19-7/');

        $submission->forceFill(['is_late' => true])->save();
    }

    #[Test]
    public function late_work_is_refused_outright_when_the_assignment_does_not_accept_it(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, [
            'submission_type' => 'text',
            'late_submission_allowed' => false,
        ]);

        $draft = $this->submissionService()->draft($assignment, $student, $staff);
        $assignment->forceFill(['deadline_at' => Carbon::now()->subHour()])->saveQuietly();

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/late work is not accepted/i');

        $this->submissionService()->submit($draft, ['submission_text' => 'Sorry'], [], $staff);
    }

    /** The penalty is charged once, and can never take a mark below zero. */
    #[Test]
    public function the_late_penalty_is_clamped_so_it_cannot_produce_a_negative_mark(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['late_penalty_percentage' => '100']);
        $assignment->forceFill(['deadline_at' => Carbon::now()->subHour()])->saveQuietly();

        $submission = $this->handedIn($assignment->refresh(), $student, $staff);
        $graded = $this->submissionService()->grade($submission, ['obtained_marks' => '3'], $staff);

        $this->assertSame('3.00', (string) $graded->getAttribute('penalty_marks'), 'The penalty is capped at what was earned.');
        $this->assertSame('0.00', (string) $graded->getAttribute('final_marks'));
    }

    /** PH19-33: the generated column and the PHP calculator must agree exactly. */
    #[Test]
    public function the_generated_final_marks_column_matches_the_php_calculator(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['late_penalty_percentage' => '10']);
        $assignment->forceFill(['deadline_at' => Carbon::now()->subHour()])->saveQuietly();

        $submission = $this->handedIn($assignment->refresh(), $student, $staff);

        foreach (['50', '40', '12.34', '0.01', '0'] as $mark) {
            $graded = $this->submissionService()->grade($submission->refresh(), ['obtained_marks' => $mark], $staff);

            $outcome = app(AssignmentGradeCalculator::class)->calculate(
                obtainedMarks: $mark,
                totalMarks: '50.00',
                latePenaltyPercentage: '10.0000',
                isLate: true,
                passingMarks: '20.00',
            );

            $this->assertSame(
                $outcome->finalMarks,
                (string) $graded->getAttribute('final_marks'),
                sprintf('PHP and MariaDB must agree on %s out of 50.', $mark),
            );

            // The column is decimal(8,4) and the calculator's contract is half-up at 2, so the stored
            // value is the same number with padding. Compared numerically rather than as a string.
            $this->assertSame(
                0,
                bccomp((string) $outcome->percentage, (string) $graded->getAttribute('percentage'), 4),
                sprintf('The percentage for %s out of 50 must match.', $mark),
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Closing, missing and reopening
    |--------------------------------------------------------------------------
    */

    /**
     * **D122.** A draft is a live row to `uq_as_live`, so an abandoned one used to be skipped by the
     * sweeper — leaving a student neither submitted nor missed, and invisible to both counts.
     */
    #[Test]
    public function an_abandoned_draft_counts_as_a_miss_when_the_assignment_closes(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$abandoner] = $this->studentWithLogin($batch, $staff);
        [$absentee] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);

        // One student starts and never submits; the other never appears.
        $this->submissionService()->draft($assignment, $abandoner, $staff);

        $closed = $this->assignmentService()->close($assignment, $staff);

        $this->assertSame(
            2,
            $closed->submissions()->where('status', SubmissionStatus::Missed->value)->count(),
            'Both the abandoned draft and the no-show are misses.',
        );

        $stats = $this->assignmentService()->statistics($closed->refresh());

        $this->assertSame(0, $stats->outstanding(), 'Nobody is left in limbo on an assignment that stopped collecting.');
    }

    #[Test]
    public function marking_missed_twice_writes_nothing_the_second_time(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $closed = $this->assignmentService()->close($assignment, $staff);

        $before = $closed->submissions()->count();
        $this->submissionService()->markMissed($closed);

        $this->assertSame($before, $closed->submissions()->count());
    }

    /** `closed` still shows the assignment to students — that is why it is not `archived`. */
    #[Test]
    public function a_closed_assignment_is_still_visible_to_students(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);

        $closed = $this->assignmentService()->close($this->publishedAssignment($batch, $staff), $staff);

        $this->assertTrue($closed->status->isVisibleToStudents());
        $this->assertFalse($closed->status->acceptsSubmissions());
    }

    #[Test]
    public function reopening_clears_the_misses_the_close_recorded(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $closed = $this->assignmentService()->close($assignment, $staff);

        $this->assertGreaterThan(0, $closed->submissions()->where('status', 'missed')->count());

        $reopened = $this->assignmentService()->reopen($closed, 'Power cut on the deadline day', $staff);

        $this->assertSame(0, $reopened->submissions()->where('status', 'missed')->count());
        $this->assertTrue($reopened->status->acceptsSubmissions());
    }

    /*
    |--------------------------------------------------------------------------
    | Publishing, freezing and duplicating
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_assignment_whose_deadline_has_passed_cannot_be_published(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);

        $draft = $this->draftAssignment($batch, $staff);
        $draft->forceFill(['deadline_at' => Carbon::now()->subDay()])->saveQuietly();

        $this->expectException(CourseRuleException::class);

        $this->assignmentService()->publish($draft->refresh(), $staff);
    }

    /**
     * The model refuses the three frozen columns once anything is graded, and `amend()` is the one
     * path through — which takes a reason.
     */
    #[Test]
    public function the_frozen_columns_refuse_an_ordinary_update_once_work_is_graded(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $this->submissionService()->grade($this->handedIn($assignment, $student, $staff), ['obtained_marks' => '30'], $staff);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/total_marks/');

        $assignment->refresh()->forceFill(['total_marks' => '80'])->save();
    }

    #[Test]
    public function amending_a_frozen_column_needs_a_reason(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);

        $assignment = $this->publishedAssignment($batch, $staff);

        $this->expectException(CourseRuleException::class);

        $this->assignmentService()->amend($assignment, ['total_marks' => '80'], '   ', $staff);
    }

    /**
     * **D121.** An edit that does not mention a field must not reset it — not to the institute's
     * default, and not to null.
     */
    #[Test]
    public function a_partial_update_leaves_untouched_fields_exactly_as_they_were(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);

        $assignment = $this->publishedAssignment($batch, $staff, [
            'max_attempts' => 2,
            'max_files' => 1,
            'passing_marks' => '20',
        ]);

        $this->assignmentService()->update($assignment, ['title' => 'Lab 1 (revised)'], $staff);

        $assignment->refresh();

        $this->assertSame('Lab 1 (revised)', $assignment->getAttribute('title'));
        $this->assertSame(2, (int) $assignment->getAttribute('max_attempts'), 'Not reset to the institute default of 3.');
        $this->assertSame(1, (int) $assignment->getAttribute('max_files'));
        $this->assertSame('20.00', (string) $assignment->getAttribute('passing_marks'), 'Not wiped by an absent key.');
    }

    /** §6.7: the brief is **referenced** by each copy, never re-uploaded. */
    #[Test]
    public function duplicating_to_another_batch_shares_the_brief_rather_than_copying_it(): void
    {
        $staff = $this->createSuperAdmin();
        $course = $this->courseWithOutline([1, 1], $staff);
        $batch = $this->runningBatch($course, [], $staff);
        $second = $this->runningBatch($course, [], $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $this->assignmentService()->attachBrief($assignment, $this->pdf('brief.pdf'));
        $assignment->refresh();

        $before = count(Storage::disk('private')->allFiles());

        $copies = $this->assignmentService()->duplicateToBatches($assignment, [(int) $second->getKey()], [], $staff);

        $this->assertCount(1, $copies);
        $this->assertSame(
            $assignment->getAttribute('attachment_path'),
            $copies->first()->getAttribute('attachment_path'),
            'One file, two rows pointing at it.',
        );
        $this->assertCount($before, Storage::disk('private')->allFiles(), 'Nothing new was written to disk.');
        $this->assertSame('draft', $copies->first()->status->value, 'A copy starts as a draft.');
    }

    #[Test]
    public function duplicating_to_a_batch_of_another_course_is_refused(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $elsewhere = $this->unrelatedBatch($staff);

        $assignment = $this->publishedAssignment($batch, $staff);

        $this->expectException(CourseRuleException::class);

        $this->assignmentService()->duplicateToBatches($assignment, [(int) $elsewhere->getKey()], [], $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | Marking
    |--------------------------------------------------------------------------
    */

    /** A teacher may mark privately and release the whole batch at once. */
    #[Test]
    public function marks_are_withheld_until_released_when_the_assignment_says_so(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['marks_visible_to_students' => false]);
        $graded = $this->submissionService()->grade($this->handedIn($assignment, $student, $staff), ['obtained_marks' => '40'], $staff);

        $this->assertNull($graded->getAttribute('marks_released_at'));
        $this->assertFalse($graded->marksVisibleToStudent());

        $released = $this->submissionService()->releaseMarks($assignment, $staff);

        $this->assertSame(1, $released);
        $this->assertTrue($graded->refresh()->marksVisibleToStudent());
    }

    /** All of it or none of it — the `AttendanceService::import` discipline (§6.8). */
    #[Test]
    public function bulk_grading_writes_nothing_when_any_row_is_wrong(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$first] = $this->studentWithLogin($batch, $staff);
        [$second] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $a = $this->handedIn($assignment, $first, $staff);
        $b = $this->handedIn($assignment, $second, $staff);

        $result = $this->submissionService()->bulkGrade($assignment, [
            ['submission_id' => (int) $a->getKey(), 'obtained_marks' => '30'],
            ['submission_id' => (int) $b->getKey(), 'obtained_marks' => '999'],
        ], $staff);

        $this->assertFalse($result->succeeded());
        $this->assertSame(0, $result->graded);
        $this->assertNull($a->refresh()->getAttribute('obtained_marks'), 'The valid row is not written either.');

        $ok = $this->submissionService()->bulkGrade($assignment, [
            ['submission_id' => (int) $a->getKey(), 'obtained_marks' => '30'],
            ['submission_id' => (int) $b->getKey(), 'obtained_marks' => '45'],
        ], $staff);

        $this->assertTrue($ok->succeeded());
        $this->assertSame(2, $ok->graded);
    }

    /** A mark the student has seen changes only with a reason, and the change is recorded. */
    #[Test]
    public function amending_a_released_mark_records_who_changed_it_and_why(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $graded = $this->submissionService()->grade($this->handedIn($assignment, $student, $staff), ['obtained_marks' => '30'], $staff);

        $amended = $this->submissionService()->amend($graded, ['obtained_marks' => '38'], 'Question 4 was marked against the wrong rubric', $staff);

        $this->assertSame('38.00', (string) $amended->getAttribute('obtained_marks'));
        $this->assertTrue($amended->wasAmended());
        $this->assertSame((int) $staff->getKey(), (int) $amended->getAttribute('amended_by'));
        $this->assertStringContainsString('rubric', (string) $amended->getAttribute('amendment_reason'));
    }

    #[Test]
    public function amending_without_a_reason_is_refused(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $graded = $this->submissionService()->grade($this->handedIn($assignment, $student, $staff), ['obtained_marks' => '30'], $staff);

        $this->expectException(CourseRuleException::class);

        $this->submissionService()->amend($graded, ['obtained_marks' => '38'], '', $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | What is never deleted
    |--------------------------------------------------------------------------
    */

    /**
     * §2.7. **The policy is not what protects this, and the test says so.**
     *
     * `Gate::before` allows a Super Admin everything before a policy is consulted, so
     * `can('delete', …)` is *true* for them however `AssignmentSubmissionPolicy::delete()` is written.
     * The policy stops every other role; the model's hook stops everyone. Asserting only the policy
     * here would have read as a passing test while the most privileged account in the system deleted
     * a class's marked work.
     */
    #[Test]
    public function a_submission_is_never_deleted_and_the_model_is_what_stops_it(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $submission = $this->handedIn($assignment, $student, $staff);

        // Every role the policy is actually consulted for.
        $coordinator = $this->createUserWithPermissions(['assignment_submissions.view_any', 'assignment_submissions.edit']);

        $this->assertFalse($coordinator->can('delete', $submission));
        $this->assertFalse($coordinator->can('forceDelete', $submission));
        $this->assertFalse($coordinator->can('restore', $submission));

        // And the Super Admin, who skips all three.
        $this->assertTrue($staff->can('delete', $submission), 'Gate::before waves them past the policy.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/never deleted/');

        $submission->delete();
    }

    /** A draft is the documented exception — it is not evidence of anything. */
    #[Test]
    public function a_student_may_withdraw_a_draft_but_not_work_they_handed_in(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);

        $draft = $this->submissionService()->draft($assignment, $student, $staff);
        $this->submissionService()->withdraw($draft, $staff);

        $this->assertSame(0, $assignment->submissions()->count());

        $submitted = $this->handedIn($assignment, $student, $staff);

        $this->expectException(CourseRuleException::class);

        $this->submissionService()->withdraw($submitted, $staff);
    }

    /**
     * An assignment somebody submitted to is closed, never removed.
     *
     * **A soft delete is an UPDATE, so `restrictOnDelete` never sees it** — and `Gate::before` skips
     * the policy for a Super Admin. Without the model hook the one role most able to do damage was
     * the only role able to hide a class's work.
     */
    #[Test]
    public function an_assignment_with_a_submission_cannot_be_deleted_by_anybody(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $coordinator = $this->createUserWithPermissions(['assignments.view_any', 'assignments.delete']);

        $this->assertTrue($coordinator->can('delete', $assignment), 'Nobody has submitted yet.');

        $this->handedIn($assignment, $student, $staff);
        $assignment->refresh();

        $this->assertFalse($coordinator->can('delete', $assignment), 'The policy stops an ordinary role.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Close or archive it/');

        $assignment->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | The submission type
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function file_or_text_needs_at_least_one_of_the_two(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff);
        $draft = $this->submissionService()->draft($assignment, $student, $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/file or type/i');

        $this->submissionService()->submit($draft, [], [], $staff);
    }

    #[Test]
    public function a_text_only_assignment_refuses_a_file(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        [$student] = $this->studentWithLogin($batch, $staff);

        $assignment = $this->publishedAssignment($batch, $staff, ['submission_type' => 'text']);
        $draft = $this->submissionService()->draft($assignment, $student, $staff);

        $this->expectException(CourseRuleException::class);

        $this->submissionService()->submit($draft, ['submission_text' => 'Here'], [$this->pdf()], $staff);
    }

    /** A student with no active enrollment on the batch has no business submitting. */
    #[Test]
    public function a_student_who_is_not_on_the_batch_cannot_start_a_submission(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $assignment = $this->publishedAssignment($batch, $staff);

        $stranger = $this->registeredStudent($staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessageMatches('/not enrolled/i');

        $this->submissionService()->draft($assignment, $stranger, $staff);
    }
}
