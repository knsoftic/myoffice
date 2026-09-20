<?php

declare(strict_types=1);

namespace App\Services\Hr\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A phase-07 business rule refused the act — a 422 with the message on the named field.
 *
 * These rules hold whatever the caller's permissions, Super Admin included: a correction needs a reason,
 * a leave balance will not go negative unless the business allowed it, a locked payroll period will not
 * be rebuilt, an employee may never approve their own leave.
 *
 * It **is** a `ValidationException`, exactly like the equivalents in phases 5 and 6, so Laravel renders it
 * as a 422 with an `errors` bag for JSON and as a redirect back with the error for a browser form — and it
 * is thrown inside the service's transaction, so the refused act leaves nothing behind.
 */
class HrRuleException extends ValidationException
{
    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }

    public static function reasonRequired(string $field = 'reason', string $message = 'A reason is required.'): static
    {
        return static::refuse($field, $message);
    }

    public static function correctionReasonTooShort(int $minimum): static
    {
        return static::refuse('reason', sprintf(
            'Say what was wrong and what it should be — at least %d characters, because this is the only '
            .'explanation anybody will have later.',
            $minimum
        ));
    }

    public static function insufficientLeaveBalance(string $requested, string $available): static
    {
        return static::refuse('total_days', sprintf(
            'That is %s day(s) against a balance of %s. Ask for fewer days, or have the shortfall approved '
            .'as unpaid leave.',
            $requested,
            $available
        ));
    }

    public static function cannotApproveOwnLeave(): static
    {
        return static::refuse('approval', 'Nobody approves their own leave, whatever permissions they hold.');
    }

    public static function approvalOutOfOrder(int $level): static
    {
        return static::refuse('approval', sprintf('Level %d cannot act before the level before it has.', $level - 1));
    }

    public static function overlappingLeave(string $reference): static
    {
        return static::refuse('from_date', sprintf('Those dates overlap leave request %s.', $reference));
    }

    public static function noSalaryStructure(string $employee): static
    {
        return static::refuse('employee_id', sprintf('%s has no active salary structure to pay against.', $employee));
    }

    public static function noAttendanceSummary(string $period): static
    {
        return static::refuse('period', sprintf(
            'The attendance summary for %s has not been built yet, and payroll reads nothing else.',
            $period
        ));
    }

    public static function runAlreadyLocked(string $run): static
    {
        return static::refuse('payroll_run', sprintf(
            'Run %s is locked. A locked run is corrected by a correction run, never by editing it.',
            $run
        ));
    }

    public static function slipDoesNotBalance(string $slip): static
    {
        return static::refuse('payroll_run', sprintf(
            'Slip %s does not add up: its totals disagree with its own component rows, so the run cannot be locked.',
            $slip
        ));
    }

    public static function advanceCeilingExceeded(string $outstanding): static
    {
        return static::refuse('amount', sprintf('Only %s is still outstanding on that advance.', $outstanding));
    }
}
