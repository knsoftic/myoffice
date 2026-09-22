<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\InstallmentLine;
use App\DataObjects\Institute\PlanRedistribution;
use App\Enums\InstallmentInterval;
use App\Enums\RemainderPlacement;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The arithmetic of an installment plan (phase-18 §6.2). Pure: no database, no clock, no settings.
 *
 * **INVARIANT P-1 — the lines sum to the net fee exactly, and that is asserted, not hoped for.**
 * It holds by construction rather than by a final fix-up: the work happens in integer paisa
 * (`Money::toMinor`), so `30,000.00 / 7` is `3,000,000 / 7 = 428,571 remainder 3` — an integer quotient
 * and an integer remainder of at most `count - 1`. There is no repeating decimal to round and therefore
 * no rounding drift to chase. `Money::distribute()` hands the leftover paisa out one at a time where
 * `RemainderPlacement` says, and that function is the only thing in the system allowed to split money
 * (F-4.11). The assertion at the end of `generate()` exists because a future edit could still break it,
 * and a plan that is one paisa short is a charge that can never reach `paid`.
 *
 * **`total >= count` is a refusal, not a clamp.** `Money::distribute('0.04', 5)` cheerfully returns
 * `0.00 0.01 0.01 0.01 0.01` — a perfectly exact split containing a zero line. A zero installment is a
 * row the student can never pay and `chk_sfi_amount` (`amount > 0`) would reject anyway, so the
 * calculator refuses with a sentence naming the two numbers rather than letting the database refuse
 * with a constraint name.
 *
 * **Redistribution runs latest-first and never touches a paid line.** A discount shrinks what is still
 * owed, and what is still owed is the far end of the schedule: taking it off the next line due would
 * change a payment the student may already have arranged. Anything no live unpaid line can absorb is
 * left unconsumed and becomes an advance on the charge — money already received is evidence, and
 * reaching into it to balance an equation is how a ledger stops being one.
 */
final class InstallmentPlanCalculator
{
    /** Spine §2.3 and §6.1: a plan is between two and sixty lines. */
    public const MIN_LINES = 2;

    public const MAX_LINES = 60;

    /**
     * Build a fresh plan for `$netAmount`.
     *
     * @param  int  $startNumber  the first `installment_no`; a rebuild passes `MAX + 1` ([D18-6], D50)
     * @param  list<CarbonInterface>|null  $customDates  required for `InstallmentInterval::Custom`
     * @return list<InstallmentLine>
     *
     * @throws FeeRuleException
     */
    public function generate(
        string $netAmount,
        int $count,
        CarbonInterface $firstDueOn,
        InstallmentInterval $interval,
        RemainderPlacement $placement = RemainderPlacement::Last,
        int $startNumber = 1,
        ?array $customDates = null,
    ): array {
        $this->assertCount($count);

        $net = Money::of($netAmount);

        if (Money::compare($net, Money::ZERO) <= 0) {
            throw FeeRuleException::nothingToSplit();
        }

        // The guard that stops a zero line reaching `chk_sfi_amount`. In paisa, because "is there at
        // least one paisa per line" is an integer question and asking it in decimals invites a float.
        $minor = Money::toMinor($net);

        if (bccomp($minor, (string) $count, 0) < 0) {
            throw FeeRuleException::planTooSmall($net, $count, $minor);
        }

        $amounts = Money::distribute($net, $count, $placement);
        $dates = $this->dates($count, $firstDueOn, $interval, $customDates);

        $lines = [];

        foreach ($amounts as $index => $amount) {
            $lines[] = new InstallmentLine($startNumber + $index, $amount, $dates[$index]);
        }

        $this->assertSumsTo($lines, $net);

        return $lines;
    }

