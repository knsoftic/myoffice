<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DataObjects\Collaborator\BulkResult;
use App\DataObjects\Finance\ExpenseData;
use App\DataObjects\Finance\RefundData;
use App\Enums\ExpenseStatus;
use App\Enums\FinanceCategoryType;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\FinanceReversal;
use App\Models\User;
use App\Services\Finance\Concerns\RecordsFinanceReversals;
use App\Services\Finance\Exceptions\InvoiceRuleException;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money the business spent (phase-13 §6.4).
 *
 * **Only an approved expense counts in a report**, and the approval decision is snapshotted onto the
 * row at creation. That matters more than it looks: raising `finance.expense_approval_threshold` next
 * month must not silently re-open what was already auto-approved, and lowering it must not retroactively
 * make last week's small expenses look like they skipped a step nobody was asking for.
 *
 * **Nothing is ever deleted once it has been approved.** The only correction is a void with a written
 * reason and, where money genuinely came back, a `finance_reversals` row. An approved expense that
 * vanished would take its figure out of a profit-and-loss statement somebody has already read.
 */
class ExpenseService
{
    use RecordsFinanceReversals;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberService $numbers,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Recording one
    |--------------------------------------------------------------------------
    */

    /**
     * Exactly one row per idempotency key: a replayed POST returns what already exists.
     */
    public function record(ExpenseData $data, ?User $actor = null): Expense
    {
        $key = $data->key();
        $existing = Expense::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertCategory($data->financeCategoryId);
        $this->assertValueDate($data->valueDate, $actor);

        return $this->db->transaction(function () use ($data, $actor, $key): Expense {
            [$status, $approvalRequired] = $this->approvalDecision($data->amount());

            $expense = new Expense;

            $expense->forceFill([
                'expense_no' => $this->numbers->next('finance.expense_prefix', 'finance.expense_next_number'),
                'idempotency_key' => $key,
                'finance_category_id' => $data->financeCategoryId,
                'branch_id' => $data->branchId,
                'context' => $data->context->value,
                'project_id' => $data->projectId,
                'title' => mb_substr($data->title, 0, 150),
                'description' => $data->description,
                'paid_to' => $data->counterparty === null ? null : mb_substr($data->counterparty, 0, 150),
                'amount' => $data->amount(),
                'expense_date' => Carbon::parse($data->valueDate->toDateString(), Format::timezone())->toDateString(),
                'payment_method' => $data->paymentMethod->value,
                'payment_method_id' => $data->paymentMethodId,
                'reference_no' => $data->referenceNo,
                'receipt_path' => $data->receiptPath,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'status' => $status->value,
                // The decision, not the setting: a later change to either never rewrites why this row
                // was approved without anybody looking at it.
                'approval_required' => $approvalRequired,
                'approved_by' => $status === ExpenseStatus::Approved ? $actor?->getKey() : null,
                'approved_at' => $status === ExpenseStatus::Approved ? now() : null,
                'notes' => $data->notes,
                'created_by' => $actor?->getKey(),
            ])->save();

            return $expense->refresh();
        }, 3);
    }

