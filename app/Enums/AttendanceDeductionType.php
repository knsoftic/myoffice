<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why an attendance deduction exists (attendance-based salary deductions).
 *
 * Each type is switched on by its own `hr.*_enabled` setting and becomes its own payslip line, so a slip
 * always says which rule took the money.
 */
enum AttendanceDeductionType: string
{
    use HasOptions;

    /** A day whose status is Late: a share of that day's pay (`hr.late_arrival_deduction_percent`). */
    case LateArrival = 'late_arrival';

    /** A chargeable absence beyond the month's allowance (`hr.monthly_absence_allowance_days`): a full day. */
    case ExcessAbsence = 'excess_absence';

    public function label(): string
    {
        return match ($this) {
            self::LateArrival => 'Late arrival',
            self::ExcessAbsence => 'Excess leave / absence',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LateArrival => 'amber',
            self::ExcessAbsence => 'rose',
        };
    }

    /** The payslip component code the calculator writes for this type. */
    public function componentCode(): string
    {
        return match ($this) {
            self::LateArrival => 'ATT_LATE',
            self::ExcessAbsence => 'ATT_ABSENCE',
        };
    }

    /** The reporting group the line sums into, so `late_deduction` / `unpaid_leave_deduction` stay complete. */
    public function componentGroup(): SalaryComponentGroup
    {
        return match ($this) {
            self::LateArrival => SalaryComponentGroup::LateDeduction,
            self::ExcessAbsence => SalaryComponentGroup::UnpaidLeave,
        };
    }
}
