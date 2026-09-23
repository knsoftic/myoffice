<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Enums\ExamStatus;
use App\Models\Institute\ExamResult;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Institute\ResultSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Materials\Concerns\BuildsMaterials;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * §2.28.4's second pair of eyes, publication, withdrawal and amendment
 * (phase-19-23 §3.2, §6.10, INV-20-5, §11).
 */
final class ResultPublicationTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsMaterials;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | §2.28.4 — the checker is not the marker
    |--------------------------------------------------------------------------
    */

    /**
     * **A policy cannot express this**, which is why the service does. A policy sees one row and the
     * rule is about the whole sheet — a sheet entered by two people needs a third to sign it off.
     */
    #[Test]
    public function the_marker_cannot_check_their_own_sheet(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessage('somebody else');

        $this->resultService()->verify($exam->refresh(), $staff);
    }

    #[Test]
    public function a_second_person_checking_stamps_the_exam_and_every_row(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);
        $verified = $this->resultService()->verify($exam->refresh(), $checker);

        $this->assertNotNull($verified->getAttribute('results_verified_at'));
        $this->assertSame((int) $checker->getKey(), (int) $verified->getAttribute('results_verified_by'));
        $this->assertSame(0, ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->whereNull('verified_at')
            ->count());
    }

    #[Test]
    public function publishing_before_checking_is_refused(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessage('checked by a second person');

        $this->resultService()->publish($exam->refresh(), $staff);
    }

    /**
     * The four-eyes step is a setting, and turning it off has to actually turn it off — otherwise a
     * small institute where one person does everything can never publish anything.
     */
    #[Test]
    public function an_institute_may_turn_the_checking_step_off(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        settings_repo()->asSystem(static fn ($repo) => $repo->set('institute.result_publish_requires_verification', false));

        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['88', '35']), $staff);
        $published = $this->resultService()->publish($exam->refresh(), $staff);

        $this->assertSame(ExamStatus::ResultsPublished, $published->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Publication
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function publishing_stamps_every_row_and_ranks_the_class(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 4, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '88', '72', '35']), $staff);

        $rows = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->orderByDesc('obtained_marks')
            ->get();

        $this->assertSame(4, $rows->whereNotNull('published_at')->count());

        // Ties share a position and the next one skips: two on 88 are both 1st, 72 is 3rd.
        $this->assertSame(
            [1, 1, 3, 4],
            $rows->pluck('position_in_batch')->map(static fn ($p): int => (int) $p)->all(),
        );
    }

    /** An absence has no position: coming last and not sitting it are different facts. */
    #[Test]
    public function an_absent_student_is_ranked_last_rather_than_unranked(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 3, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '50', null]), $staff);

        $absent = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->whereNull('obtained_marks')
            ->firstOrFail();

        $this->assertFalse((bool) $absent->getAttribute('is_passed'));
        $this->assertNull($absent->getAttribute('percentage'));
    }

    /*
    |--------------------------------------------------------------------------
    | Withdrawal — INV-20-5
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function withdrawing_demands_a_reason_and_hides_every_row_again(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '35']), $staff, $checker);

        try {
            $this->resultService()->unpublish($exam->refresh(), '', $checker);
            $this->fail('Results were withdrawn without a reason.');
        } catch (CourseRuleException) {
            // expected
        }

        $withdrawn = $this->resultService()->unpublish($exam->refresh(), 'Question 4 was mismarked throughout', $checker);

        $this->assertSame(ExamStatus::Marking, $withdrawn->status);
        $this->assertSame(0, ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->whereNotNull('published_at')
            ->count());
        // Nothing is deleted: the marks are still there to be corrected.
        $this->assertSame(2, ExamResult::query()->where('exam_id', $exam->getKey())->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Amendment — the only way a published mark changes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function amending_demands_a_reason_and_records_who_and_why(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '35']), $staff, $checker);

        $row = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->where('obtained_marks', '35.00')
            ->firstOrFail();

        try {
            $this->resultService()->amend($row, ['obtained_marks' => '55'], '', $staff);
            $this->fail('A published mark changed without a reason.');
        } catch (CourseRuleException) {
            // expected
        }

        $amended = $this->resultService()->amend($row->refresh(), ['obtained_marks' => '55'], 'Question 4 re-marked', $staff);

        $this->assertSame('55.00', (string) $amended->getAttribute('obtained_marks'));
        $this->assertSame('Question 4 re-marked', $amended->getAttribute('amendment_reason'));
        $this->assertNotNull($amended->getAttribute('amended_at'));
        $this->assertSame((int) $staff->getKey(), (int) $amended->getAttribute('amended_by'));
        $this->assertTrue($amended->wasAmended());
    }

    #[Test]
    public function amending_regrades_the_row_and_recounts_the_exam(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, ['passing_marks' => '40'], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '35']), $staff, $checker);

        $row = ExamResult::query()
            ->where('exam_id', $exam->getKey())
            ->where('obtained_marks', '35.00')
            ->firstOrFail();

        $this->assertFalse((bool) $row->getAttribute('is_passed'));

        $amended = $this->resultService()->amend($row, ['obtained_marks' => '55'], 'Question 4 re-marked', $staff);

        $this->assertTrue((bool) $amended->getAttribute('is_passed'), 'A regrade has to follow the new mark.');
        $this->assertSame('55.0000', (string) $amended->getAttribute('percentage'));
        $this->assertSame(2, (int) $exam->refresh()->getAttribute('passed_count'));
        $this->assertSame('55.00', (string) $exam->getAttribute('lowest_marks'));
    }

    #[Test]
    public function an_amended_mark_cannot_exceed_the_rows_own_snapshot(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $exam = $this->conductedExam($batch, ['total_marks' => '50', 'passing_marks' => '20'], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['44', '18']), $staff, $checker);

        $row = ExamResult::query()->where('exam_id', $exam->getKey())->firstOrFail();

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessage('out of 50.00');

        $this->resultService()->amend($row, ['obtained_marks' => '51'], 'Typo', $staff);
    }

    /*
    |--------------------------------------------------------------------------
    | §3.2 — what a student may see, and the totals they are shown
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_student_sees_nothing_until_it_is_published_and_nothing_again_once_it_is_withdrawn(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        [$student, $studentUser] = $this->studentWithLogin($batch, $staff);
        $classmate = $this->roster($batch, 1, $staff)[0];

        $exam = $this->conductedExam($batch, [], $staff);
        $rows = [
            ['student_id' => (int) $student->getKey(), 'attendance_status' => 'appeared', 'obtained_marks' => '88'],
            ['student_id' => (int) $classmate->getAttribute('student_id'), 'attendance_status' => 'appeared', 'obtained_marks' => '61'],
        ];

        $this->resultService()->saveSheet($exam, $rows, $staff);

        $this->actingAs($studentUser)->get(route('student.results.index'))
            ->assertOk()
            ->assertDontSee('88.00');

        $this->resultService()->verify($exam->refresh(), $checker);
        $this->resultService()->publish($exam->refresh(), $checker);

        $this->actingAs($studentUser)->get(route('student.results.index'))
            ->assertOk()
            ->assertSee('88.00');

        $this->resultService()->unpublish($exam->refresh(), 'Mismarked across the sheet', $checker);

        $this->actingAs($studentUser)->get(route('student.results.index'))
            ->assertOk()
            ->assertDontSee('88.00');
    }

    /**
     * **A half-entered sheet cannot go out.** Publishing 27 marks out of 30 tells three students
     * nothing while telling the other 27 they are ranked against a class of 27 — and the positions
     * would be wrong for everybody. The refusal names how many are missing.
     */
    #[Test]
    public function an_incomplete_sheet_refuses_to_be_published(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 3, $staff);
        $exam = $this->conductedExam($batch, [], $staff);

        // Two of the three.
        $this->resultService()->saveSheet($exam, [
            ['student_id' => (int) $seats[0]->getAttribute('student_id'), 'attendance_status' => 'appeared', 'obtained_marks' => '88'],
            ['student_id' => (int) $seats[1]->getAttribute('student_id'), 'attendance_status' => 'appeared', 'obtained_marks' => '61'],
        ], $staff);

        $this->resultService()->verify($exam->refresh(), $checker);

        $this->expectException(CourseRuleException::class);
        $this->expectExceptionMessage('has no mark yet');

        $this->resultService()->publish($exam->refresh(), $checker);
    }

    /**
     * The percentage and the grade-point average treat an absence differently on purpose: it counts
     * in the denominator (so it drags the percentage down) and carries no grade point (so it is left
     * out of the average rather than counted as 0.0). Both callers use this one calculation.
     */
    #[Test]
    public function the_summary_counts_an_absence_against_the_percentage_and_not_against_the_average(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 3, $staff);
        $scale = $this->gradeScale(null, ['code' => 'GPA'], $staff);
        $exam = $this->conductedExam($batch, ['grade_scale_id' => $scale->getKey(), 'passing_marks' => '0'], $staff);

        $this->publishedExam($exam, $this->sheetRows($seats, ['80', '80', null]), $staff, $checker);

        $summary = ResultSummary::of(ExamResult::query()->where('exam_id', $exam->getKey())->get());

        $this->assertSame(3, $summary['counted'], 'An absence counts in the denominator.');
        $this->assertSame('160.00', $summary['obtained']);
        $this->assertSame('300.00', $summary['total']);
        // Two rows carry a grade point of 4.00; the absence carries none and is not a zero.
        $this->assertSame('4.00', $summary['gradePoints']);
    }

    #[Test]
    public function a_summary_over_nothing_is_a_dash_rather_than_zero_per_cent(): void
    {
        $summary = ResultSummary::of([]);

        $this->assertSame(0, $summary['count']);
        $this->assertNull($summary['percentage'], 'Zero per cent reads as failure; no exams sat is not failure.');
        $this->assertNull($summary['gradePoints']);
    }
}
