<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\GradeOutcome;
use App\Support\Money;

/**
 * What one submission is worth, once lateness has been paid for (phase-19-23 §6.8, F-7.1).
 *
 * **Pure and dependency-free on purpose.** It takes strings and returns a value object; it reads no
 * setting, touches no model and opens no transaction. That is what lets PH19-33 run it over forty
 * randomised cases and compare the answer, byte for byte, with what MariaDB computed in the
 * `final_marks` generated column — a comparison that would prove nothing if the calculator could
 * consult anything the database cannot.
 *
 * **Every step goes through `Money`.** `CLAUDE.md` §4 forbids `*`, `/` and `+` on money, and marks are
 * money-shaped for the same reason: `0.1 + 0.2` is not `0.3` in a float, and a mark landing a hundredth
 * below a pass line decides whether somebody passed. `Money` also owns the rounding rule — half away
 * from zero — which plain bcmath does not do: `bcadd($x, '0', 2)` truncates, so `33.335` would become
 * `33.33` and the contract asks for `33.34`.
 *
 * **A penalty can never create a negative mark, and never exceeds what was earned.** `min(penalty,
 * obtained)` is the whole of it. Without the clamp a 100 % late penalty on 3 marks out of 50 would give
 * −47, which `chk_asub_marks` would refuse and which nobody could explain to the student either.
 *
 * **`finalMarks` here must equal the generated column**, `GREATEST(obtained - COALESCE(penalty, 0), 0)`.
 * The clamp above makes that `GREATEST` redundant rather than contradictory — two independent guards
 * that agree, so neither becomes the only one anybody relies on.
 */
final class AssignmentGradeCalculator
{
    /** Marks are `decimal(8,2)`. */
    private const MARK_SCALE = 2;

    /**
     * `percentage` is `decimal(8,4)`, but §6.8's contract is explicitly half-up **at two** and PH19-33
     * pins it, so the value is computed at 2 and stored in the wider column unpadded.
     */
    private const PERCENTAGE_SCALE = 2;

    /**
     * @param  string  $obtainedMarks  what the teacher awarded, before any penalty
     * @param  string  $totalMarks  the snapshot on the submission row, never the assignment's current value
     * @param  string  $latePenaltyPercentage  `decimal(8,4)` — percent of `totalMarks`, charged once
     * @param  bool  $isLate  decided at insert and never recomputed (INV-19-7)
     * @param  string|null  $passingMarks  null = no pass line on this assignment, so `isPassed` is null
     */
    public function calculate(
        string $obtainedMarks,
        string $totalMarks,
        string $latePenaltyPercentage = '0.0000',
        bool $isLate = false,
        ?string $passingMarks = null,
    ): GradeOutcome {
        // The ceiling is asserted in three places (INV-19-6) — the Form Request, the service and
        // `chk_asub_marks`. Clamping here as well means an out-of-range value reaching the calculator
        // through some fourth path still produces an answer the database would accept.
        $obtained = Money::clamp($obtainedMarks, '0.00', $totalMarks, self::MARK_SCALE);

        $penalty = $this->penaltyFor($obtained, $totalMarks, $latePenaltyPercentage, $isLate);
        $final = Money::clamp(Money::sub($obtained, $penalty), '0.00', $totalMarks, self::MARK_SCALE);

        return new GradeOutcome(
            obtainedMarks: $obtained,
            penaltyMarks: $penalty,
            finalMarks: $final,
            percentage: $this->percentageOf($final, $totalMarks),
            isPassed: $passingMarks === null ? null : Money::compare($final, $passingMarks) >= 0,
        );
    }

    /**
     * The deduction actually charged: zero when the work was on time or the assignment charges nothing,
     * and never more than the marks earned — so subtracting it cannot go below zero.
     */
    public function penaltyFor(string $obtainedMarks, string $totalMarks, string $latePenaltyPercentage, bool $isLate): string
    {
        if (! $isLate || Money::compare($latePenaltyPercentage, '0.0000') <= 0) {
            return '0.00';
        }

        $charge = Money::round(Money::percentage($totalMarks, $latePenaltyPercentage), self::MARK_SCALE);

        return Money::min($charge, Money::round($obtainedMarks, self::MARK_SCALE));
    }

    /**
     * `final / total × 100`, half away from zero at two decimals.
     *
     * Null when the total is zero — which `chk_asub_total` forbids, so it should be unreachable. It is
     * handled anyway because `Money::percentageOf()` throws on a zero whole, and the one code path this
     * runs in is a teacher looking at a grading grid: "no percentage" is a better answer than a 500.
     */
    public function percentageOf(string $finalMarks, string $totalMarks): ?string
    {
        if (Money::compare($totalMarks, '0.00') <= 0) {
            return null;
        }

        return Money::clamp(
            Money::percentageOf($finalMarks, $totalMarks, self::PERCENTAGE_SCALE),
            '0.00',
            '100.00',
            self::PERCENTAGE_SCALE,
        );
    }
}
