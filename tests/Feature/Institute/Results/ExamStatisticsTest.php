<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Results;

use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\ExamStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsAdmissions;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\Feature\Institute\Results\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * The one definition of "how did they do" (phase-19-23 §6.10, INV-23-1).
 *
 * **This class exists so the certificate and the result card cannot disagree.** §6.10 says
 * `forStudent()` is *"the input `CertificateService` uses"*, and the reason is that two code paths
 * computing an aggregate eventually produce two different As — and the one printed on a certificate
 * is the one nobody can correct afterwards.
 */
final class ExamStatisticsTest extends TestCase
{
    use BuildsAdmissions;
    use BuildsCatalogue;
    use BuildsExams;
    use BuildsRegisters;
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    private function stats(): ExamStatisticsService
    {
        return app(ExamStatisticsService::class);
    }

    /**
     * A batch with a weighted midterm (30%) and final (70%), plus a quiz that must be ignored.
     *
     * Strong sits 60 / 90 / 10; weak sits 30 / 50 / 10.
     *
     * @return array{0: StudentBatchEnrollment, 1: StudentBatchEnrollment}
     */
    private function weightedCourse(): array
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();

        $batch = $this->runningBatch($this->courseWithOutline([1, 1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);
        $scale = $this->gradeScale(null, ['code' => 'W'.mb_substr(uniqid(), -5)], $staff);

        $shared = [
            'grade_scale_id' => $scale->getKey(),
            'passing_marks' => '40',
            'start_time' => '09:00',
            'end_time' => '11:00',
        ];

        $midterm = $this->conductedExam($batch, $shared + [
            'exam_type' => 'midterm',
            'name' => 'Midterm',
            'weight_percentage' => '30.0000',
            'scheduled_date' => now()->addDays(3)->toDateString(),
        ], $staff);
        $this->publishedExam($midterm, $this->sheetRows($seats, ['60', '30']), $staff, $checker);

        $final = $this->conductedExam($batch, $shared + [
            'exam_type' => 'final',
            'name' => 'Final',
            'weight_percentage' => '70.0000',
            'scheduled_date' => now()->addDays(10)->toDateString(),
        ], $staff);
        $this->publishedExam($final, $this->sheetRows($seats, ['90', '50']), $staff, $checker);

        $quiz = $this->conductedExam($batch, $shared + [
            'exam_type' => 'quiz',
            'name' => 'Quiz',
            'scheduled_date' => now()->addDays(14)->toDateString(),
        ], $staff);
        $this->publishedExam($quiz, $this->sheetRows($seats, ['10', '10']), $staff, $checker);

        return [$seats[0], $seats[1]];
    }

    /*
    |--------------------------------------------------------------------------
    | Major exams, and the quiz that is not one
    |--------------------------------------------------------------------------
    */

    /**
     * `ExamType::isMajor()` is midterms and finals only. A student who scored 10% on a weekly quiz
     * is not refused a certificate for it, and one who failed the final is.
     */
    #[Test]
    public function only_midterms_and_finals_count_as_major_exams(): void
    {
        [$strong, $weak] = $this->weightedCourse();

        $strongStats = $this->stats()->forStudent($strong);
        $weakStats = $this->stats()->forStudent($weak);

        $this->assertSame(3, $strongStats->results->count(), 'Every published result is listed.');
        $this->assertSame(2, $strongStats->majorsTotal, 'But only two of them are major.');

        $this->assertTrue($strongStats->passedEveryMajor());
        $this->assertFalse($weakStats->passedEveryMajor(), 'Weak failed the midterm at 30%.');
        $this->assertSame(1, $weakStats->majorsPassed);
    }

    /**
     * **A course with no major exam answers `true`**, and that is deliberate: a short practical
     * course may legitimately have none, and refusing every certificate on it would make the rule
     * impossible to satisfy rather than merely strict. `describe()` says which case it is, because
     * "0 of 0 passed" reads like a failure.
     */
    #[Test]
    public function a_course_with_no_major_exams_has_passed_them_all(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seat = $this->roster($batch, 1, $staff)[0];

        $stats = $this->stats()->forStudent($seat);

        $this->assertTrue($stats->passedEveryMajor());
        $this->assertSame(0, $stats->majorsTotal);
        $this->assertStringContainsString('no midterm or final', $stats->describe());
    }

    /**
     * A published major with no row for this student is neither a pass nor a fail — they were not on
     * the roster that day, or the sheet is incomplete. `majorsMissing` is what stops it being
     * silently counted as either.
     */
    #[Test]
    public function a_major_exam_the_student_has_no_result_for_is_counted_as_missing(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 2, $staff);

        $exam = $this->conductedExam($batch, [
            'exam_type' => 'final',
            'name' => 'Final',
            'passing_marks' => '40',
        ], $staff);
        $this->publishedExam($exam, $this->sheetRows($seats, ['80', '80']), $staff, $checker);

        // A third student joins afterwards and has no row on that sheet.
        $latecomer = $this->roster($batch, 1, $staff)[0];

        $stats = $this->stats()->forStudent($latecomer);

        $this->assertSame(1, $stats->majorsTotal);
        $this->assertSame(0, $stats->majorsPassed);
        $this->assertSame(1, $stats->majorsMissing);
        $this->assertFalse($stats->passedEveryMajor());
        $this->assertStringContainsString('no result', $stats->describe());
    }

