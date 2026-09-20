<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Models\Hr\SalaryStructure;
use App\Support\Hr\PayrollInputs;
use App\Support\Hr\PayrollPeriod;
use App\Support\Hr\PayslipDraft;
use App\Support\Hr\PayslipLine;
use App\Support\Money;

/**
 * The payroll algorithm (phase-07 §6.6). A **pure function**: no writes, no events, no `now()`.
 *
 * Given the same employee, period, summary, structure and inputs it produces identical lines to the
 * paisa, every time. That is not a nicety — it is what lets the preview on screen be provably the same
 * figures as the slip that gets generated, and what lets a test assert a whole payroll rather than assert
 * that it "ran".
 *
 * **Every step that produces money produces a stored line** (HR-13). No total is ever recomputed from the
 * inputs afterwards: `gross_earnings` is the sum of the earning lines, `total_deductions` the sum of the
 * deduction lines, and `net` the difference. A slip that does not add up is therefore impossible rather
 * than merely unlikely.
 *
 * All arithmetic is `App\Support\Money` — bcmath, intermediate scale 6, quantised half-up at 2. The one
 * rule that matters when reading the code below: a figure is quantised **when it is stored on a line**,
 * and lines are summed from their stored values. That is why the worked example's 1,580.645161 becomes
 * 1,580.65 before it is multiplied by 2.5 days.
 */
