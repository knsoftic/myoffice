<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\ResultOutcome;
use App\Enums\ExamAttendanceStatus;
use App\Models\Institute\GradeScale;
use App\Models\Institute\GradeScaleBand;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What one exam result is worth (phase-19-23 §6.10, INV-20-2).
 *
 * **Pure and dependency-free.** It takes marks, a total and a scale's bands, and returns a value object.
 * It reads no setting, touches no row and opens no transaction — which is what lets a test run it over
 * a hundred cases without a database, and what makes INV-20-2's claim ("written **only** by
 * `ResultCalculator`") checkable rather than aspirational.
 *
 * **An absence is not a zero, and this is the first place that matters.** A student who did not sit the
 * exam has `obtained_marks = null`, not `0.00` — so they have no percentage and no grade, and
 * `chk_er_absent` says the same thing at the database. Writing a zero would drag the class average down
 * with a number nobody earned and hand the student a grade for an exam they never took.
 *
 * **The band is matched on the rounded percentage.** §6.8's convention is half-up at two decimals, and
 * band edges step by 0.01 — so a 2dp percentage always falls inside exactly one band, and the 0.01 gap
 * between `39.99` and `40.00` contains no representable value. Matching on an unrounded ratio would put
 * `39.995` in the gap and leave a result with no grade at all.
 */
final class ResultCalculator
{
    /** Marks are `decimal(8,2)`; percentages are `decimal(8,4)` stored at the contract's 2. */
    private const SCALE = 2;

    /**
     * @param  Collection<int, GradeScaleBand>|list<GradeScaleBand>  $bands  the scale's bands, any order
     */
    public function calculate(
        ExamAttendanceStatus $attendance,
        ?string $obtainedMarks,
        string $totalMarks,
        iterable $bands,
        ?string $passingMarks = null,
        ?string $scalePassPercentage = null,
    ): ResultOutcome {
        // An absence, an exemption or a debarment carries no marks — so it carries no percentage and
        // no grade either. `countsAsFail()` is what the class figures read; it is not a mark of zero.
        if (! $attendance->requiresMarks() || $obtainedMarks === null) {
            return ResultOutcome::notAssessed($attendance);
        }

        $obtained = Money::clamp($obtainedMarks, '0.00', $totalMarks, self::SCALE);
        $percentage = $this->percentageOf($obtained, $totalMarks);

        $band = $percentage === null ? null : $this->bandFor($bands, $percentage);

        return new ResultOutcome(
            attendance: $attendance,
            obtainedMarks: $obtained,
            percentage: $percentage,
            grade: $band?->getAttribute('grade'),
            gradePoint: $band?->getAttribute('grade_point') === null ? null : (string) $band->getAttribute('grade_point'),
            gradeScaleBandId: $band === null ? null : (int) $band->getKey(),
            isPassed: $this->decidePass($obtained, $percentage, $band, $passingMarks, $scalePassPercentage),
        );
    }

    /**
     * `obtained / total × 100`, half away from zero at two decimals.
     *
     * Null when the total is zero, which `chk_er_total` forbids — handled anyway because the one code
     * path this runs in is a marker halfway through a sheet of thirty, and "no percentage" is a better
     * outcome for them than a division by zero.
     */
    public function percentageOf(string $obtainedMarks, string $totalMarks): ?string
    {
        if (Money::compare($totalMarks, '0.00') <= 0) {
            return null;
        }

        return Money::clamp(
            Money::percentageOf($obtainedMarks, $totalMarks, self::SCALE),
            '0.00',
            '100.00',
            self::SCALE,
        );
    }

    /**
     * The band a percentage falls in — **bcmath comparison, never a float `<=`**.
     *
     * `0.1 + 0.2 !== 0.3` in a float, and a band boundary is exactly where that bites: a student on
     * 79.99999999 would be handed the grade above. The bands are compared as decimal strings, so a
     * boundary is a boundary.
     *
     * @param  Collection<int, GradeScaleBand>|list<GradeScaleBand>  $bands
     */
    public function bandFor(iterable $bands, string $percentage): ?GradeScaleBand
    {
        foreach ($bands as $band) {
            $min = (string) $band->getAttribute('min_percentage');
            $max = (string) $band->getAttribute('max_percentage');

            if (Money::compare($percentage, $min) >= 0 && Money::compare($percentage, $max) <= 0) {
                return $band;
            }
        }

        // INV-20-3 says a valid scale covers 0–100 with no gaps, so this is unreachable for one that
        // passed validation. It returns null rather than throwing because a marker mid-sheet should
        // see a result with no grade and a warning, not a 500.
        return null;
    }

    /**
     * Passed or not, in the order the contract sets out.
     *
     * **[D-20-2] The exam's own `passing_marks` wins over the band's `is_pass`.** §81 makes passing
     * marks an exam-level field, and a coordinator who types 33 out of 100 means 33 — a scale's 40%
     * must not quietly move the line they set.
     *
     * **The test is `> 0`, not "is present".** `exams.passing_marks` is NOT NULL, so a paper with no
     * pass line carries `0.00` rather than null — and `obtained >= 0` is true for everybody, so
     * treating a zero as a real pass line would pass the whole class including the student who scored
     * nothing. A zero means "this paper has no pass line", and the scale's own line answers instead.
     */
    private function decidePass(
        string $obtained,
        ?string $percentage,
        ?GradeScaleBand $band,
        ?string $passingMarks,
        ?string $scalePassPercentage,
    ): ?bool {
        if ($passingMarks !== null && $passingMarks !== '' && Money::compare($passingMarks, '0.00') > 0) {
            return Money::compare($obtained, $passingMarks) >= 0;
        }

        // §2.9: the scale's `pass_percentage` is what an exam with no pass line of its own falls back
        // to, before the band's own flag.
        if ($percentage !== null && $scalePassPercentage !== null && $scalePassPercentage !== ''
            && Money::compare($scalePassPercentage, '0.00') > 0) {
            return Money::compare($percentage, $scalePassPercentage) >= 0;
        }

        return $band === null ? null : (bool) $band->getAttribute('is_pass');
    }

    /**
     * Ranks within one exam. **Ties share a rank and the next rank skips** (§6.10) — three students on
     * 88% are all 2nd and the next is 5th, which is what a reader expects from a position and what a
     * dense rank would quietly get wrong.
     *
     * **Only students who appeared are ranked.** An absence has no percentage, so it is skipped here
     * and its `position_in_batch` stays null: a student who did not sit the paper did not come last
     * in it.
     *
     * @param  iterable<array-key, array{id: int, percentage: string|null}>  $rows
     * @return array<int, int> result id => position
     */
    public function positions(iterable $rows): array
    {
        $ranked = [];

        foreach ($rows as $row) {
            if (($row['percentage'] ?? null) === null) {
                continue;
            }

            $ranked[] = ['id' => (int) $row['id'], 'percentage' => (string) $row['percentage']];
        }

        usort($ranked, static fn (array $a, array $b): int => Money::compare($b['percentage'], $a['percentage']));

        $positions = [];
        $position = 0;
        $seen = 0;
        $previous = null;

        foreach ($ranked as $row) {
            $seen++;

            if ($previous === null || Money::compare($row['percentage'], $previous) !== 0) {
                $position = $seen;
                $previous = $row['percentage'];
            }

            $positions[$row['id']] = $position;
        }

        return $positions;
    }

    /** The scale's own pass line, for a caller that has the scale but not its number to hand. */
    public function passPercentageOf(?GradeScale $scale): ?string
    {
        return $scale === null ? null : (string) $scale->getAttribute('pass_percentage');
    }
}