    /**
     * Change one nobody has acted on yet.
     */
    public function update(Expense $expense, ExpenseData $data, ?User $actor = null): Expense
    {
        if ($expense->status !== ExpenseStatus::Pending) {
            throw InvoiceRuleException::refuse('status', sprintf(
                '%s is %s. An expense somebody has already decided about is corrected by voiding it and '
                .'re-entering it, so both the decision and the correction stay on the record.',
                (string) $expense->expense_no, strtolower($expense->status->label()),
            ));
        }

        if ($expense->isDerived()) {
            throw InvoiceRuleException::refuse('status', sprintf(
                '%s was created from a %s, not typed by anybody. Correcting it means correcting what it '
                .'came from.', (string) $expense->expense_no, str_replace('_', ' ', (string) $expense->source_type),
            ));
        }

        $this->assertCategory($data->financeCategoryId);
        $this->assertValueDate($data->valueDate, $actor);

        return $this->db->transaction(function () use ($expense, $data, $actor): Expense {
            $locked = $this->lock($expense);

            $locked->forceFill([
                'finance_category_id' => $data->financeCategoryId,
                'branch_id' => $data->branchId,
                'context' => $data->context->value,
                'project_id' => $data->projectId,
                'title' => mb_substr($data->title, 0, 150),
                'description' => $data->description,
                'paid_to' => $data->counterparty === null ? null : mb_substr($data->counterparty, 0, 150),
                'amount' => $data->amount(),
                'expense_date' => Carbon::parse($data->valueDate->toDateString(), Format::timezone())->toDateString(),
                'payment_method' => $data->paymentMethod->value,
                'payment_method_id' => $data->paymentMethodId,
                'reference_no' => $data->referenceNo,
                'receipt_path' => $data->receiptPath ?? $locked->receipt_path,
                'notes' => $data->notes,
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Deciding about one
    |--------------------------------------------------------------------------
    */

    /**
     * Forward-only and idempotent, so a double-clicked bulk action cannot approve twice.
     *
     * Refuses self-approval unless the business has said otherwise. An expense somebody approved for
     * themselves is not an approval, and the setting exists for the one-person finance team where the
     * alternative is nothing ever being approved at all.
     */
    public function approve(Expense $expense, User $actor): Expense
    {
        return $this->db->transaction(function () use ($expense, $actor): Expense {
            $locked = $this->lock($expense);

            if ($locked->status === ExpenseStatus::Approved) {
                return $locked;
            }

            if ($locked->status !== ExpenseStatus::Pending) {
                throw InvoiceRuleException::refuse('status', sprintf(
                    '%s is %s and cannot be approved.', (string) $locked->expense_no, strtolower($locked->status->label()),
                ));
            }

            if ((int) $locked->created_by === (int) $actor->getKey()
                && ! setting('finance.expense_self_approval_allowed', false)) {
                throw InvoiceRuleException::refuse('status',
                    'You recorded this expense, so you cannot also approve it. Somebody else has to look '
                    .'at it — that is what the approval step is for.');
            }

            $locked->forceFill([
                'status' => ExpenseStatus::Approved->value,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    public function reject(Expense $expense, string $reason, User $actor): Expense
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Rejecting an expense means telling somebody their claim was refused. The reason is what '
                .'they are told.');
        }

        return $this->db->transaction(function () use ($expense, $reason, $actor): Expense {
            $locked = $this->lock($expense);

            if ($locked->status !== ExpenseStatus::Pending) {
                throw InvoiceRuleException::refuse('status', sprintf(
                    '%s is %s and cannot be rejected.', (string) $locked->expense_no, strtolower($locked->status->label()),
                ));
            }

            $locked->forceFill([
                'status' => ExpenseStatus::Rejected->value,
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => mb_substr($reason, 0, 255),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    /**
     * The only correction path for an approved expense.
     *
     * The row stays for ever and leaves every report. A corrected expense is re-entered and linked
     * through `corrects_expense_id`, so the two are one story rather than a disappearance and an
     * unexplained new figure.
     */
    public function void(Expense $expense, string $reason, User $actor): Expense
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Voiding an approved expense takes a figure out of a report somebody may already have '
                .'read. It is recorded with its reason.');
        }

        return $this->db->transaction(function () use ($expense, $reason, $actor): Expense {
            $locked = $this->lock($expense);

            if ($locked->status === ExpenseStatus::Voided) {
                return $locked;
            }

            if ($locked->status === ExpenseStatus::Rejected) {
                throw InvoiceRuleException::refuse('status',
                    'A rejected expense never became the company\'s money, so there is nothing to void.');
            }

            $this->markVoided($locked, mb_substr($reason, 0, 255), $actor);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Approve several, by explicit id.
     *
     * **Never "everything matching the filter"**: a filter re-evaluated on the server is a set whose
     * contents the person pressing the button never saw. Rows that moved since the page loaded are
     * reported by name and never forced.
     *
     * @param  list<int>  $ids
     */
    public function approveMany(array $ids, User $actor): BulkResult
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return new BulkResult([], [], Money::ZERO);
        }

        sort($ids);

        return $this->db->transaction(function () use ($ids, $actor): BulkResult {
            $expenses = Expense::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            $changed = [];
            $skipped = [];
            $total = Money::ZERO;

            foreach ($expenses as $expense) {
                if ($expense->status !== ExpenseStatus::Pending) {
                    $skipped[(int) $expense->getKey()] = sprintf('%s is %s — it moved after the page was loaded.',
                        (string) $expense->expense_no, strtolower($expense->status->label()));

                    continue;
                }

                if ((int) $expense->created_by === (int) $actor->getKey()
                    && ! setting('finance.expense_self_approval_allowed', false)) {
                    $skipped[(int) $expense->getKey()] = sprintf('%s is your own expense.', (string) $expense->expense_no);

                    continue;
                }

                $expense->forceFill([
                    'status' => ExpenseStatus::Approved->value,
                    'approved_by' => $actor->getKey(),
                    'approved_at' => now(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                $changed[] = (int) $expense->getKey();
                $total = Money::add($total, (string) $expense->amount);
            }

            return new BulkResult($changed, $skipped, $total);
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Money coming back
    |--------------------------------------------------------------------------
    */

    public function refund(Expense $expense, RefundData $data, ?User $actor = null): FinanceReversal
    {
        if ($expense->status === ExpenseStatus::Rejected) {
            throw InvoiceRuleException::refuse('status',
                'A rejected expense was never paid, so there is nothing to get back.');
        }

        return $this->recordReversal($expense, $data, $actor);
    }

    /**
     * `refunded_amount` equals the sum of its reversals. `net_amount` is generated, so it cannot
     * disagree once this is right.
     */
    public function recomputeCaches(Expense $expense): Expense
    {
        return $this->db->transaction(function () use ($expense): Expense {
            $locked = $this->lock($expense);

            $sum = Money::of((string) ($this->db->table('finance_reversals')
                ->where('expense_id', $locked->getKey())
                ->sum('amount') ?: Money::ZERO));

            $this->db->table('expenses')->where('id', $locked->getKey())->update([
                'refunded_amount' => $sum,
                'updated_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * What status a new expense starts in, and whether approval was ever going to be needed.
     *
     * @return array{0: ExpenseStatus, 1: bool}
     */
    private function approvalDecision(string $amount): array
    {
        if (! setting('finance.expense_approval_required', true)) {
            return [ExpenseStatus::Approved, false];
        }

        $threshold = Money::of((string) setting('finance.expense_approval_threshold', '0.00'));

        // A threshold of zero means everything needs approval; above it, only what exceeds it does.
        if (Money::isPositive($threshold) && Money::compare($amount, $threshold) <= 0) {
            return [ExpenseStatus::Approved, false];
        }

        return [ExpenseStatus::Pending, true];
    }

    private function assertCategory(int $categoryId): void
    {
        $category = FinanceCategory::query()->find($categoryId);

        if ($category === null || $category->type !== FinanceCategoryType::Expense) {
            throw InvoiceRuleException::refuse('finance_category_id',
                'That is not an expense category. An income category on an expense would put the figure '
                .'on the wrong side of the profit-and-loss statement.');
        }
    }

    /**
     * A future-dated expense is refused outright; a heavily back-dated one needs the approve ability.
     *
     * Money that has not been spent yet has not been spent, and a back-dated row lands in a period
     * somebody may already have reported on — which is a decision rather than a data-entry choice.
     */
    private function assertValueDate(CarbonInterface $date, ?User $actor): void
    {
        $today = Carbon::now(Format::timezone())->startOfDay();
        $value = Carbon::parse($date->toDateString(), Format::timezone())->startOfDay();

        if ($value->greaterThan($today)) {
            throw InvoiceRuleException::refuse('expense_date',
                'Money that has not gone out yet has not been spent. Record it on the day it does.');
        }

        $limit = (int) setting('finance.backdate_limit_days', 30);

        if ($limit > 0 && $value->lessThan($today->copy()->subDays($limit))
            && $actor?->can('expenses.approve') !== true) {
            throw InvoiceRuleException::refuse('expense_date', sprintf(
                'That is more than %d days ago, which lands it in a period somebody may already have '
                .'reported on. Somebody who can approve expenses has to record it.', $limit,
            ));
        }
    }

    private function markVoided(Expense $expense, string $reason, ?User $actor): void
    {
        $expense->forceFill([
            'status' => ExpenseStatus::Voided->value,
            'voided_by' => $actor?->getKey(),
            'voided_at' => now(),
            'void_reason' => $reason,
            'updated_by' => $actor?->getKey(),
        ])->save();
    }

    protected function voidAfterFullRefund(Model $target, string $reason, ?User $actor): void
    {
        if ($target instanceof Expense && $target->status !== ExpenseStatus::Voided) {
            $this->markVoided($target, $reason, $actor);
        }
    }

    private function lock(Expense $expense): Expense
    {
        return Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();
    }
}
