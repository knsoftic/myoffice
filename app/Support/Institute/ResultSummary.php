<?php

declare(strict_types=1);

namespace App\Support\Institute;

use App\Models\Institute\ExamResult;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * "How did this student do, over these exams?" — asked once, in one place (phase-19-23 §6.11).
 *
 * The student panel asks it for a summary strip, the admin card asks it for a printed total, and the
 * result card asks it per enrolment. Three callers, and D107 is the standing warning about what
 * happens when three callers each answer a question their own way: the numbers disagree, and the one
 * that is wrong is whichever the reader is looking at.
 *
 * **Two rules live here rather than in the callers.**
 *
 * An exemption is not a zero. A student excused an exam is not counted in the denominator, because
 * dividing by a paper they were told not to sit turns an institute's decision into their failure.
 * `ExamAttendanceStatus::countsInDenominator()` is the authority, and this class only obeys it.
 *
 * A percentage over nothing is **null, not zero**. Zero per cent reads as total failure; no exams sat
 * is not failure, and the two must not print the same. Every caller renders null as a dash.
 *
 * **The percentage and the grade-point average treat an absence differently, on purpose.** An absence
 * counts in the denominator, so it pulls the percentage down exactly as a zero would — which is what
 * missing an exam means. It carries no `grade_point`, because no band was ever assigned to it, so it
 * is left out of the average rather than counted as a 0.0. Inventing a grade point for a paper nobody
 * marked would put a number on a transcript that no scale produced. The two figures therefore answer
 * two different questions, and a card showing both should say which is which.
 */
final class ResultSummary
{
    /**
     * @param  iterable<int, ExamResult>  $results
     * @return array{count: int, counted: int, passed: int, obtained: string, total: string, percentage: ?string, gradePoints: ?string}
     */
    public static function of(iterable $results): array
    {
        $obtained = '0.00';
        $total = '0.00';
        $points = '0.00';
        $pointed = 0;
        $counted = 0;
        $passed = 0;
        $all = 0;

        foreach ($results as $result) {
            $all++;

            if (! $result->attendance_status->countsInDenominator()) {
                continue;
            }

            $counted++;
            $obtained = Money::sum($obtained, (string) ($result->getAttribute('obtained_marks') ?? '0.00'));
            $total = Money::sum($total, (string) $result->getAttribute('total_marks'));

            if ((bool) $result->getAttribute('is_passed')) {
                $passed++;
            }

            $point = $result->getAttribute('grade_point');

            if ($point !== null) {
                $points = Money::sum($points, (string) $point);
                $pointed++;
            }
        }

        return [
            'count' => $all,
            'counted' => $counted,
            'passed' => $passed,
            'obtained' => $obtained,
            'total' => $total,
            'percentage' => Money::compare($total, '0.00') > 0
                ? Money::percentageOf($obtained, $total)
                : null,
            // Averaged over the results that actually carry a grade point, not over every result: a
            // scale without GPA would otherwise drag a mixed transcript's average towards zero.
            'gradePoints' => $pointed > 0
                ? Money::round(Money::div($points, (string) $pointed), 2)
                : null,
        ];
    }

    /**
     * The same figures for a `Collection`, in the order a card prints them — oldest exam first, so a
     * course reads as a progression rather than as a reverse-chronological list.
     *
     * @param  Collection<int, ExamResult>  $results
     * @return Collection<int, ExamResult>
     */
    public static function inPrintOrder(Collection $results): Collection
    {
        return $results
            ->sortBy(static fn (ExamResult $r): string => sprintf(
                '%s-%010d',
                (string) ($r->exam?->getAttribute('scheduled_date') ?? '9999-12-31'),
                (int) ($r->getAttribute('exam_id') ?? 0),
            ))
            ->values();
    }
}
