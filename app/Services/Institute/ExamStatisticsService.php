<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\AggregateGrade;
use App\DataObjects\Institute\StudentExamStats;
use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Models\Institute\Exam;
use App\Models\Institute\ExamResult;
use App\Models\Institute\GradeScale;
use App\Models\Institute\StudentBatchEnrollment;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of "how did they do" that every screen, card, certificate and report reads
 * (phase-19-23 §6.10).
 *
 * **INV-23-1 is why this class exists.** A report, a certificate and a result card each computing
 * their own aggregate is three answers to one question, and the one printed on a certificate is the
 * one that cannot be corrected afterwards. §6.10 says `forStudent()` is *"the input
 * `CertificateService` uses, so the certificate grade and the result card can never disagree"* — so a
 * `SUM()` anywhere else is a defect, not a shortcut.
 *
 * **It reads snapshots, never live figures.** `exam_results.total_marks`, `percentage`, `grade` and
 * `is_passed` are the row's own columns (INV-20-1, INV-20-2), so an aggregate computed today and the
 * same aggregate computed next year, after the exam was re-scaled, are identical.
 *
 * **Published only.** `ExamStatus::ResultsPublished` is the single status in which a mark exists as
 * far as anybody outside the marking room is concerned (§3.2), and an unchecked sheet must never
 * decide whether somebody graduates.
 *
 * **Arithmetic is bcmath throughout.** A weighted average of decimal(8,2) marks under
 * decimal(8,4) weights is exactly the place a float loses a hundredth and a student lands one band
 * lower than they should.
 *
 * ---
 *
 * **`forBatch()` and `forCourse()` are deliberately absent.** The contract lists them here and their
 * only consumers are Phase 23's reports and charts. Building a return shape before anything reads it
 * is how the shape comes out wrong — and worse, how it comes out *plausible*, gets consumed, and then
 * has to be changed. They arrive with the screens that need them, reading this same class's
 * primitives rather than re-deriving anything.
 */
final class ExamStatisticsService
{
    /**
     * Only the scale service. `ResultCalculator` is deliberately **not** injected: it turns one mark
     * into one grade, and this class never grades anything — it reads grades that were already
     * written (INV-20-2) and bands an aggregate through `GradeScaleService::bandFor()`. Injecting it
     * would invite somebody to recompute a result here, which is the one thing INV-23-1 forbids.
     */
    public function __construct(
        private readonly GradeScaleService $scales,
    ) {}

    /**
     * Everything one enrolment's published exams add up to.
     *
     * The results come back oldest exam first, which is the order a card prints them in — a course
     * should read as a progression rather than as a reverse-chronological list.
     */
    public function forStudent(StudentBatchEnrollment $enrollment, ?string $mode = null): StudentExamStats
    {
        $results = $this->publishedResultsFor($enrollment);

        $majors = $this->majorExamsFor($enrollment);
        $passedMajors = 0;
        $missingMajors = 0;

        foreach ($majors as $exam) {
            $result = $results->first(
                static fn (ExamResult $r): bool => (int) $r->getAttribute('exam_id') === (int) $exam->getKey(),
            );

            if ($result === null) {
                // Published exam, no row for this student — they were not on the roster on the day,
                // or the sheet is incomplete. Either way it is not a pass and it is not a fail.
                $missingMajors++;

                continue;
            }

            if ((bool) $result->getAttribute('is_passed')) {
                $passedMajors++;
            }
        }

        return new StudentExamStats(
            results: $results,
            aggregate: $this->aggregateFor($enrollment, $mode ?? $this->defaultMode(), $results),
            majorsTotal: $majors->count(),
            majorsPassed: $passedMajors,
            majorsMissing: $missingMajors,
        );
    }

