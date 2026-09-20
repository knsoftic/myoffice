<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Models\Hr\EmployeeAdvance;

/**
 * Everything `PayrollCalculator::build()` reads that is not the employee, the structure or the summary
 * (phase-07 §6.6 steps 4-8).
 *
 * **The settings arrive as values, not as lookups.** The calculator never calls `setting()`: the run
 * snapshotted its basis and its tax mode when it was created, and a run generated in April must produce
 * the same figures in June even if somebody changed a default in May. Passing them in is what makes the
 * calculator a pure function and what makes FT-HR-44 — "generate twice, get the same slip" — possible.
 *
 * `$manualLines` are the typed bonus, commission, overtime and reimbursement lines a holder of
 * `payroll.create` entered for this employee on this run (§6.6 step 6). §28's four named earnings are all
 * ordinary components rather than loose columns, so each one is audited and each one appears on the slip
 * under its own name.
 */
final readonly class PayrollInputs
{
    /**
     * @param  'calendar_days'|'working_days'|'fixed_30'  $dayBasis
     * @param  'basic'|'gross'  $lopBasis
     * @param  'none'|'fixed_percentage'|'manual'  $taxMode
     * @param  'none'|'nearest_1'|'nearest_10'  $netRounding
     * @param  list<PayslipLine>  $manualLines
     * @param  list<EmployeeAdvance>  $advancesDue
     */
    public function __construct(
        public string $dayBasis = 'calendar_days',
        public string $lopBasis = 'gross',
        public string $taxMode = 'manual',
        public string $taxRate = '0.0000',
        public bool $unpaidLeaveDeductionEnabled = true,
        public int $lateDeductionLatesPerDay = 0,
        public string $advanceRecoveryCapPercent = '50.0000',
        public string $netRounding = 'none',
        public array $manualLines = [],
        public array $advancesDue = [],
        public ?string $manualTaxAmount = null,
    ) {}

    /**
     * The inputs a run carries, read from its snapshot rather than from today's settings.
     *
     * @param  array<string, mixed>  $run
     * @param  list<PayslipLine>  $manualLines
     * @param  list<EmployeeAdvance>  $advancesDue
     */
    public static function fromRun(
        array $run,
        array $manualLines = [],
        array $advancesDue = [],
        ?string $manualTaxAmount = null,
    ): self {
        return new self(
            dayBasis: (string) ($run['day_basis'] ?? 'calendar_days'),
            lopBasis: (string) ($run['lop_basis'] ?? 'gross'),
            taxMode: (string) ($run['tax_mode'] ?? 'manual'),
            taxRate: (string) ($run['tax_rate'] ?? '0.0000'),
            unpaidLeaveDeductionEnabled: (bool) ($run['unpaid_leave_deduction_enabled'] ?? true),
            lateDeductionLatesPerDay: (int) ($run['late_deduction_lates_per_day'] ?? 0),
            advanceRecoveryCapPercent: (string) ($run['advance_recovery_cap_percent'] ?? '50.0000'),
            netRounding: (string) ($run['net_rounding'] ?? 'none'),
            manualLines: $manualLines,
            advancesDue: $advancesDue,
            manualTaxAmount: $manualTaxAmount,
        );
    }

    /**
     * The settings a run snapshots at creation, read from the live configuration exactly once.
     *
     * @return array<string, mixed>
     */
    public static function snapshotSettings(): array
    {
        return [
            'day_basis' => (string) setting('hr.payroll_day_basis', 'calendar_days'),
            'lop_basis' => (string) setting('hr.lop_basis', 'gross'),
            'tax_mode' => (string) setting('hr.tax_mode', 'manual'),
            'tax_rate' => (string) setting('hr.tax_default_rate', '0.0000'),
            'unpaid_leave_deduction_enabled' => (bool) setting('hr.unpaid_leave_deduction_enabled', true),
            'late_deduction_lates_per_day' => (int) setting('hr.late_deduction_lates_per_day', 0),
            'advance_recovery_cap_percent' => (string) setting('hr.advance_recovery_cap_percent', '50.0000'),
            'net_rounding' => (string) setting('hr.payroll_net_rounding', 'none'),
        ];
    }
}