    /*
    |--------------------------------------------------------------------------
    | The four grade modes
    |--------------------------------------------------------------------------
    */

    /** (60 × 30 + 90 × 70) ÷ 100 = 81.0000, in bcmath at four decimals. */
    #[Test]
    public function weighted_average_uses_each_exams_weight(): void
    {
        [$strong, $weak] = $this->weightedCourse();

        $this->assertSame('81.0000', $this->stats()->aggregateFor($strong, 'weighted_average')->percentage);
        $this->assertSame('44.0000', $this->stats()->aggregateFor($weak, 'weighted_average')->percentage);
    }

    /**
     * **An exam with no weight is weighted equally with its peers**, which is the only sensible
     * reading of a nullable column: treating null as zero would silently drop it from the average,
     * and treating it as 100 would swamp everything else. A set where every weight is null is
     * therefore a plain mean — and that is the common case.
     */
    #[Test]
    public function exams_with_no_weight_are_weighted_equally(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);
        $scale = $this->gradeScale(null, ['code' => 'EQ'.mb_substr(uniqid(), -4)], $staff);

        foreach ([['midterm', '80', 3], ['final', '40', 20]] as [$type, $mark, $day]) {
            $exam = $this->conductedExam($batch, [
                'exam_type' => $type,
                'name' => ucfirst($type),
                'grade_scale_id' => $scale->getKey(),
                'passing_marks' => '40',
                'scheduled_date' => now()->addDays($day)->toDateString(),
                'start_time' => '13:00',
                'end_time' => '15:00',
            ], $staff);
            $this->publishedExam($exam, $this->sheetRows($seats, [$mark]), $staff, $checker);
        }