    /**
     * Exam statistics for one batch, or for every batch of a course (phase-19-23 6.22).
     *
     * **Aggregated from the counters `ExamService` maintains on each exam row**, never recounted
     * from `exam_results`. Those counters are written inside the transaction that enters, amends or
     * publishes a result, so they are the same numbers the exam's own page shows - and a chart that
     * recounted would be a second answer to "what was the pass rate", differing from the first
     * exactly when somebody had amended a mark.
     *
     * **Unpublished exams are excluded.** A pass rate computed from a sheet nobody has released is
     * a figure about a decision that has not been taken, and putting it on a dashboard would leak
     * results before the institute meant to.
     *
     * @param  array<string, mixed>  $filters  `course_id`, `batch_id`, `exam_type`, `teacher_id`
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forBatch(DateRange $range, array $filters = []): array
    {
        return $this->aggregateExams($range, $filters, 'batch');
    }

    /**
     * The same figures bucketed by month, for the trend chart (6.22 `examPassRateTrend`).
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forCourse(DateRange $range, array $filters = []): array
    {
        return $this->aggregateExams($range, $filters, 'month');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function aggregateExams(DateRange $range, array $filters, string $grouping): array
    {
        $query = DB::table('exams as e')
            ->leftJoin('batches as b', 'b.id', '=', 'e.batch_id')
            ->leftJoin('courses as c', 'c.id', '=', 'e.course_id')
            ->whereNull('e.deleted_at')
            ->whereNotNull('e.results_published_at')
            ->whereBetween('e.scheduled_date', [$range->start()->toDateString(), $range->end()->toDateString()]);

        foreach (['course_id' => 'e.course_id', 'batch_id' => 'e.batch_id', 'teacher_id' => 'e.teacher_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        if (! empty($filters['exam_type'])) {
            $query->whereIn('e.exam_type', (array) $filters['exam_type']);
        }

        $bucket = $grouping === 'month'
            ? "DATE_FORMAT(e.scheduled_date, '%Y-%m')"
            : 'COALESCE(b.name, c.name, CONCAT("Exam #", e.id))';

        $rows = [];
        $totals = ['exams' => 0, 'expected' => 0, 'appeared' => 0, 'absent' => 0, 'passed' => 0, 'failed' => 0];

        $results = $query
            ->selectRaw($bucket.' as bucket, e.exam_type as series')
            ->selectRaw(
                'COUNT(*) as exams, '
                .'COALESCE(SUM(e.expected_count), 0) as expected, '
                .'COALESCE(SUM(e.appeared_count), 0) as appeared, '
                .'COALESCE(SUM(e.absent_count), 0) as absent, '
                .'COALESCE(SUM(e.passed_count), 0) as passed, '
                .'COALESCE(SUM(e.failed_count), 0) as failed, '
                .'AVG(e.average_percentage) as average'
            )
            ->groupByRaw($bucket.', e.exam_type')
            ->orderByRaw($bucket)
            ->get();

        foreach ($results as $row) {
            $appeared = (int) $row->appeared;

            $rows[] = [
                'bucket' => (string) $row->bucket,
                'series' => (string) ($row->series ?? ''),
                'exams' => (int) $row->exams,
                'expected' => (int) $row->expected,
                'appeared' => $appeared,
                'absent' => (int) $row->absent,
                'passed' => (int) $row->passed,
                'failed' => (int) $row->failed,
                // Against those who sat it. A student who did not appear has not failed.
                'pass_rate' => $appeared > 0
                    ? Money::round(Money::mul(Money::div((string) $row->passed, (string) $appeared), '100'), 2)
                    : null,
                'average' => $row->average === null ? null : Money::round((string) $row->average, 2),
            ];

            foreach (['exams', 'expected', 'appeared', 'absent', 'passed', 'failed'] as $key) {
                $totals[$key] += (int) $row->{$key};
            }
        }

        $totals['pass_rate'] = $totals['appeared'] > 0
            ? Money::round(Money::mul(Money::div((string) $totals['passed'], (string) $totals['appeared']), '100'), 2)
            : null;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * The overall grade, resolved the way the institute has asked for.
     *
     * | mode | what it means |
     * |---|---|
     * | `final_exam` | the most recent published **final**, falling back to the most recent major |
     * | `best_exam` | the highest percentage of any published major |
     * | `weighted_average` | every published major, weighted by `exams.weight_percentage` |
     * | `manual` | nothing is computed — whoever issues the certificate types it |
     *
     * **`weighted_average` over majors only**, matching `certificate_require_pass`: a weekly test
     * that does not gate a certificate should not drag its grade down either.
     *
     * **An exam with no weight is weighted equally with its peers**, which is the contract's rule and
     * the only sensible reading of a nullable column — treating null as zero would silently drop an
     * exam from the average, and treating it as 100 would swamp everything else.
     *
     * @param  Collection<int, ExamResult>|null  $results  pass them in to avoid a second query
     */
    public function aggregateFor(
        StudentBatchEnrollment $enrollment,
        string $mode,
        ?Collection $results = null,
    ): AggregateGrade {
        if ($mode === 'manual') {
            // Nothing to compute, and saying so is different from computing a zero.
            return AggregateGrade::none($mode);
        }

        $results ??= $this->publishedResultsFor($enrollment);

        // Majors that actually carry a percentage. An absence has none, and averaging it as zero
        // would make a missed midterm indistinguishable from one sat and failed.
        $majorIds = $this->majorExamsFor($enrollment)->map(
            static fn (Exam $e): int => (int) $e->getKey(),
        )->all();

        $counted = $results->filter(static fn (ExamResult $r): bool => in_array((int) $r->getAttribute('exam_id'), $majorIds, true)
            && $r->getAttribute('percentage') !== null);

        if ($counted->isEmpty()) {
            return AggregateGrade::none($mode);
        }

        $percentage = match ($mode) {
            'final_exam' => $this->finalExamPercentage($counted, $enrollment),
            'best_exam' => $this->bestPercentage($counted),
            default => $this->weightedAverage($counted),
        };

        if ($percentage === null) {
            return AggregateGrade::none($mode);
        }

        $scale = $this->scaleFor($enrollment, $counted);
        $band = $scale === null ? null : $this->scales->bandFor($scale, $percentage);

        return AggregateGrade::fromBand($mode, $percentage, $band, $counted->count());
    }

