<?php

declare(strict_types=1);

namespace App\Services\Hr\Exceptions;

use LogicException;

/**
 * An attendance row or summary in a locked payroll period was edited or rebuilt (phase-07 HR-18).
 *
 * Locking a payroll run locks the attendance it was calculated from. Without that, correcting a March
 * attendance row after March's salaries were paid would leave the evidence disagreeing with the payment —
 * and the disagreement would be invisible until somebody recomputed by hand.
 *
 * The message names the run, because the only honest next step is to look at it.
 */
final class LockedAttendanceException extends LogicException
{
    public static function forPeriod(string $what, string $runNumber): self
    {
        return new self(sprintf(
            '%s is locked by payroll run %s (phase-07 HR-18). Attendance behind a paid period does not '
            .'change; correct the payroll with a correction run instead.',
            $what,
            $runNumber,
        ));
    }
}