        $this->assertSame('60.0000', $this->stats()->aggregateFor($seats[0], 'weighted_average')->percentage);
    }

    #[Test]
    public function best_exam_takes_the_highest_major_percentage(): void
    {
        [$strong, $weak] = $this->weightedCourse();

        $this->assertSame('90.0000', $this->stats()->aggregateFor($strong, 'best_exam')->percentage);
        // 10% on the quiz is higher than nothing but is not a major, so 50 wins.
        $this->assertSame('50.0000', $this->stats()->aggregateFor($weak, 'best_exam')->percentage);
    }

    #[Test]
    public function final_exam_takes_the_final(): void
    {
        [$strong] = $this->weightedCourse();

        $this->assertSame('90.0000', $this->stats()->aggregateFor($strong, 'final_exam')->percentage);
    }

    /**
     * **The fallback matters.** An institute that sets `final_exam` and then runs a course assessed
     * by two midterms would otherwise get no grade at all, and no explanation why.
     */
    #[Test]
    public function final_exam_falls_back_to_the_last_major_when_there_is_no_final(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);

        foreach ([['70', 3], ['85', 20]] as [$mark, $day]) {
            $exam = $this->conductedExam($batch, [
                'exam_type' => 'midterm',
                'name' => 'Midterm '.$day,
                'passing_marks' => '40',
                'scheduled_date' => now()->addDays($day)->toDateString(),
                'start_time' => '13:00',
                'end_time' => '15:00',
            ], $staff);
            $this->publishedExam($exam, $this->sheetRows($seats, [$mark]), $staff, $checker);
        }

        $this->assertSame('85.0000', $this->stats()->aggregateFor($seats[0], 'final_exam')->percentage);
    }

    /** `manual` computes nothing, and saying so is different from computing a zero. */
    #[Test]
    public function manual_computes_nothing(): void
    {
        [$strong] = $this->weightedCourse();

        $aggregate = $this->stats()->aggregateFor($strong, 'manual');

        $this->assertNull($aggregate->percentage);
        $this->assertFalse($aggregate->exists());
        $this->assertSame(0, $aggregate->examCount);
    }

    /*
    |--------------------------------------------------------------------------
    | Nothing to aggregate
    |--------------------------------------------------------------------------
    */

    /**
     * Null, not zero. Zero per cent on a certificate says the student scored nothing, which is a
     * different and defamatory claim from "there is no aggregate".
     */
    #[Test]
    public function an_aggregate_over_no_exams_is_null_rather_than_zero(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seat = $this->roster($batch, 1, $staff)[0];

        $aggregate = $this->stats()->aggregateFor($seat, 'weighted_average');

        $this->assertNull($aggregate->percentage);
        $this->assertNull($aggregate->grade);
        $this->assertFalse($aggregate->exists());
        $this->assertSame([], array_filter($aggregate->toColumns()));
    }

    /**
     * An unpublished sheet must never decide whether somebody graduates — §3.2 names exactly one
     * status in which a mark exists outside the marking room.
     */
    #[Test]
    public function an_unpublished_sheet_contributes_nothing(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 1, $staff);

        $exam = $this->conductedExam($batch, ['exam_type' => 'final', 'passing_marks' => '40'], $staff);
        $this->resultService()->saveSheet($exam, $this->sheetRows($seats, ['95']), $staff);

        $stats = $this->stats()->forStudent($seats[0]);

        $this->assertSame(0, $stats->results->count());
        $this->assertNull($stats->aggregate->percentage);
        // The exam is not published, so it is not a major exam anybody can be held to yet.
        $this->assertSame(0, $stats->majorsTotal);
    }

    /*
    |--------------------------------------------------------------------------
    | forExam reads the caches rather than recounting
    |--------------------------------------------------------------------------
    */

    /**
     * A report that recounted could disagree with the exam screen, and the exam screen is the one
     * somebody is looking at while they ask.
     */
    #[Test]
    public function for_exam_reports_the_same_figures_the_exam_screen_shows(): void
    {
        $staff = $this->createSuperAdmin();
        $checker = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $seats = $this->roster($batch, 4, $staff);

        $exam = $this->conductedExam($batch, ['exam_type' => 'final', 'passing_marks' => '40'], $staff);
        $this->publishedExam($exam, $this->sheetRows($seats, ['88', '72', '35', null]), $staff, $checker);

        $figures = $this->stats()->forExam($exam->refresh());

        $this->assertSame(4, $figures['expected']);
        $this->assertSame(3, $figures['appeared']);
        $this->assertSame(1, $figures['absent']);
        $this->assertSame(2, $figures['passed']);
        $this->assertSame('88.00', (string) $figures['highest']);
        $this->assertSame('35.00', (string) $figures['lowest']);
        // Two of the three who sat it passed.
        $this->assertSame('66.67', $figures['pass_rate']);
    }

    /** A pass rate over nobody is null, not 0%. */
    #[Test]
    public function a_pass_rate_over_no_candidates_is_null(): void
    {
        $staff = $this->createSuperAdmin();
        $batch = $this->runningBatch($this->courseWithOutline([1], $staff), [], $staff);
        $exam = $this->conductedExam($batch, ['exam_type' => 'final'], $staff);

        $this->assertNull($this->stats()->forExam($exam)['pass_rate']);
    }
}
