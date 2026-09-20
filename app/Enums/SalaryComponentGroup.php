<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of line a salary component is (phase-07 §2.18, §3, requirement §28).
 *
 * **The group decides the side** ({@see side()}), not a separate column somebody could set
 * inconsistently: a `tax` line is a deduction because it is tax, and there is no way to configure an
 * earning that behaves like one. That is also what lets `payroll_run_items` keep reporting handles —
 * `allowance_amount`, `bonus_amount`, `tax_amount` and the rest — that always agree with the component
 * rows they summarise, because {@see reportingColumn()} is the one mapping.
 *
 * {@see isSystem()} marks the five groups payroll produces by itself. A human may not create a component
 * in one of them: loss of pay, a late deduction and an advance recovery are computed from attendance and
 * from the advance ledger (§6.6), and letting somebody hand-enter one would put a second, untraceable
 * source under the same total.
 */
enum SalaryComponentGroup: string
{
    use HasOptions;

    // Earnings.
    case Basic = 'basic';
    case Allowance = 'allowance';
    case Bonus = 'bonus';
    case Commission = 'commission';
    case Overtime = 'overtime';
    case Reimbursement = 'reimbursement';
    case OtherEarning = 'other_earning';

    // Deductions.
    case Tax = 'tax';
    case AdvanceRecovery = 'advance_recovery';
    case UnpaidLeave = 'unpaid_leave';
    case LateDeduction = 'late_deduction';
    case Statutory = 'statutory';
    case OtherDeduction = 'other_deduction';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Basic salary',
            self::Allowance => 'Allowance',
            self::Bonus => 'Bonus',
            self::Commission => 'Commission',
            self::Overtime => 'Overtime',
            self::Reimbursement => 'Reimbursement',
            self::OtherEarning => 'Other earning',
            self::Tax => 'Tax',
            self::AdvanceRecovery => 'Advance recovery',
            self::UnpaidLeave => 'Loss of pay',
            self::LateDeduction => 'Late deduction',
            self::Statutory => 'Statutory deduction',
            self::OtherDeduction => 'Other deduction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Basic => 'emerald',
            self::Allowance => 'teal',
            self::Bonus => 'lime',
            self::Commission => 'cyan',
            self::Overtime => 'sky',
            self::Reimbursement => 'indigo',
            self::OtherEarning => 'violet',
            self::Tax => 'rose',
            self::AdvanceRecovery => 'orange',
            self::UnpaidLeave => 'amber',
            self::LateDeduction => 'yellow',
            self::Statutory => 'pink',
            self::OtherDeduction => 'zinc',
        };
    }

    /**
     * Which side of the slip this group falls on. The group **is** the side — there is no way to
     * configure a tax line that adds to somebody's pay.
     */
    public function side(): SalaryComponentType
    {
        return match ($this) {
            self::Basic, self::Allowance, self::Bonus, self::Commission,
            self::Overtime, self::Reimbursement, self::OtherEarning => SalaryComponentType::Earning,
            default => SalaryComponentType::Deduction,
        };
    }

    /**
     * Is this group produced by payroll itself rather than configured by a human?
     *
     * A person may not create a component in one of these: the figure comes from attendance (§6.4) or
     * from the advance ledger (§6.6 step 8), and a hand-entered line under the same total would make the
     * number impossible to explain.
     */
    public function isSystem(): bool
    {
        return in_array($this, [
            self::UnpaidLeave,
            self::LateDeduction,
            self::AdvanceRecovery,
            self::Overtime,
            self::Tax,
        ], true);
    }

    /**
     * The `payroll_run_items` column this group sums into, or null when the group has no handle of its
     * own and lands only in `gross_earnings` / `total_deductions`.
     */
    public function reportingColumn(): ?string
    {
        return match ($this) {
            self::Allowance => 'allowance_amount',
            self::Bonus => 'bonus_amount',
            self::Commission => 'commission_amount',
            self::Overtime => 'overtime_amount',
            self::Tax => 'tax_amount',
            self::AdvanceRecovery => 'advance_recovery_amount',
            self::UnpaidLeave => 'unpaid_leave_deduction',
            self::LateDeduction => 'late_deduction',
            default => null,
        };
    }

    /**
     * Is this group part of the taxable base by default? A reimbursement is the business paying back
     * money somebody already spent, so it is not pay.
     */
    public function isTaxableByDefault(): bool
    {
        return $this->side() === SalaryComponentType::Earning && $this !== self::Reimbursement;
    }

    /**
     * The groups on one side, for a grouped picker.
     *
     * @return list<self>
     */
    public static function forSide(SalaryComponentType $side): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $group): bool => $group->side() === $side
        ));
    }
}
