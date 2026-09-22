<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

use App\Support\Money;

/**
 * A fee rule refused the act — a 422 with the message on the named field (phase-18 §6).
 *
 * These hold whatever the caller's permissions, Super Admin included, because every one of them is a
 * statement about money rather than about authority: a plan whose lines do not sum to the net fee, a
 * discount that would take net below zero, a charge that already holds a receipt. The messages carry
 * the two numbers that disagree, because the person reading one is being asked to fix an amount and
 * "invalid installment plan" does not tell them which.
 *
 * Separate from `CourseRuleException` only so a caller can tell a money refusal from a catalogue one.
 */
class FeeRuleException extends CourseRuleException
{
    public static function planTooSmall(string $net, int $count, string $minorUnits): self
    {
        return self::refuse('installment_count', sprintf(
            'A net fee of %s cannot be split into %d installments — that is less than one paisa each. '
            .'Use %s installments or fewer.',
            Money::format($net),
            $count,
            $minorUnits,
        ));
    }

    public static function planLineCount(int $min, int $max): self
    {
        return self::refuse('installment_count', sprintf(
            'An installment plan has between %d and %d lines. A single line is not a plan — the charge '
            .'is already payable in full.',
            $min,
            $max,
        ));
    }

    public static function nothingToSplit(): self
    {
        return self::refuse('amount',
            'This charge has nothing left to collect, so there is nothing to split into installments.');
    }

    public static function customDateCount(int $count): self
    {
        return self::refuse('due_dates', sprintf(
            'A custom plan needs exactly %d due dates, one for each installment.',
            $count,
        ));
    }

    public static function dueDatesMustAscend(int $index, string $date, string $previous): self
    {
        return self::refuse('due_dates', sprintf(
            'Installment %d is due on %s, which is not after installment %d (%s). Due dates must move '
            .'forward — two lines due the same day are one line the student will pay once.',
            $index + 1,
            $date,
            $index,
            $previous,
        ));
    }

    /**
     * PI-1, refused at the door rather than after the write (§6.3).
     */
    public static function planSumMismatch(string $sum, string $net): self
    {
        return self::refuse('installments', sprintf(
            'The installments add up to %s but the net fee is %s — a difference of %s. A plan that does '
            .'not sum to the fee leaves a charge that can never reach paid.',
            Money::format($sum),
            Money::format($net),
            Money::format(Money::abs(Money::sub($sum, $net))),
        ));
    }

    /**
     * [D18-4] — see phase-18 R-2 for why this is a refusal rather than a plan over the remainder.
     */
    public static function planAfterMoney(string $received): self
    {
        return self::refuse('installments', sprintf(
            'This charge has already received %s, so a plan cannot be built on it. Splitting a fee that '
            .'is part paid would either break the rule that the lines sum to the net fee, or move an '
            .'existing receipt onto a line it was never paid against. Collect the remainder as it comes, '
            .'or void the receipt and start again.',
            Money::format($received),
        ));
    }

    public static function discountExceedsFee(string $discount, string $scholarship, string $gross): self
    {
        return self::refuse('amount', sprintf(
            'Discount %s plus scholarship %s comes to more than the gross fee of %s. A charge cannot be '
            .'reduced below zero — if the student owes nothing, the remaining heads are what to reduce.',
            Money::format($discount),
            Money::format($scholarship),
            Money::format($gross),
        ));
    }

    public static function reasonRequiredFor(string $act): self
    {
        return self::reasonRequired('reason', sprintf(
            'Say why: %s changes what a student owes, and the reason is what the figure is explained by '
            .'when somebody asks about it months later.',
            $act,
        ));
    }

    public static function chargeHoldsMoney(string $act, string $received): self
    {
        return self::refuse('status', sprintf(
            'This charge holds %s in receipts, so it cannot be %s. Refund or void the money first — a '
            .'receipt is evidence that cash changed hands and it does not disappear with the charge.',
            Money::format($received),
            $act,
        ));
    }

    public static function chargeIsClosed(string $status): self
    {
        return self::refuse('status', sprintf(
            'This charge is %s, so nothing more can be recorded against it.',
            mb_strtolower($status),
        ));
    }

    public static function waiverExceedsLine(string $asked, string $remaining, int $number): self
    {
        return self::refuse('amount', sprintf(
            'Installment %d has %s still outstanding, so %s cannot be waived against it. A waiver can '
            .'only release what is still owed.',
            $number,
            Money::format($remaining),
            Money::format($asked),
        ));
    }

    public static function approverMustHoldTheAbility(): self
    {
        return self::refuse('approved_by',
            'The person approving a discount needs the discount approval permission. Recording somebody '
            .'as the approver who could not have approved it is worse than leaving it blank.');
    }

    /**
     * [D18-8] — the segregation the spine applies to payouts, stated rather than assumed.
     */
    public static function approverMayNotBeTheCreator(): self
    {
        return self::refuse('approved_by',
            'You cannot approve your own discount unless you hold the approval permission yourself. '
            .'Pick an approver who does.');
    }

    public static function effectiveDateInTheFuture(): self
    {
        return self::refuse('effective_on',
            'A discount takes effect on a date that has arrived. A future date would change a net fee '
            .'that payments have already been measured against.');
    }

    public static function structureDoesNotBalance(string $sum, string $netPayable): self
    {
        return self::refuse('heads', sprintf(
            'The charges add up to %s but the admission agreed %s — a difference of %s. Nothing has been '
            .'written. Fix the heads or the admission figures so the two agree.',
            Money::format($sum),
            Money::format($netPayable),
            Money::format(Money::abs(Money::sub($sum, $netPayable))),
        ));
    }

    public static function admissionIsNotChargeable(string $stage): self
    {
        return self::refuse('student_admission_id', sprintf(
            'This admission is %s, so no fee can be raised against it.',
            mb_strtolower($stage),
        ));
    }

    public static function zeroCharge(): self
    {
        return self::refuse('gross_amount',
            'A charge of zero is not a charge. If the student owes nothing for this head, leave the head '
            .'off — or raise it and record a discount, so the reason is on the record.');
    }
}