class PayrollCalculator
{
    public function build(
        Employee $employee,
        PayrollPeriod $period,
        ?AttendanceMonthlySummary $summary,
        ?SalaryStructure $structure,
        PayrollInputs $inputs,
    ): PayslipDraft {
        // ---- step 0: resolve inputs, and refuse to guess -------------------------------------------
        if (! $employee->status->isPayrollEligible()) {
            return PayslipDraft::skipped((int) $employee->getKey(), 'not_payroll_eligible');
        }

        if ($employee->exit_date !== null && $employee->exit_date->lessThan($period->start)) {
            return PayslipDraft::skipped((int) $employee->getKey(), 'exited_before_period');
        }

        if ($structure === null) {
            return PayslipDraft::skipped((int) $employee->getKey(), 'no_salary_structure');
        }

        if ($summary === null) {
            // R4 — full attendance is never assumed. A missing summary stops the employee, not the run.
            return PayslipDraft::skipped((int) $employee->getKey(), 'no_attendance_summary');
        }

        $basic = Money::round((string) $structure->basic_salary);
        $contractedGross = Money::round((string) $structure->gross_salary);
        $payableDays = Money::round((string) $summary->payable_days, 4);
        $lopDays = Money::round((string) $summary->lop_days, 4);

        $lines = [];
        $sort = 0;

        // ---- step 1: basic ---------------------------------------------------------------------------
        $lines[] = new PayslipLine(
            componentCode: 'BASIC',
            componentName: 'Basic salary',
            group: SalaryComponentGroup::Basic,
            side: SalaryComponentType::Earning,
            calculation: SalaryComponentCalculation::Fixed,
            amount: $basic,
            isTaxable: true,
            calculationNote: 'Basic as per the salary structure.',
            sortOrder: $sort++,
        );

        $structureLines = $structure->components()->orderBy('sort_order')->orderBy('id')->get();

        // ---- step 2: pass-1 earnings -----------------------------------------------------------------
        foreach ($structureLines as $row) {
            // The row already casts its three enum columns; reading them back through ::from()
            // on a stringified enum is what broke the first time.
            $side = $row->side;
            $calculation = $row->calculation_type;

            if ($side !== SalaryComponentType::Earning || $calculation->pass() !== 1) {
                continue;
            }

            $lines[] = $this->structureLine($row, $calculation, $basic, $payableDays, $sort++);
        }

        // ---- step 3: pass-2 earnings, against the gross pass 1 produced ------------------------------
        $grossBeforePassTwo = $this->sumOf($lines, SalaryComponentType::Earning);

        foreach ($structureLines as $row) {
            $side = $row->side;
            $calculation = $row->calculation_type;

            if ($side !== SalaryComponentType::Earning || $calculation->pass() !== 2) {
                continue;
            }

            $rate = Money::round((string) $row->rate, 4);
            $amount = Money::round(Money::percentage($grossBeforePassTwo, $rate));

            $lines[] = new PayslipLine(
                componentCode: (string) $row->component_code,
                componentName: (string) $row->component_name,
                group: $row->component_group,
                side: SalaryComponentType::Earning,
                calculation: $calculation,
                amount: $amount,
                rate: $rate,
                baseAmount: $grossBeforePassTwo,
                isTaxable: (bool) $row->is_taxable,
                salaryComponentId: $row->salary_component_id === null ? null : (int) $row->salary_component_id,
                calculationNote: sprintf('%s%% of %s gross.', rtrim(rtrim($rate, '0'), '.'), $grossBeforePassTwo),
                sortOrder: $sort++,
            );
        }

        // ---- step 6 (entered before the deductions that depend on the gross) -------------------------
        foreach ($inputs->manualLines as $manual) {
            if ($manual->isEarning()) {
                $lines[] = $manual;
                $sort++;
            }
        }

        // ---- step 4: attendance proration -------------------------------------------------------------
        $divisor = $this->divisor($inputs->dayBasis, $period, $summary);

        if (Money::isZero($divisor)) {
            return PayslipDraft::skipped((int) $employee->getKey(), 'zero_day_divisor');
        }

        $prorateBase = $inputs->lopBasis === 'basic' ? $basic : $contractedGross;
        $perDay = Money::round(Money::div($prorateBase, $divisor));

        if ($inputs->unpaidLeaveDeductionEnabled && Money::isPositive($lopDays)) {
            $amount = Money::round(Money::mul($perDay, $lopDays));

            $lines[] = new PayslipLine(
                componentCode: 'UNPAID_LEAVE',
                componentName: 'Unpaid leave / absence',
                group: SalaryComponentGroup::UnpaidLeave,
                side: SalaryComponentType::Deduction,
                calculation: SalaryComponentCalculation::PerDay,
                amount: $amount,
                baseAmount: $perDay,
                quantity: $lopDays,
                isTaxable: false,
                calculationNote: sprintf('%s / %s x %s days', $prorateBase, rtrim(rtrim($divisor, '0'), '.'), $lopDays),
                sortOrder: $sort++,
            );
        }

        // ---- step 5: late deduction, only when the business asked for one ----------------------------
        if ($inputs->lateDeductionLatesPerDay > 0) {
            $lateDays = intdiv((int) $summary->late_count, $inputs->lateDeductionLatesPerDay);

            if ($lateDays > 0) {
                $amount = Money::round(Money::mul($perDay, (string) $lateDays));

                $lines[] = new PayslipLine(
                    componentCode: 'LATE',
                    componentName: 'Late deduction',
                    group: SalaryComponentGroup::LateDeduction,
                    side: SalaryComponentType::Deduction,
                    calculation: SalaryComponentCalculation::PerDay,
                    amount: $amount,
                    baseAmount: $perDay,
                    quantity: Money::round((string) $lateDays, 4),
                    isTaxable: false,
                    calculationNote: sprintf(
                        '%d late arrivals / %d per day = %d day(s) x %s',
                        (int) $summary->late_count,
                        $inputs->lateDeductionLatesPerDay,
                        $lateDays,
                        $perDay
                    ),
                    sortOrder: $sort++,
                );
            }
        }

        // ---- step 7: structure deductions, then tax ---------------------------------------------------
        foreach ($structureLines as $row) {
            $side = $row->side;

            if ($side !== SalaryComponentType::Deduction) {
                continue;
            }

            $calculation = $row->calculation_type;
            $base = $calculation === SalaryComponentCalculation::PercentageOfGross
                ? $grossBeforePassTwo
                : $basic;

            $lines[] = $this->structureLine($row, $calculation, $base, $payableDays, $sort++);
        }

        foreach ($inputs->manualLines as $manual) {
            if ($manual->isDeduction() && $manual->group !== SalaryComponentGroup::Tax) {
                $lines[] = $manual;
                $sort++;
            }
        }

        $taxableGross = $this->taxableGross($lines);
        $tax = $this->taxLine($inputs, $taxableGross, $sort);

        if ($tax !== null) {
            $lines[] = $tax;
            $sort++;
        }

        // ---- step 8: advance recovery, capped ---------------------------------------------------------
        $netBeforeRecovery = Money::sub(
            $this->sumOf($lines, SalaryComponentType::Earning),
            $this->sumOf($lines, SalaryComponentType::Deduction)
        );

        $cap = Money::round(Money::percentage($netBeforeRecovery, $inputs->advanceRecoveryCapPercent));
        $remainingCap = Money::isPositive($cap) ? $cap : Money::zero();
        $advanceDecisions = [];

        foreach ($inputs->advancesDue as $advance) {
            $outstanding = Money::round((string) $advance->outstanding_amount);
            $installment = Money::round((string) $advance->installment_amount);
            $take = Money::min($installment, $outstanding, $remainingCap);

            $advanceDecisions[] = [
                'advance_id' => (int) $advance->getKey(),
                'advance_number' => (string) $advance->advance_number,
                'installment' => $installment,
                'outstanding' => $outstanding,
                'cap_remaining' => $remainingCap,
                'taken' => $take,
            ];

            if (! Money::isPositive($take)) {
                continue;
            }

            $lines[] = new PayslipLine(
                componentCode: 'ADV_RECOVERY',
                componentName: sprintf('Advance recovery (%s)', $advance->advance_number),
                group: SalaryComponentGroup::AdvanceRecovery,
                side: SalaryComponentType::Deduction,
                calculation: SalaryComponentCalculation::Fixed,
                amount: $take,
                isTaxable: false,
                sourceType: 'employee_advance',
                sourceId: (int) $advance->getKey(),
                calculationNote: sprintf(
                    'Installment %s, outstanding %s, %s%% cap of %s.',
                    $installment,
                    $outstanding,
                    rtrim(rtrim($inputs->advanceRecoveryCapPercent, '0'), '.'),
                    $netBeforeRecovery
                ),
                sortOrder: $sort++,
            );

            $remainingCap = Money::sub($remainingCap, $take);
        }

        // ---- step 9: totals, and the negative-net ladder ----------------------------------------------
        [$lines, $holdReason] = $this->settleNegativeNet($lines);

        // ---- step 10: business rounding ---------------------------------------------------------------
        $lines = $this->applyRounding($lines, $inputs->netRounding, $sort);

        $gross = $this->sumOf($lines, SalaryComponentType::Earning);
        $deductions = $this->sumOf($lines, SalaryComponentType::Deduction);
        $net = Money::round(Money::sub($gross, $deductions));

        // ---- step 12: the snapshot that explains all of it --------------------------------------------
        $snapshot = [
            'structure_id' => (int) $structure->getKey(),
            'structure_version' => (int) $structure->version,
            'summary_id' => (int) $summary->getKey(),
            'summary' => [
                'working_days' => (string) $summary->working_days,
                'present_days' => (string) $summary->present_days,
                'payable_days' => $payableDays,
                'lop_days' => $lopDays,
                'late_count' => (int) $summary->late_count,
            ],
            'day_basis' => $inputs->dayBasis,
            'day_divisor' => $divisor,
            'lop_basis' => $inputs->lopBasis,
            'prorate_base' => $prorateBase,
            'per_day_amount' => $perDay,
            'tax_mode' => $inputs->taxMode,
            'tax_rate' => $inputs->taxRate,
            'taxable_gross' => $taxableGross,
            'advance_recovery_cap_percent' => $inputs->advanceRecoveryCapPercent,
            'advance_decisions' => $advanceDecisions,
            'net_rounding' => $inputs->netRounding,
            'unpaid_leave_deduction_enabled' => $inputs->unpaidLeaveDeductionEnabled,
            'late_deduction_lates_per_day' => $inputs->lateDeductionLatesPerDay,
        ];

        return new PayslipDraft(
            employeeId: (int) $employee->getKey(),
            lines: array_values($lines),
            grossEarnings: $gross,
            totalDeductions: $deductions,
            netSalary: $net,
            taxableGross: $taxableGross,
            dayDivisor: $divisor,
            perDayAmount: $perDay,
            snapshot: $snapshot,
            holdReason: $holdReason,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The steps, each in one place
    |--------------------------------------------------------------------------
    */

    /**
     * §6.6 step 4's divisor. `working_days` of zero is caught by the caller as a skip rather than becoming
     * a division by zero somewhere further down.
     */
    private function divisor(string $basis, PayrollPeriod $period, AttendanceMonthlySummary $summary): string
    {
        return match ($basis) {
            'working_days' => Money::round((string) $summary->working_days, 4),
            'fixed_30' => '30.0000',
            default => Money::round((string) $period->calendarDays(), 4),
        };
    }

    /**
     * One structure line, computed by its calculation type (§6.6 steps 2 and 7).
     */
    private function structureLine(
        $row,
        SalaryComponentCalculation $calculation,
        string $base,
        string $payableDays,
        int $sort,
    ): PayslipLine {
        $rate = Money::round((string) $row->rate, 4);
        $stored = Money::round((string) $row->amount);

        [$amount, $note] = match ($calculation) {
            SalaryComponentCalculation::Fixed => [
                $stored,
                'Fixed amount from the salary structure.',
            ],
            SalaryComponentCalculation::PercentageOfBasic => [
                Money::round(Money::percentage($base, $rate)),
                sprintf('%s%% of %s.', rtrim(rtrim($rate, '0'), '.'), $base),
            ],
            SalaryComponentCalculation::PerDay => [
                Money::round(Money::mul($stored, $payableDays)),
                sprintf('%s x %s payable days.', $stored, $payableDays),
            ],
            SalaryComponentCalculation::PercentageOfGross => [
                Money::round(Money::percentage($base, $rate)),
                sprintf('%s%% of %s.', rtrim(rtrim($rate, '0'), '.'), $base),
            ],
        };

        return new PayslipLine(
            componentCode: (string) $row->component_code,
            componentName: (string) $row->component_name,
            group: $row->component_group,
            side: $row->side,
            calculation: $calculation,
            amount: $amount,
            rate: $rate,
            baseAmount: $calculation === SalaryComponentCalculation::PerDay ? $stored : $base,
            quantity: $calculation === SalaryComponentCalculation::PerDay ? $payableDays : '0.0000',
            isTaxable: (bool) $row->is_taxable,
            salaryComponentId: $row->salary_component_id === null ? null : (int) $row->salary_component_id,
            calculationNote: $note,
            sortOrder: $sort,
        );
    }

    /**
     * §6.6 step 7's tax. `manual` takes whatever was entered for this employee or run; `none` writes no
     * line at all, so a business with no payroll tax has no zero row cluttering every slip.
     */
    private function taxLine(PayrollInputs $inputs, string $taxableGross, int $sort): ?PayslipLine
    {
        $amount = match ($inputs->taxMode) {
            'fixed_percentage' => Money::round(Money::percentage($taxableGross, $inputs->taxRate)),
            'manual' => $inputs->manualTaxAmount === null ? null : Money::round($inputs->manualTaxAmount),
            default => null,
        };

        if ($amount === null || ! Money::isPositive($amount)) {
            return null;
        }

        return new PayslipLine(
            componentCode: 'TAX',
            componentName: 'Income tax',
            group: SalaryComponentGroup::Tax,
            side: SalaryComponentType::Deduction,
            calculation: SalaryComponentCalculation::Fixed,
            amount: $amount,
            rate: $inputs->taxMode === 'fixed_percentage' ? Money::round($inputs->taxRate, 4) : '0.0000',
            baseAmount: $taxableGross,
            isTaxable: false,
            calculationNote: $inputs->taxMode === 'fixed_percentage'
                ? sprintf('%s%% of the taxable gross %s.', rtrim(rtrim($inputs->taxRate, '0'), '.'), $taxableGross)
                : 'Entered for this run.',
            sortOrder: $sort,
        );
    }

    /**
     * §6.6 step 9's ladder. A negative net on a regular run is never *stored* as a negative: the advance
     * recovery gives way first, because an advance is the business's money and next month will still be
     * there — a salary that goes backwards is a bill sent to an employee.
     *
     * If it is still negative once the recovery is gone, the item is held with a reason naming the
     * deductions, rather than paid (HR-14).
     *
     * @param  list<PayslipLine>  $lines
     * @return array{0: list<PayslipLine>, 1: string|null}
     */
    private function settleNegativeNet(array $lines): array
    {
        $net = fn (array $rows): string => Money::sub(
            $this->sumOf($rows, SalaryComponentType::Earning),
            $this->sumOf($rows, SalaryComponentType::Deduction)
        );

        if (! Money::isNegative($net($lines))) {
            return [$lines, null];
        }

        $recoveryKeys = [];

        foreach ($lines as $index => $line) {
            if ($line->group === SalaryComponentGroup::AdvanceRecovery) {
                $recoveryKeys[] = $index;
            }
        }

        // Trim the recoveries from the last one backwards, then drop what is left of them.
        foreach (array_reverse($recoveryKeys) as $index) {
            $shortfall = Money::abs($net($lines));

            if (! Money::isPositive($shortfall)) {
                break;
            }

            $line = $lines[$index];
            $keep = Money::sub($line->amount, $shortfall);

            if (Money::isPositive($keep)) {
                $lines[$index] = $line->withAmount(
                    Money::round($keep),
                    $line->calculationNote.' Reduced so the net salary does not go negative.'
                );

                break;
            }

            unset($lines[$index]);
            $lines = array_values($lines);
        }

        if (! Money::isNegative($net($lines))) {
            return [$lines, null];
        }

        $offenders = [];

        foreach ($lines as $line) {
            if ($line->isDeduction()) {
                $offenders[] = sprintf('%s %s', $line->componentName, $line->amount);
            }
        }

        return [$lines, sprintf(
            'The deductions exceed the earnings even with no advance recovery: %s. Nothing is paid until '
            .'HR decides what to waive or defer.',
            implode(', ', $offenders)
        )];
    }

    /**
     * §6.6 step 10. The rounding difference is a **visible line**, so `gross - deductions = net` still
     * holds exactly (HR-13) rather than the net being quietly nudged.
     *
     * @param  list<PayslipLine>  $lines
     * @return list<PayslipLine>
     */
    private function applyRounding(array $lines, string $mode, int $sort): array
    {
        $nearest = match ($mode) {
            'nearest_1' => 1,
            'nearest_10' => 10,
            default => 0,
        };

        if ($nearest === 0) {
            return $lines;
        }

        $net = Money::sub(
            $this->sumOf($lines, SalaryComponentType::Earning),
            $this->sumOf($lines, SalaryComponentType::Deduction)
        );

        $rounded = Money::roundTo($net, $nearest);
        $difference = Money::round(Money::sub($rounded, $net));

        if (Money::isZero($difference)) {
            return $lines;
        }

        $isEarning = Money::isPositive($difference);

        $lines[] = new PayslipLine(
            componentCode: 'ROUNDING',
            componentName: 'Rounding adjustment',
            group: $isEarning ? SalaryComponentGroup::OtherEarning : SalaryComponentGroup::OtherDeduction,
            side: $isEarning ? SalaryComponentType::Earning : SalaryComponentType::Deduction,
            calculation: SalaryComponentCalculation::Fixed,
            amount: Money::abs($difference),
            isTaxable: false,
            calculationNote: sprintf('Net %s rounded to the nearest %d.', $net, $nearest),
            sortOrder: $sort,
        );

        return $lines;
    }

    /**
     * The taxable base: the stored earning lines that are marked taxable (§6.6 step 7).
     *
     * @param  list<PayslipLine>  $lines
     */
    private function taxableGross(array $lines): string
    {
        $amounts = [];

        foreach ($lines as $line) {
            if ($line->isEarning() && $line->isTaxable) {
                $amounts[] = $line->amount;
            }
        }

        return $amounts === [] ? Money::zero() : Money::round(Money::sum($amounts));
    }

    /**
     * @param  list<PayslipLine>  $lines
     */
    private function sumOf(array $lines, SalaryComponentType $side): string
    {
        $amounts = [];

        foreach ($lines as $line) {
            if ($line->side === $side) {
                $amounts[] = $line->amount;
            }
        }

        return $amounts === [] ? Money::zero() : Money::round(Money::sum($amounts));
    }
}