    /**
     * Apply a signed change in net to a live plan (§6.2 "Redistribution", §6.3.3).
     *
     * `$liveUnpaidLines` is exactly what its name says: lines that are neither cancelled, waived, paid
     * nor partially paid. The caller filters; this function does not guess, because "unpaid" is a
     * question about receipts and receipts are not in scope here.
     *
     * @param  list<InstallmentLine>  $liveUnpaidLines
     */
    public function redistribute(array $liveUnpaidLines, string $signedDelta): PlanRedistribution
    {
        $delta = Money::of($signedDelta);

        if (Money::isZero($delta)) {
            return new PlanRedistribution;
        }

        // Latest due date first: a discount comes off the far end of the schedule, not off the payment
        // the student has already arranged for next week.
        $ordered = $liveUnpaidLines;
        usort($ordered, static fn (InstallmentLine $a, InstallmentLine $b): int => $b->dueDate <=> $a->dueDate
            ?: $b->number <=> $a->number);

        return Money::isNegative($delta)
            ? $this->shrink($ordered, $delta)
            : $this->grow($ordered, $delta);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<InstallmentLine>  $ordered  latest due first
     */
    private function shrink(array $ordered, string $delta): PlanRedistribution
    {
        // Work in paisa on the magnitude, so "how much is left to take off" is an integer countdown.
        $remaining = Money::toMinor(Money::abs($delta));
        $amounts = [];
        $cancel = [];

        foreach ($ordered as $line) {
            if (bccomp($remaining, '0', 0) <= 0) {
                break;
            }

            $available = Money::toMinor($line->amount);
            $take = bccomp($available, $remaining, 0) <= 0 ? $available : $remaining;
            $left = bcsub($available, $take, 0);
            $remaining = bcsub($remaining, $take, 0);

            if ($line->id === null) {
                continue;
            }

            // A line reduced to nothing is cancelled rather than kept at 0.00: `chk_sfi_amount`
            // forbids a zero amount, and a cancelled line still shows in the schedule with its number,
            // which is what keeps "installment 3" meaning one thing (D50).
            bccomp($left, '0', 0) === 0
                ? $cancel[] = $line->id
                : $amounts[$line->id] = Money::fromMinor($left);
        }

        return new PlanRedistribution(
            amounts: $amounts,
            cancel: $cancel,
            // Negative: what is left over reduces the charge below what was already received, which is
            // an advance. It is never pushed onto a paid line.
            unconsumed: bccomp($remaining, '0', 0) > 0 ? Money::negate(Money::fromMinor($remaining)) : '0.00',
        );
    }

    /**
     * @param  list<InstallmentLine>  $ordered  latest due first
     */
    private function grow(array $ordered, string $delta): PlanRedistribution
    {
        foreach ($ordered as $line) {
            if ($line->id === null) {
                continue;
            }

            // The whole increase lands on the latest live unpaid line. Spreading it would change several
            // amounts a student has already been told, to no benefit.
            return new PlanRedistribution(
                amounts: [$line->id => Money::add($line->amount, $delta)],
            );
        }

        // Nothing live left to grow — a correction after the plan was fully consumed. It becomes a new
        // line, which the service numbers `MAX + 1`.
        return new PlanRedistribution(newLineAmount: $delta);
    }

    /**
     * @param  list<CarbonInterface>|null  $customDates
     * @return list<CarbonImmutable>
     *
     * @throws FeeRuleException
     */
    private function dates(
        int $count,
        CarbonInterface $firstDueOn,
        InstallmentInterval $interval,
        ?array $customDates,
    ): array {
        if ($interval->isRegular()) {
            $first = CarbonImmutable::parse($firstDueOn->toDateString());

            return array_map(
                static fn (int $n): CarbonImmutable => CarbonImmutable::parse(
                    $interval->addTo($first, $n)->toDateString(),
                ),
                range(0, $count - 1),
            );
        }

        if ($customDates === null || count($customDates) !== $count) {
            throw FeeRuleException::customDateCount($count);
        }

        $dates = array_map(
            static fn (CarbonInterface $date): CarbonImmutable => CarbonImmutable::parse($date->toDateString()),
            array_values($customDates),
        );

        // Strictly ascending: two lines due the same day are one line the student will pay once, and
        // a plan that goes backwards makes "the next installment" unanswerable.
        foreach ($dates as $index => $date) {
            if ($index > 0 && $date->lessThanOrEqualTo($dates[$index - 1])) {
                throw FeeRuleException::dueDatesMustAscend(
                    $index,
                    $date->toDateString(),
                    $dates[$index - 1]->toDateString(),
                );
            }
        }

        return $dates;
    }

    private function assertCount(int $count): void
    {
        if ($count < self::MIN_LINES || $count > self::MAX_LINES) {
            throw FeeRuleException::planLineCount(self::MIN_LINES, self::MAX_LINES);
        }
    }

    /**
     * INVARIANT P-1, in code.
     *
     * @param  list<InstallmentLine>  $lines
     */
    private function assertSumsTo(array $lines, string $net): void
    {
        $sum = Money::sum(array_map(static fn (InstallmentLine $l): string => $l->amount, $lines));

        if (Money::compare($sum, $net) !== 0) {
            throw new LogicException(sprintf(
                'InstallmentPlanCalculator produced lines summing to %s against a net fee of %s. P-1 is '
                .'broken; nothing may be written.',
                $sum,
                $net,
            ));
        }

        foreach ($lines as $line) {
            if (Money::compare($line->amount, Money::ZERO) <= 0) {
                throw new LogicException(sprintf(
                    'Installment %d came out as %s. A zero or negative line is not payable.',
                    $line->number,
                    $line->amount,
                ));
            }
        }
    }
}
