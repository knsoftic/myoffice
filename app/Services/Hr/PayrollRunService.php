<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AdvanceRecoveryType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\PayrollRunType;
use App\Enums\SalaryComponentGroup;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeAdvance;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\PayrollRunItemComponent;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Support\Hr\PayrollInputs;
use App\Support\Hr\PayrollPeriod;
use App\Support\Hr\PayslipDraft;
use App\Support\Hr\PayslipLine;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payroll runs: create, generate, lock, pay, correct (phase-07 §6.2, §6.7, §6.8).
 *
 * **A run has one irreversible moment.** Before `lock()` everything is a draft and regenerating is
 * expected. After it, every money column, every component row and the attendance behind the period are
 * frozen (HR-15, HR-18). There is **no unlock** ([D-HR-6]): a mistake found after locking is fixed by
 * §6.8's correction run, which is generated, locked and paid through the same lifecycle — so the
 * correction is as auditable as the thing it corrects, and no report ever needs to know a slip was wrong.
 *
 * **Generation never assumes.** An employee with no salary structure, no attendance summary, or an exit
 * before the period is **skipped with a named reason** and no item is written. Paying somebody zero
 * because a row was missing looks exactly like paying somebody who earned nothing, and the difference
 * matters.
 *
 * **A slip reduces an advance only when it is paid**, not when it is generated. A draft that is
 * regenerated, or a run that is cancelled, must not have quietly collected money in between.
 */
class PayrollRunService
{
    /**
     * The groups a human types for one run (§6.6 step 6) — §28's four named earnings, each an ordinary
     * audited component rather than a loose column.
     *
     * @var list<SalaryComponentGroup>
     */
    private const ENTERED_GROUPS = [
        SalaryComponentGroup::Bonus,
        SalaryComponentGroup::Commission,
        SalaryComponentGroup::Overtime,
        SalaryComponentGroup::Reimbursement,
    ];

    public function __construct(
        private readonly HrNumberService $numbers,
        private readonly PayrollCalculator $calculator,
        private readonly SalaryStructureService $structures,
        private readonly AdvanceService $advances,
        private readonly AttendanceSummaryService $summaries,
    ) {}

    /**
     * Open a run and snapshot everything that will influence its arithmetic (§6.2).
     *
     * The snapshot is the point: a run generated in April must produce the same figures in June even if
     * somebody changed a default in May.
     */
    public function create(
        int $year,
        int $month,
        ?int $branchId = null,
        PayrollRunType $type = PayrollRunType::Regular,
        ?string $title = null,
        ?PayrollRun $parent = null,
        ?Carbon $paymentDate = null,
        ?User $actor = null,
    ): PayrollRun {
        $period = PayrollPeriod::of($year, $month);
        $settings = PayrollInputs::snapshotSettings();

        return DB::transaction(function () use ($period, $branchId, $type, $title, $parent, $paymentDate, $actor, $settings) {
            $run = new PayrollRun;
            $run->forceFill([
                'run_number' => $this->numbers->payrollRunNumber(),
                'title' => $title ?: sprintf('%s payroll', $period->label()),
                'branch_id' => $branchId,
                'run_type' => $type,
                'parent_run_id' => $parent?->getKey(),
                'period_year' => $period->year,
                'period_month' => $period->month,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'payment_date' => $paymentDate?->toDateString(),
                'status' => PayrollRunStatus::Draft,
                'day_basis' => $settings['day_basis'],
                'lop_basis' => $settings['lop_basis'],
                'tax_mode' => $settings['tax_mode'],
                'settings_snapshot' => $settings,
                'created_by' => $actor?->getKey(),
            ]);

            try {
                $run->save();
            } catch (UniqueConstraintViolationException) {
                // `uq_pr_regular` (§6.10 #12) — one live regular run per branch per month.
                $existing = PayrollRun::query()
                    ->where('period_year', $period->year)
                    ->where('period_month', $period->month)
                    ->where('run_type', PayrollRunType::Regular)
                    ->where('status', '<>', PayrollRunStatus::Cancelled)
                    ->when($branchId === null,
                        fn ($query) => $query->whereNull('branch_id'),
                        fn ($query) => $query->where('branch_id', $branchId))
                    ->first();

                throw HrRuleException::refuse('period_month', sprintf(
                    '%s already has a regular payroll run (%s). A second one would pay the same month '
                    .'twice; a missed or wrong figure is fixed with a correction run instead.',
                    $period->label(),
                    $existing?->run_number ?? 'an existing run'
                ));
            }

            return $run;
        });
    }