    /**
     * The headline figures for one exam — read straight off the row's caches.
     *
     * They are re-derived by `ExamResultService` on every sheet save, verify and amend, so this
     * re-reads rather than recounting: a report that recounted could disagree with the exam screen,
     * and the exam screen is the one somebody is looking at while they ask.
     *
     * @return array<string, mixed>
     */
    public function forExam(Exam $exam): array
    {
        $appeared = (int) $exam->getAttribute('appeared_count');
        $passed = (int) $exam->getAttribute('passed_count');

        return [
            'expected' => (int) $exam->getAttribute('expected_count'),
            'entered' => (int) $exam->getAttribute('results_entered_count'),
            'appeared' => $appeared,
            'absent' => (int) $exam->getAttribute('absent_count'),
            'passed' => $passed,
            'failed' => (int) $exam->getAttribute('failed_count'),
            // Null rather than zero when nobody sat it: a pass rate over no candidates is not 0%.
            'pass_rate' => $appeared > 0
                ? Money::round(Money::percentageOf((string) $passed, (string) $appeared), 2)
                : null,
            'highest' => $exam->getAttribute('highest_marks'),
            'lowest' => $exam->getAttribute('lowest_marks'),
            'average' => $exam->getAttribute('average_marks'),
            'average_percentage' => $exam->getAttribute('average_percentage'),
        ];
    }

    // ===========================================================================================

    /** @return Collection<int, ExamResult> */
    private function publishedResultsFor(StudentBatchEnrollment $enrollment): Collection
    {
        return ExamResult::query()
            ->where('student_batch_enrollment_id', $enrollment->getKey())
            ->published()
            ->with(['exam:id,name,exam_type,scheduled_date,weight_percentage,total_marks', 'band', 'scale'])
            ->get()
            ->sortBy(static fn (ExamResult $r): string => sprintf(
                '%s-%010d',
                (string) ($r->exam?->getAttribute('scheduled_date') ?? '9999-12-31'),
                (int) $r->getAttribute('exam_id'),
            ))
            ->values();
    }

    /**
     * Published major exams for this enrolment's batch.
     *
     * Asked of the **batch**, not of the student's results, so an exam the student has no row for
     * still counts towards `majorsTotal` — which is what lets `passedEveryMajor()` distinguish
     * "passed them all" from "never sat one".
     *
     * @return Collection<int, Exam>
     */
    private function majorExamsFor(StudentBatchEnrollment $enrollment): Collection
    {
        $majorTypes = array_map(
            static fn (ExamType $type): string => $type->value,
            array_values(array_filter(ExamType::cases(), static fn (ExamType $t): bool => $t->isMajor())),
        );

        return Exam::query()
            ->where('batch_id', $enrollment->getAttribute('batch_id'))
            ->where('status', ExamStatus::ResultsPublished->value)
            ->whereIn('exam_type', $majorTypes)
            ->orderBy('scheduled_date')
            ->get(['id', 'exam_type', 'scheduled_date', 'weight_percentage', 'total_marks']);
    }

