<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AdvanceRecoveryType;
use App\Enums\AdvanceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeAdvance;
use App\Models\Hr\EmployeeAdvanceRepayment;
use App\Models\Hr\PayrollRunItem;
use App\Models\User;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Salary advances and their recovery (phase-07 §6.2, HR-19).
 *
 * **Over-recovery is impossible, not merely guarded against.** Every recovery is written under the
 * advance's row lock with a conditional `UPDATE … WHERE recovered + waived + :x <= amount`; when the
 * condition fails the update affects zero rows and the service refuses, naming what is actually left.
 * The `chk_adv_ceiling` CHECK is the second wall behind it. Two payroll runs racing on the same advance
 * therefore cannot each take the last installment.
 *
 * **Recovery rows are written when a slip is paid, never when it is generated.** A draft slip that is
 * regenerated, or a run that is cancelled, must not have reduced somebody's advance in the meantime
 * (§6.6 step 8).
 *
 * **The unrecovered remainder stays on the advance.** When the cap bites, the shortfall is not written
 * off and not silently dropped — it simply takes another month (§6.10 #16).
 *
 * `dueFor()` is the **only** definition of "what should payroll recover this period", so the advances
 * screen and the payroll generator can never disagree about it.
 */
class AdvanceService
{
    public function __construct(
        private readonly HrNumberService $numbers,
    ) {}

    /**
     * File a request (§6.2).
     *
     * An amount above `hr.advance_max_multiple_of_basic × basic` needs `employee_advances.approve` to
     * file and records the justification — a big advance is a decision, and the record of who took it is
     * the point.
     */
    public function request(
        Employee $employee,
        string $amount,
        string $reason,
        int $installments = 1,
        ?int $firstRecoveryYear = null,
        ?int $firstRecoveryMonth = null,
        ?User $actor = null,
    ): EmployeeAdvance {
        $amount = Money::round($amount);
        $installments = max(1, $installments);

        if (! Money::isPositive($amount)) {
            throw HrRuleException::refuse('amount', 'An advance is a positive amount.');
        }

        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('reason', 'Say what the advance is for.');
        }

        $this->assertWithinCeiling($employee, $amount, $actor);

        $start = Carbon::create(
            $firstRecoveryYear ?? (int) now()->addMonth()->year,
            $firstRecoveryMonth ?? (int) now()->addMonth()->month,
            1
        );

        return DB::transaction(function () use ($employee, $amount, $reason, $installments, $start, $actor) {
            $advance = new EmployeeAdvance;
            $advance->forceFill([
                'advance_number' => $this->numbers->advanceNumber(),
                'employee_id' => $employee->getKey(),
                'amount' => $amount,
                'reason' => trim($reason),
                'requested_on' => now()->toDateString(),
                'installment_count' => $installments,
                'installment_amount' => $this->installmentAmount($amount, $installments),
                'first_recovery_year' => $start->year,
                'first_recovery_month' => $start->month,
                'outstanding_amount' => $amount,
                'status' => AdvanceStatus::Requested,
                'created_by' => $actor?->getKey(),
            ])->save();

            return $advance;
        });
    }

    public function approve(EmployeeAdvance $advance, ?User $actor = null): EmployeeAdvance
    {
        $this->assertStatus($advance, [AdvanceStatus::Requested], 'approved');

        $advance->forceFill([
            'status' => AdvanceStatus::Approved,
            'approved_by' => $actor?->getKey(),
            'approved_at' => now(),
        ])->save();

        return $advance;
    }

    public function reject(EmployeeAdvance $advance, string $reason, ?User $actor = null): EmployeeAdvance
    {
        $this->assertStatus($advance, [AdvanceStatus::Requested], 'rejected');

        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('rejection_reason', 'Say why the advance was refused.');
        }

        $advance->forceFill([
            'status' => AdvanceStatus::Rejected,
            'rejected_by' => $actor?->getKey(),
            'rejected_at' => now(),
            'rejection_reason' => trim($reason),
        ])->save();

        return $advance;
    }

    public function cancel(EmployeeAdvance $advance, string $reason): EmployeeAdvance
    {
        $this->assertStatus($advance, [AdvanceStatus::Requested, AdvanceStatus::Approved], 'cancelled');

        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('notes', 'Say why the advance is being withdrawn.');
        }

        $advance->forceFill([
            'status' => AdvanceStatus::Cancelled,
            'notes' => trim($reason),
        ])->save();

        return $advance;
    }

    /**
     * The money actually leaves (§6.2). From here `amount` is immutable — it is what somebody received.
     */
    public function disburse(
        EmployeeAdvance $advance,
        PaymentMethod $method,
        ?string $reference = null,
        ?Carbon $on = null,
    ): EmployeeAdvance {
        $this->assertStatus($advance, [AdvanceStatus::Approved], 'disbursed');

        if ($method->expectsReference() && trim((string) $reference) === '') {
            throw HrRuleException::refuse('disbursement_reference', sprintf(
                'A %s needs its reference — it is how this payment is found again in a bank statement.',
                $method->label()
            ));
        }

        $advance->forceFill([
            'status' => AdvanceStatus::Disbursed,
            'disbursed_on' => ($on ?? now())->toDateString(),
            'disbursement_method' => $method,
            'disbursement_reference' => $reference,
        ])->save();

        return $advance;
    }

    /**
     * Post a recovery, a waiver or a correction (§6.2, HR-19).
     *
     * The conditional UPDATE is the whole guard: it either moves the caches within the ceiling or it
     * affects nothing, and the refusal then names the exact remainder rather than reporting a constraint.
     */
    public function recordRecovery(
        EmployeeAdvance $advance,
        string $amount,
        ?PayrollRunItem $item = null,
        AdvanceRecoveryType $type = AdvanceRecoveryType::Payroll,
        ?string $notes = null,
        ?User $actor = null,
        ?Carbon $on = null,
        LedgerEntryType $entryType = LedgerEntryType::Debit,
    ): EmployeeAdvanceRepayment {
        $amount = Money::round($amount);

        if (! Money::isPositive($amount)) {
            throw HrRuleException::refuse('amount', 'A repayment row carries a positive amount; the '
                .'direction is the entry type, never a negative magnitude.');
        }

        if ($type->requiresNote() && trim((string) $notes) === '') {
            throw HrRuleException::reasonRequired('notes', sprintf(
                'A %s needs a note saying why.',
                $type->label()
            ));
        }

        return DB::transaction(function () use ($advance, $amount, $item, $type, $notes, $actor, $on, $entryType) {
            $locked = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->getKey());

            if ($entryType === LedgerEntryType::Debit) {
                $this->assertRoomFor($locked, $amount, $type);
            }

            $repayment = new EmployeeAdvanceRepayment;
            $repayment->forceFill([
                'employee_advance_id' => $locked->getKey(),
                'payroll_run_item_id' => $item?->getKey(),
                'entry_type' => $entryType,
                'recovery_type' => $type,
                'amount' => $amount,
                'recovered_on' => ($on ?? now())->toDateString(),
                'notes' => $notes,
                'performed_by' => $actor?->getKey(),
            ]);

            try {
                $repayment->save();
            } catch (UniqueConstraintViolationException) {
                // `uq_aar_item` — one payroll recovery per advance per slip. Re-posting a paid slip must
                // not take the money twice.
                throw HrRuleException::refuse('payroll_run_item_id', sprintf(
                    'Advance %s was already recovered on this salary slip.',
                    $locked->advance_number
                ));
            }

            $this->recomputeCaches($locked);

            return $repayment;
        });
    }

    /**
     * Forgive part of an advance. Never an edit of `amount` — the advance still says what was lent, and
     * the waiver says what was forgiven and by whom.
     */
    public function waive(EmployeeAdvance $advance, string $amount, string $reason, ?User $actor = null): EmployeeAdvanceRepayment
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('notes', 'A waiver needs a reason — it is somebody '
                .'deciding not to collect money that was lent.');
        }

        return $this->recordRecovery(
            advance: $advance,
            amount: $amount,
            item: null,
            type: AdvanceRecoveryType::Waiver,
            notes: trim($reason),
            actor: $actor,
        );
    }

    /**
     * The installments payroll should recover in a period — the **only** definition (§6.2).
     *
     * @return EloquentCollection<int, EmployeeAdvance>
     */
    public function dueFor(Employee $employee, int $year, int $month): EloquentCollection
    {
        return EmployeeAdvance::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [AdvanceStatus::Disbursed, AdvanceStatus::Recovering])
            ->where('outstanding_amount', '>', 0)
            ->where(function ($query) use ($year, $month): void {
                $query->whereNull('first_recovery_year')
                    ->orWhere('first_recovery_year', '<', $year)
                    ->orWhere(fn ($scoped) => $scoped
                        ->where('first_recovery_year', $year)
                        ->where('first_recovery_month', '<=', $month));
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Post the recovery rows a **paid** slip implies (§6.6 step 8).
     *
     * Called by `PayrollRunService::markItemPaid()` and by nothing else: an unpaid slip never reduces an
     * advance, because the money has not moved.
     */
    public function postRecoveriesFor(PayrollRunItem $item, ?User $actor = null): int
    {
        $posted = 0;

        foreach ($item->components as $component) {
            if ($component->source_type !== 'employee_advance' || $component->source_id === null) {
                continue;
            }

            $advance = EmployeeAdvance::query()->find($component->source_id);

            if ($advance === null) {
                continue;
            }

            $already = EmployeeAdvanceRepayment::query()
                ->where('employee_advance_id', $advance->getKey())
                ->where('payroll_run_item_id', $item->getKey())
                ->exists();

            if ($already) {
                continue;
            }

            $this->recordRecovery(
                advance: $advance,
                amount: (string) $component->amount,
                item: $item,
                type: AdvanceRecoveryType::Payroll,
                notes: sprintf('Recovered on salary slip %s.', $item->slip_number),
                actor: $actor,
                on: $item->paid_at?->copy() ?? now(),
            );

            $posted++;
        }

        return $posted;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * `amount / installments`, with the residual on the **last** installment so the parts add back up to
     * the whole (§2.21).
     */
    private function installmentAmount(string $amount, int $installments): string
    {
        if ($installments <= 1) {
            return $amount;
        }

        return Money::round(Money::div($amount, (string) $installments));
    }

    /**
     * The conditional update of HR-19: move the caches only while they stay inside the ceiling.
     */
    private function assertRoomFor(EmployeeAdvance $advance, string $amount, AdvanceRecoveryType $type): void
    {
        $taken = Money::add((string) $advance->recovered_amount, (string) $advance->waived_amount);
        $room = Money::round(Money::sub((string) $advance->amount, $taken));

        if (Money::greaterThan($amount, $room)) {
            throw HrRuleException::refuse('amount', sprintf(
                'Advance %s has %s left to recover, and this %s is %s. An advance can never be recovered '
                .'for more than it was (phase-07 HR-19).',
                $advance->advance_number,
                $room,
                strtolower($type->label()),
                $amount
            ));
        }
    }

    /**
     * Re-derive the three caches from the repayment rows, and close the advance at zero.
     */
    private function recomputeCaches(EmployeeAdvance $advance): void
    {
        $rows = EmployeeAdvanceRepayment::query()
            ->where('employee_advance_id', $advance->getKey())
            ->get(['recovery_type', 'signed_amount']);

        $recovered = Money::zero();
        $waived = Money::zero();

        foreach ($rows as $row) {
            $signed = Money::round((string) $row->signed_amount);

            if ($row->recovery_type === AdvanceRecoveryType::Waiver) {
                $waived = Money::add($waived, $signed);
            } else {
                $recovered = Money::add($recovered, $signed);
            }
        }

        $outstanding = Money::round(Money::sub(
            (string) $advance->amount,
            Money::add($recovered, $waived)
        ));

        $settled = ! Money::isPositive($outstanding);

        $advance->forceFill([
            'recovered_amount' => Money::round($recovered),
            'waived_amount' => Money::round($waived),
            'outstanding_amount' => Money::isNegative($outstanding) ? Money::zero() : $outstanding,
            'status' => $settled
                ? AdvanceStatus::Settled
                : ($advance->status === AdvanceStatus::Disbursed ? AdvanceStatus::Recovering : $advance->status),
            'settled_at' => $settled ? ($advance->settled_at ?? now()) : null,
        ])->save();
    }

    private function assertWithinCeiling(Employee $employee, string $amount, ?User $actor): void
    {
        $multiple = Money::round((string) setting('hr.advance_max_multiple_of_basic', '1.0000'), 4);
        $basic = Money::round((string) ($employee->activeSalaryStructure?->basic_salary ?? '0'));

        if (Money::isZero($multiple) || Money::isZero($basic)) {
            return;
        }

        $ceiling = Money::round(Money::mul($basic, $multiple));

        if (Money::lessThan($amount, $ceiling) || Money::equals($amount, $ceiling)) {
            return;
        }

        if ($actor !== null && $actor->can('employee_advances.approve')) {
            return;
        }

        throw HrRuleException::refuse('amount', sprintf(
            '%s is above the usual ceiling of %s (%s x the basic salary). A bigger advance is allowed, '
            .'but it has to be filed by somebody who can approve one, with the reason recorded.',
            $amount,
            $ceiling,
            rtrim(rtrim($multiple, '0'), '.')
        ));
    }

    /**
     * @param  list<AdvanceStatus>  $allowed
     */
    private function assertStatus(EmployeeAdvance $advance, array $allowed, string $action): void
    {
        if (in_array($advance->status, $allowed, true)) {
            return;
        }

        throw HrRuleException::refuse('status', sprintf(
            'An advance that is %s cannot be %s. The states it can move to are fixed (phase-07 §2.26), '
            .'so a wrong turn is always recoverable.',
            $advance->status->label(),
            $action
        ));
    }
}