    /**
     * Build or rebuild the run's items (§6.2).
     *
     * "Regenerate" means exactly this: draft items and their components are deleted and written again.
     * Nothing outside the run is touched, because nothing outside the run has happened yet.
     *
     * **Entered lines survive a regeneration.** The bonus, commission, overtime and reimbursement lines of
     * §6.6 step 6 are typed by a human; wiping them because attendance was rebuilt would make everybody
     * re-key them and would eventually mean somebody's bonus went missing. They are carried over from the
     * existing draft unless the caller passes replacements.
     *
     * @param  list<int>|null  $employeeIds
     * @param  array<int, list<PayslipLine>>  $inputLines  keyed by employee id (§6.6 step 6)
     * @param  array<int, string>  $manualTax  keyed by employee id, for `tax_mode = manual`
     * @return array{generated: int, skipped: array<int, string>, held: int}
     */
    public function generate(
        PayrollRun $run,
        ?array $employeeIds = null,
        ?User $actor = null,
        array $inputLines = [],
        array $manualTax = [],
    ): array {
        if (! $run->status->isEditable()) {
            throw HrRuleException::refuse('status', sprintf(
                'Run %s is %s. Only a draft or generated run can be built again — after locking, the '
                .'remedy is a correction run.',
                $run->run_number,
                $run->status->label()
            ));
        }

        $period = PayrollPeriod::of((int) $run->period_year, (int) $run->period_month);
        $report = ['generated' => 0, 'skipped' => [], 'held' => 0];

        $employees = Employee::query()
            ->with(['department', 'designation'])
            ->when($run->branch_id !== null, fn ($query) => $query->where('branch_id', $run->branch_id))
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->whereDate('joining_date', '<=', $period->end->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($employees as $employee) {
            $id = (int) $employee->getKey();

            $draft = $this->draftFor(
                $run,
                $employee,
                $period,
                $inputLines[$id] ?? $this->carriedInputLines($run, $employee),
                $manualTax[$id] ?? $this->carriedManualTax($run, $employee),
            );

            if ($draft->wasSkipped()) {
                $report['skipped'][(int) $employee->getKey()] = $draft->skipReason;

                continue;
            }

            DB::transaction(function () use ($run, $employee, $period, $draft, $actor, &$report): void {
                $this->writeItem($run, $employee, $period, $draft, $actor);
                $report['generated']++;

                if ($draft->holdReason !== null) {
                    $report['held']++;
                }
            });
        }

        $run->forceFill([
            'status' => PayrollRunStatus::Generated,
            'generated_at' => now(),
            'generated_by' => $actor?->getKey(),
        ])->save();

        $this->recomputeTotals($run);

        return $report;
    }

    /**
     * The same calculator the generator uses, read-only — so a preview can never differ from the result.
     *
     * @param  list<PayslipLine>  $inputLines
     */
    public function preview(
        PayrollRun $run,
        Employee $employee,
        array $inputLines = [],
        ?string $manualTax = null,
    ): PayslipDraft {
        return $this->draftFor(
            $run,
            $employee,
            PayrollPeriod::of((int) $run->period_year, (int) $run->period_month),
            $inputLines ?: $this->carriedInputLines($run, $employee),
            $manualTax ?? $this->carriedManualTax($run, $employee),
        );
    }

    /**
     * Freeze the run (§6.7). From here the items are evidence.
     *
     * HR-13 is asserted for every item **before** anything is stamped: a slip whose stored lines do not
     * sum to its own totals must never become immutable, because after locking there is no way to fix it
     * except a correction that would then have to explain an arithmetic error.
     */
    public function lock(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        if ($run->status->isLocked()) {
            throw HrRuleException::refuse('status', sprintf('Run %s is already locked.', $run->run_number));
        }

        if ($run->status === PayrollRunStatus::Cancelled) {
            throw HrRuleException::refuse('status', sprintf('Run %s was cancelled.', $run->run_number));
        }

        $items = $run->items()->with('components')->get();

        if ($items->isEmpty()) {
            throw HrRuleException::refuse('status', sprintf(
                'Run %s has no salary slips. Generate it before locking it.',
                $run->run_number
            ));
        }

        $broken = [];

        foreach ($items as $item) {
            if (! $item->totalsAgree()) {
                $broken[] = $item->slip_number;
            }
        }

        if ($broken !== []) {
            throw HrRuleException::refuse('status', sprintf(
                'These slips do not add up and cannot be locked: %s. Regenerate the run — a locked slip '
                .'can never be corrected in place (phase-07 HR-13).',
                implode(', ', $broken)
            ));
        }

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run->forceFill([
                'status' => PayrollRunStatus::Locked,
                'locked_at' => now(),
                'locked_by' => $actor?->getKey(),
            ])->save();

            $run->items()->update([
                'status' => PayrollItemStatus::Locked->value,
                'updated_at' => now(),
            ]);

            // Items already held stay held — a hold is a decision, not a state the lock overwrites.
            PayrollRunItem::query()
                ->where('payroll_run_id', $run->getKey())
                ->whereNotNull('hold_reason')
                ->update([
                    'status' => PayrollItemStatus::OnHold->value,
                    'updated_at' => now(),
                ]);

            // HR-18, R5 — the arithmetic behind a paid slip is frozen with it.
            $this->lockAttendance($run);

            return $run;
        });
    }

    /**
     * Record that one slip was paid (§6.2), and post the advance recovery it implies (§6.6 step 8).
     */
    public function markItemPaid(
        PayrollRunItem $item,
        PaymentMethod $method,
        ?string $reference = null,
        ?User $actor = null,
        ?Carbon $on = null,
    ): PayrollRunItem {
        if (! $item->status->isPayable()) {
            throw HrRuleException::refuse('status', sprintf(
                'Slip %s is %s. Only a locked slip can be paid — a draft can still change.',
                $item->slip_number,
                $item->status->label()
            ));
        }

        if ($method->expectsReference() && trim((string) $reference) === '') {
            throw HrRuleException::refuse('payment_reference', sprintf(
                'A %s needs its reference — it is how this payment is found again in a bank statement.',
                $method->label()
            ));
        }

        return DB::transaction(function () use ($item, $method, $reference, $actor, $on): PayrollRunItem {
            $item->forceFill([
                'status' => PayrollItemStatus::Paid,
                'paid_at' => ($on ?? now()),
                'payment_method' => $method,
                'payment_reference' => $reference,
                'paid_by' => $actor?->getKey(),
            ])->save();

            $this->advances->postRecoveriesFor($item->fresh('components'), $actor);

            $this->recomputeTotals($item->run);

            return $item;
        });
    }

    /**
     * Hold a slip back. A held item is excluded from the run's "fully paid" test and is named on screen,
     * so it cannot be forgotten quietly.
     */
    public function holdItem(PayrollRunItem $item, string $reason, ?User $actor = null): PayrollRunItem
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('hold_reason', 'Say why this salary is being held — '
                .'the employee will ask, and so will whoever releases it.');
        }

        if ($item->status === PayrollItemStatus::Paid) {
            throw HrRuleException::refuse('status', sprintf(
                'Slip %s was already paid. Money that has moved is corrected with a correction run.',
                $item->slip_number
            ));
        }

        $item->forceFill([
            'status' => PayrollItemStatus::OnHold,
            'hold_reason' => trim($reason),
        ])->save();

        $this->recomputeTotals($item->run);

        return $item;
    }

    /**
     * Cancel a run that has not been locked.
     */
    public function cancel(PayrollRun $run, string $reason, ?User $actor = null): PayrollRun
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('cancellation_reason', 'Say why the run is being cancelled.');
        }

        if (! $run->status->isEditable()) {
            throw HrRuleException::refuse('status', sprintf(
                'Run %s is %s and can no longer be cancelled. A locked run is history; correct it with a '
                .'correction run.',
                $run->run_number,
                $run->status->label()
            ));
        }

        return DB::transaction(function () use ($run, $reason, $actor): PayrollRun {
            $this->clearDraftItems($run);

            $run->forceFill([
                'status' => PayrollRunStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->getKey(),
                'cancellation_reason' => trim($reason),
                'employee_count' => 0,
                'total_gross' => Money::zero(),
                'total_deductions' => Money::zero(),
                'total_net' => Money::zero(),
                'total_paid' => Money::zero(),
            ])->save();

            return $run;
        });
    }

    /**
     * Issue a correction against a locked slip (§6.8).
     *
     * **The original row is never touched.** A new item on a `correction` run carries `corrects_item_id`,
     * components that may be negative, and a net that may be negative. An advance recovery being undone
     * posts a `credit` repayment row rather than editing the original repayment.
     *
     * @param  list<PayslipLine>  $lines
     */
    public function issueCorrection(
        PayrollRunItem $original,
        array $lines,
        string $reason,
        ?User $actor = null,
    ): PayrollRunItem {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('reason', 'A correction needs a reason — it is the only '
                .'explanation anybody will have for why two slips exist for one month.');
        }

        if ($lines === []) {
            throw HrRuleException::refuse('lines', 'A correction has to correct something.');
        }

        return DB::transaction(function () use ($original, $lines, $reason, $actor): PayrollRunItem {
            $run = $this->correctionRunFor($original, $actor);

            $gross = $this->sumLines($lines, true);
            $deductions = $this->sumLines($lines, false);
            $net = Money::round(Money::sub($gross, $deductions));

            $item = new PayrollRunItem;
            $item->forceFill([
                'payroll_run_id' => $run->getKey(),
                'run_type' => PayrollRunType::Correction,
                'slip_number' => $this->numbers->slipNumber(),
                'employee_id' => $original->employee_id,
                'salary_structure_id' => $original->salary_structure_id,
                'attendance_monthly_summary_id' => $original->attendance_monthly_summary_id,
                'corrects_item_id' => $original->getKey(),
                'department_name' => $original->department_name,
                'designation_title' => $original->designation_title,
                'employment_type' => $original->employment_type,
                'joining_date' => $original->joining_date?->toDateString(),
                'gross_earnings' => $gross,
                'total_deductions' => $deductions,
                'net_salary' => $net,
                'taxable_gross' => Money::zero(),
                'status' => PayrollItemStatus::Draft,
                'notes' => trim($reason),
                'calculation_snapshot' => [
                    'corrects' => $original->slip_number,
                    'reason' => trim($reason),
                ],
                'created_by' => $actor?->getKey(),
            ])->save();

            foreach ($lines as $index => $line) {
                $component = new PayrollRunItemComponent;
                $component->forceFill($line->toRow() + [
                    'payroll_run_item_id' => $item->getKey(),
                    'run_type' => PayrollRunType::Correction->value,
                    'sort_order' => $index,
                ])->save();
            }

            $this->writeReportingHandles($item, $lines);
            $this->reverseAdvanceRecoveries($item, $lines, $actor);
            $this->recomputeTotals($run);

            return $item;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<PayslipLine>  $inputLines
     */
    private function draftFor(
        PayrollRun $run,
        Employee $employee,
        PayrollPeriod $period,
        array $inputLines = [],
        ?string $manualTax = null,
    ): PayslipDraft {
        $structure = $this->structures->effectiveOn($employee, $period->end);

        $summary = AttendanceMonthlySummary::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->first();

        $inputs = PayrollInputs::fromRun(
            run: (array) ($run->settings_snapshot ?? []) + [
                'day_basis' => (string) $run->day_basis,
                'lop_basis' => (string) $run->lop_basis,
                'tax_mode' => (string) $run->tax_mode,
            ],
            manualLines: $inputLines,
            advancesDue: $this->advances->dueFor($employee, $period->year, $period->month)->all(),
            manualTaxAmount: $manualTax,
        );

        return $this->calculator->build($employee, $period, $summary, $structure, $inputs);
    }

    private function writeItem(
        PayrollRun $run,
        Employee $employee,
        PayrollPeriod $period,
        PayslipDraft $draft,
        ?User $actor,
    ): PayrollRunItem {
        $existing = PayrollRunItem::query()
            ->where('payroll_run_id', $run->getKey())
            ->where('employee_id', $employee->getKey())
            ->lockForUpdate()
            ->first();

        // A regenerated slip keeps its number: it has been on screen, and possibly in an email.
        $slipNumber = null;

        if ($existing !== null) {
            if ($existing->status->isImmutable()) {
                throw HrRuleException::refuse('status', sprintf(
                    'Slip %s is %s and cannot be regenerated.',
                    $existing->slip_number,
                    $existing->status->label()
                ));
            }

            // "Regenerate" means the draft is rewritten, slip number and all evidence of the draft gone.
            $existing->components()->delete();
            $slipNumber = $existing->slip_number;
            $existing->forceDelete();
        }

        $summary = AttendanceMonthlySummary::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_year', $period->year)
            ->where('period_month', $period->month)
            ->first();

        $structure = $this->structures->effectiveOn($employee, $period->end);

        $item = new PayrollRunItem;
        $item->forceFill([
            'payroll_run_id' => $run->getKey(),
            'run_type' => $run->run_type,
            'slip_number' => $slipNumber ?? $this->numbers->slipNumber(),
            'employee_id' => $employee->getKey(),
            'salary_structure_id' => $structure?->getKey(),
            'attendance_monthly_summary_id' => $summary?->getKey(),
            'department_name' => $employee->department?->name,
            'designation_title' => $employee->designation?->title,
            'employment_type' => $employee->employment_type->value,
            'joining_date' => $employee->joining_date->toDateString(),
            'basic_salary' => (string) ($structure->basic_salary ?? '0.00'),
            'contracted_gross' => (string) ($structure->gross_salary ?? '0.00'),
            'gross_earnings' => $draft->grossEarnings,
            'total_deductions' => $draft->totalDeductions,
            'taxable_gross' => $draft->taxableGross,
            'net_salary' => $draft->netSalary,
            'payable_days' => (string) ($summary->payable_days ?? '0.0000'),
            'lop_days' => (string) ($summary->lop_days ?? '0.0000'),
            'working_days' => (string) ($summary->working_days ?? '0.0000'),
            'present_days' => (string) ($summary->present_days ?? '0.0000'),
            'paid_leave_days' => (string) ($summary->paid_leave_days ?? '0.0000'),
            'unpaid_leave_days' => (string) ($summary->unpaid_leave_days ?? '0.0000'),
            'late_count' => (int) ($summary->late_count ?? 0),
            'day_divisor' => $draft->dayDivisor,
            'per_day_amount' => $draft->perDayAmount,
            'calculation_snapshot' => $draft->snapshot,
            'status' => $draft->holdReason === null ? PayrollItemStatus::Draft : PayrollItemStatus::OnHold,
            'hold_reason' => $draft->holdReason,
            'created_by' => $actor?->getKey(),
        ])->save();

        foreach ($draft->lines as $index => $line) {
            $component = new PayrollRunItemComponent;
            $component->forceFill($line->toRow() + [
                'payroll_run_item_id' => $item->getKey(),
                'run_type' => $run->run_type->value,
                'sort_order' => $index,
            ])->save();
        }

        $this->writeReportingHandles($item, $draft->lines);

        return $item;
    }

    /**
     * §6.6 step 11: each money handle is the sum of its component group, so a report never has to know
     * the component codes and can never disagree with the slip.
     *
     * @param  list<PayslipLine>  $lines
     */
    private function writeReportingHandles(PayrollRunItem $item, array $lines): void
    {
        $handles = [];

        foreach (SalaryComponentGroup::cases() as $group) {
            $column = $group->reportingColumn();

            if ($column === null) {
                continue;
            }

            $amounts = [];

            foreach ($lines as $line) {
                if ($line->group === $group) {
                    $amounts[] = $line->amount;
                }
            }

            $handles[$column] = $amounts === [] ? Money::zero() : Money::round(Money::sum($amounts));
        }

        $item->forceFill($handles)->save();
    }

    /**
     * Freeze the period's attendance and summaries behind the run (HR-18).
     */
    private function lockAttendance(PayrollRun $run): void
    {
        Attendance::query()
            ->whereBetween('attendance_date', [
                $run->period_start->toDateString(),
                $run->period_end->toDateString(),
            ])
            ->when($run->branch_id !== null, fn ($query) => $query->where('branch_id', $run->branch_id))
            ->whereNull('locked_at')
            ->whereIn('employee_id', $run->items()->pluck('employee_id'))
            ->update([
                'locked_at' => now(),
                'locked_by_payroll_run_id' => $run->getKey(),
            ]);

        $this->summaries->lockPeriod(
            (int) $run->period_year,
            (int) $run->period_month,
            (int) $run->getKey(),
            $run->branch_id === null ? null : (int) $run->branch_id,
        );
    }

    /**
     * The correction run for a period — found or opened. A correction run does not occupy the regular
     * slot, so any number of them may exist for one month (§6.8).
     *
     * A run that already carries a slip for this employee cannot take another (`uq_pri_employee`), so a
     * second correction for the same person opens a further run rather than failing. That is the honest
     * shape: two corrections are two events, each with its own number, its own lock and its own payment.
     */
    private function correctionRunFor(PayrollRunItem $original, ?User $actor): PayrollRun
    {
        $parent = $original->run;

        $existing = PayrollRun::query()
            ->where('run_type', PayrollRunType::Correction)
            ->where('parent_run_id', $parent->getKey())
            ->whereNotIn('status', [PayrollRunStatus::Cancelled, PayrollRunStatus::Paid])
            ->whereDoesntHave('items', fn ($query) => $query->where('employee_id', $original->employee_id))
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create(
            year: (int) $parent->period_year,
            month: (int) $parent->period_month,
            branchId: $parent->branch_id === null ? null : (int) $parent->branch_id,
            type: PayrollRunType::Correction,
            title: sprintf('Correction to %s', $parent->run_number),
            parent: $parent,
            actor: $actor,
        );
    }

    /**
     * A correction that gives back an advance recovery posts a `credit` repayment row rather than editing
     * the original one (§6.8).
     *
     * @param  list<PayslipLine>  $lines
     */
    private function reverseAdvanceRecoveries(PayrollRunItem $item, array $lines, ?User $actor): void
    {
        foreach ($lines as $line) {
            if ($line->sourceType !== 'employee_advance' || $line->sourceId === null) {
                continue;
            }

            if (! Money::isPositive($line->amount)) {
                continue;
            }

            $advance = EmployeeAdvance::query()->find($line->sourceId);

            if ($advance === null) {
                continue;
            }

            $this->advances->recordRecovery(
                advance: $advance,
                amount: $line->amount,
                item: null,
                type: AdvanceRecoveryType::Correction,
                notes: sprintf('Reversed by correction slip %s.', $item->slip_number),
                actor: $actor,
                entryType: $line->isEarning() ? LedgerEntryType::Credit : LedgerEntryType::Debit,
            );
        }
    }

    /**
     * The §6.6 step-6 lines already stored on this run's draft slip for an employee, so regenerating does
     * not silently discard what a human typed.
     *
     * @return list<PayslipLine>
     */
    private function carriedInputLines(PayrollRun $run, Employee $employee): array
    {
        $item = PayrollRunItem::query()
            ->with('components')
            ->where('payroll_run_id', $run->getKey())
            ->where('employee_id', $employee->getKey())
            ->first();

        if ($item === null) {
            return [];
        }

        $carried = [];

        foreach ($item->components as $component) {
            $group = $component->component_group;

            if (! in_array($group, self::ENTERED_GROUPS, true)) {
                continue;
            }

            $carried[] = new PayslipLine(
                componentCode: (string) $component->component_code,
                componentName: (string) $component->component_name,
                group: $group,
                side: $group->side(),
                calculation: $component->calculation_type,
                amount: Money::round((string) $component->amount),
                rate: (string) $component->rate,
                baseAmount: (string) $component->base_amount,
                quantity: (string) $component->quantity,
                isTaxable: (bool) $component->is_taxable,
                salaryComponentId: $component->salary_component_id === null ? null : (int) $component->salary_component_id,
                sourceType: $component->source_type,
                sourceId: $component->source_id === null ? null : (int) $component->source_id,
                calculationNote: $component->calculation_note,
            );
        }

        return $carried;
    }

    /**
     * The manual tax already stored on this run's draft slip, carried for the same reason.
     */
    private function carriedManualTax(PayrollRun $run, Employee $employee): ?string
    {
        $amount = PayrollRunItemComponent::query()
            ->whereIn('payroll_run_item_id', PayrollRunItem::query()
                ->where('payroll_run_id', $run->getKey())
                ->where('employee_id', $employee->getKey())
                ->select('id'))
            ->where('component_group', SalaryComponentGroup::Tax->value)
            ->value('amount');

        return $amount === null ? null : Money::round((string) $amount);
    }

    /**
     * Re-derive the run's four caches from its items.
     */
    public function recomputeTotals(PayrollRun $run): PayrollRun
    {
        $items = $run->items()->get();

        $paid = $items->where('status', PayrollItemStatus::Paid);
        $payable = $items->reject(fn (PayrollRunItem $item): bool => $item->status === PayrollItemStatus::Cancelled);

        $run->forceFill([
            'employee_count' => $items->count(),
            'total_gross' => $this->sumColumn($payable, 'gross_earnings'),
            'total_deductions' => $this->sumColumn($payable, 'total_deductions'),
            'total_net' => $this->sumColumn($payable, 'net_salary'),
            'total_paid' => $this->sumColumn($paid, 'net_salary'),
        ]);

        // A run is "paid" once every slip that could be paid has been; a held slip keeps the run open,
        // which is what stops it disappearing off the screen with money still owed.
        if ($run->status->isLocked() || $run->status === PayrollRunStatus::PartiallyPaid) {
            $outstanding = $items->filter(
                fn (PayrollRunItem $item): bool => $item->status === PayrollItemStatus::Locked
                    || $item->status === PayrollItemStatus::OnHold
            );

            if ($items->isNotEmpty() && $outstanding->isEmpty()) {
                $run->forceFill(['status' => PayrollRunStatus::Paid, 'paid_at' => now()]);
            } elseif ($paid->isNotEmpty()) {
                $run->forceFill(['status' => PayrollRunStatus::PartiallyPaid]);
            }
        }

        $run->save();

        return $run;
    }

    private function clearDraftItems(PayrollRun $run): void
    {
        foreach ($run->items()->with('components')->get() as $item) {
            if ($item->status->isImmutable()) {
                continue;
            }

            $item->components()->delete();
            $item->forceDelete();
        }
    }

    /**
     * @param  Collection<int, PayrollRunItem>  $items
     */
    private function sumColumn($items, string $column): string
    {
        $amounts = $items->map(fn (PayrollRunItem $item): string => (string) $item->{$column})->all();

        return $amounts === [] ? Money::zero() : Money::round(Money::sum($amounts));
    }

    /**
     * @param  list<PayslipLine>  $lines
     */
    private function sumLines(array $lines, bool $earnings): string
    {
        $amounts = [];

        foreach ($lines as $line) {
            if ($line->isEarning() === $earnings) {
                $amounts[] = $line->amount;
            }
        }

        return $amounts === [] ? Money::zero() : Money::round(Money::sum($amounts));
    }
}
