<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where an employee stands with the business (phase-07 §3, `employees.status`, requirement §24).
 *
 * {@see isPayrollEligible()} is the one that matters: a payroll run generates an item for `active` and
 * `probation` staff and for nobody else, so a suspended or resigned employee cannot be paid by accident.
 * Suspension and termination are discretionary acts and carry a mandatory reason (HR-20).
 */
enum EmployeeStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Probation = 'probation';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
    case Resigned = 'resigned';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Probation => 'Probation',
            self::Suspended => 'Suspended',
            self::Inactive => 'Inactive',
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Probation => 'sky',
            self::Suspended => 'amber',
            self::Inactive => 'slate',
            self::Resigned => 'zinc',
            self::Terminated => 'rose',
        };
    }


    /**
     * May a payroll run produce a slip for this employee? `active` and `probation` only.
     */
    public function isPayrollEligible(): bool
    {
        return $this === self::Active || $this === self::Probation;
    }

    /**
     * Has this person left? Their record stays — history does not end with employment.
     */
    public function isExited(): bool
    {
        return $this === self::Resigned || $this === self::Terminated;
    }

    /**
     * Moving **to** this status needs a written reason (HR-20).
     */
    public function requiresReason(): bool
    {
        return $this === self::Suspended || $this === self::Terminated || $this === self::Resigned;
    }
}