    /**
     * The most recent published final, or — when the course has none — the most recent major.
     *
     * The fallback matters: an institute that sets `final_exam` and then runs a course assessed by
     * two midterms would otherwise get no grade at all and no explanation why.
     *
     * @param  Collection<int, ExamResult>  $counted
     */
    private function finalExamPercentage(Collection $counted, StudentBatchEnrollment $enrollment): ?string
    {
        $finals = $counted->filter(
            static fn (ExamResult $r): bool => $r->exam?->exam_type === ExamType::Final,
        );

        $chosen = ($finals->isNotEmpty() ? $finals : $counted)->last();

        return $chosen === null ? null : (string) $chosen->getAttribute('percentage');
    }

    /** @param  Collection<int, ExamResult>  $counted */
    private function bestPercentage(Collection $counted): ?string
    {
        $best = null;

        foreach ($counted as $result) {
            $percentage = (string) $result->getAttribute('percentage');

            if ($best === null || Money::compare($percentage, $best) > 0) {
                $best = $percentage;
            }
        }

        return $best;
    }

    /**
     * Sum(percentage × weight) ÷ sum(weight), in bcmath at four decimals.
     *
     * An exam with no weight takes `1`, so a set where every weight is null becomes a plain mean —
     * which is what "weighted equally" means and is exactly the common case.
     *
     * @param  Collection<int, ExamResult>  $counted
     */
    private function weightedAverage(Collection $counted): ?string
    {
        $weighted = '0.0000';
        $weights = '0.0000';

        foreach ($counted as $result) {
            $weight = $result->exam?->getAttribute('weight_percentage');
            // Null, and zero, both mean "no weight stated". A stored 0 would otherwise erase the
            // exam from the average while looking like a deliberate figure.
            $weight = ($weight === null || Money::compare((string) $weight, '0.0000') === 0)
                ? '1.0000'
                : (string) $weight;

            $weighted = Money::sum(
                $weighted,
                Money::round(bcmul((string) $result->getAttribute('percentage'), $weight, 8), 4),
            );
            $weights = Money::sum($weights, $weight);
        }

        if (Money::compare($weights, '0.0000') === 0) {
            return null;
        }

        // `bcdiv` + `Money::round(…, 4)` rather than `Money::div()`. **`Money::div` rounds its result
        // to `Money::SCALE`, which is two** — it is the money surface, and money has two decimals.
        // A percentage is decimal(8,4) and banding happens at four, so routing this through it would
        // throw away the two decimals that decide which band an aggregate lands in. It also takes
        // exactly two arguments; a third is silently ignored, which is how a `, 4` can sit in a call
        // for a year looking like it does something.
        return Money::round(bcdiv($weighted, $weights, 8), 4);
    }

    /**
     * The scale to band the aggregate against: whichever the counted results were graded on.
     *
     * Taken from the **results**, not from the institute's current default, so an aggregate is banded
     * against the ladder the marks were actually given under (INV-20-4). A course whose exams used
     * two different scales has no single answer, so the most recent one wins and the caller sees the
     * band it produced — which is at least reproducible.
     *
     * @param  Collection<int, ExamResult>  $counted
     */
    private function scaleFor(StudentBatchEnrollment $enrollment, Collection $counted): ?GradeScale
    {
        $last = $counted->last();
        $scale = $last?->scale;

        if ($scale instanceof GradeScale) {
            return $scale;
        }

        $scaleId = $last?->getAttribute('grade_scale_id');

        return $scaleId === null ? null : GradeScale::query()->find($scaleId);
    }

    /** `institute.certificate_grade_source`, with the contract's default. */
    private function defaultMode(): string
    {
        return (string) setting('institute.certificate_grade_source', 'weighted_average');
    }
}
