<?php

declare(strict_types=1);

namespace App\Services\Finance\Concerns;

use App\DataObjects\Finance\RefundData;
use App\Enums\ReversalType;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceReversal;
use App\Models\Finance\Income;
use App\Models\User;
use App\Services\Finance\Exceptions\InvoiceRuleException;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The §6.4.1 refund algorithm, shared by the expense and income services.
 *
 * One implementation because the two are the same act in opposite directions: money the business paid
 * out coming back, and money the business took in going back. Two copies would be two places for the
 * ceiling check to drift, and the ceiling is the whole point — a refund larger than what was spent is
 * not a refund, it is a figure nobody can reconcile.
 *
 * **Nothing here dispatches a commission job.** An expense or an other-income row has never produced a
 * ledger entry, so there is nothing for the engine to undo. That is the difference between this table
 * and the spine's `payment_reversals`, and it is why they are separate tables rather than one with a
 * nullable third target.
 */
trait RecordsFinanceReversals
{
    /**
     * Record money coming back on an expense or an other-income row.
     *
     * The ceiling is a **conditional UPDATE** rather than a read-then-write: two refunds submitted at
     * the same instant would both pass a `remaining` check computed a moment earlier, and the second
     * one would take the total past the amount. `chk_*_refund_ceiling` is the database's own backstop
     * behind it.
     */
    protected function recordReversal(Expense|Income $target, RefundData $data, ?User $actor = null): FinanceReversal
    {
        $amount = Money::of($data->amount);

        if (! Money::isPositive($amount)) {
            throw InvoiceRuleException::refuse('amount', 'A refund of nothing is not a refund.');
        }

        if (! in_array($data->type, self::ACCEPTED_REVERSAL_TYPES, true)) {
            throw InvoiceRuleException::refuse('type', sprintf(
                '%s is not something that happens to an expense or an other-income row.', $data->type->label(),
            ));
        }

        $key = $data->key();
        $existing = FinanceReversal::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->db->transaction(function () use ($target, $data, $amount, $actor, $key): FinanceReversal {
            $locked = $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $remaining = Money::sub((string) $locked->amount, (string) $locked->refunded_amount);

            if (Money::compare($amount, $remaining) > 0) {
                throw InvoiceRuleException::refuse('amount', sprintf(
                    '%s is more than the %s still outstanding on %s. Money cannot come back twice.',
                    Money::format($amount), Money::format($remaining), $this->targetReference($locked),
                ));
            }

            $reversal = FinanceReversal::allowDirectWrites(function () use ($locked, $data, $amount, $actor, $key): FinanceReversal {
                $row = new FinanceReversal;

                $row->forceFill([
                    // The spine's counter, deliberately: the business follows one reversal-voucher
                    // sequence across money received and money spent, not two that look alike.
                    'reversal_no' => $this->numbers->next(
                        'finance.payment_reversal_prefix', 'finance.payment_reversal_next_number',
                    ),
                    'idempotency_key' => $key,
                    'expense_id' => $locked instanceof Expense ? $locked->getKey() : null,
                    'income_id' => $locked instanceof Income ? $locked->getKey() : null,
                    'type' => $data->type->value,
                    'amount' => $amount,
                    'reason' => mb_substr(trim($data->reason), 0, 255),
                    'refund_method' => $data->method,
                    'reference_no' => $data->referenceNo,
                    'occurred_on' => ($data->refundedOn === null
                        ? Carbon::now(Format::timezone())
                        : Carbon::parse($data->refundedOn->toDateString(), Format::timezone()))->toDateString(),
                    'performed_by' => $actor?->getKey(),
                    // Snapshot: a deleted user must not make a reversal unattributable.
                    'performed_by_name' => mb_substr((string) ($actor?->name ?? 'System'), 0, 150),
                    'notes' => $data->notes,
                    'created_by' => $actor?->getKey(),
                ])->save();

                return $row->refresh();
            });

            $applied = $this->db->table($locked->getTable())
                ->where('id', $locked->getKey())
                ->whereRaw('refunded_amount + ? <= amount', [$amount])
                ->update([
                    'refunded_amount' => $this->db->raw('refunded_amount + '.$this->literal($amount)),
                    'updated_at' => now(),
                ]);

            if ($applied !== 1) {
                throw InvoiceRuleException::refuse('amount',
                    'The outstanding amount moved while this form was open. Nothing was written — open '
                    .'it again and the figure will be current.');
            }

            $locked->refresh();

            // Fully refunded, and the act was a full refund or a void: the row leaves every report, and
            // the reversal's reason becomes the void reason so there is one explanation rather than two.
            if ($this->isFullyUndone($locked, $data->type)) {
                $this->voidAfterFullRefund($locked, mb_substr(trim($data->reason), 0, 255), $actor);
            }

            return $reversal;
        }, 3);
    }

    /**
     * The four types that can happen to an expense or an other-income row.
     *
     * `cancellation` is absent on purpose: it belongs to the spine's transfer path, where money moves
     * from one charge to another, and there is nothing here for it to move to.
     *
     * @var list<ReversalType>
     */
    private const ACCEPTED_REVERSAL_TYPES = [
        ReversalType::FullRefund,
        ReversalType::PartialRefund,
        ReversalType::Void,
        ReversalType::Correction,
    ];

    private function isFullyUndone(Expense|Income $target, ReversalType $type): bool
    {
        return Money::compare((string) $target->refunded_amount, (string) $target->amount) >= 0
            && in_array($type, [ReversalType::FullRefund, ReversalType::Void], true);
    }

    /**
     * A bcmath string this class produced, quoted for a raw `SET x = x + ?` fragment.
     */
    private function literal(string $amount): string
    {
        return "'".Money::of($amount)."'";
    }

    private function targetReference(Expense|Income $target): string
    {
        return (string) ($target instanceof Expense ? $target->expense_no : $target->income_no);
    }

    /**
     * Each service says what "void" means for its own row, because the two carry different columns.
     */
    abstract protected function voidAfterFullRefund(Model $target, string $reason, ?User $actor): void;
}
