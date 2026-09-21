<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DataObjects\Finance\ExpenseData;
use App\DataObjects\Finance\RefundData;
use App\Enums\FinanceCategoryType;
use App\Enums\IncomeStatus;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\FinanceReversal;
use App\Models\Finance\Income;
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
 * Money in that is neither a project payment nor a student fee (§29, phase-13 §6.4).
 *
 * Deliberately the mirror of {@see ExpenseService}, minus the approval step: income needs none, because
 * the money either arrived or it did not and there is nothing for a second person to agree with.
 *
 * **It is not a back door into the payment tables.** A project payment and a student fee belong to the
 * spine and fire the commission engine; a row here fires nothing. Recording a client's project payment
 * as "other income" would therefore look correct on every report and quietly pay no commission — which
 * is why the form's category list holds no project or fee category and the service refuses one.
 */
class IncomeService
{
    use RecordsFinanceReversals;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function record(ExpenseData $data, ?User $actor = null): Income
    {
        $key = $data->key();
        $existing = Income::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertCategory($data->financeCategoryId);
        $this->assertValueDate($data->valueDate, $actor);

        return $this->db->transaction(function () use ($data, $actor, $key): Income {
            $income = new Income;

            $income->forceFill([
                'income_no' => $this->numbers->next('finance.income_prefix', 'finance.income_next_number'),
                'idempotency_key' => $key,
                'finance_category_id' => $data->financeCategoryId,
                'branch_id' => $data->branchId,
                'context' => $data->context->value,
                'project_id' => $data->projectId,
                'client_id' => $data->clientId,
                'title' => mb_substr($data->title, 0, 150),
                'description' => $data->description,
                'received_from' => $data->counterparty === null ? null : mb_substr($data->counterparty, 0, 150),
                'amount' => $data->amount(),
                'received_on' => Carbon::parse($data->valueDate->toDateString(), Format::timezone())->toDateString(),
                'payment_method' => $data->paymentMethod->value,
                'payment_method_id' => $data->paymentMethodId,
                'reference_no' => $data->referenceNo,
                'receipt_path' => $data->receiptPath,
                'status' => IncomeStatus::Recorded->value,
                'notes' => $data->notes,
                'created_by' => $actor?->getKey(),
            ])->save();

            return $income->refresh();
        }, 3);
    }

    public function update(Income $income, ExpenseData $data, ?User $actor = null): Income
    {
        if ($income->status !== IncomeStatus::Recorded) {
            throw InvoiceRuleException::refuse('status', sprintf(
                '%s is voided. A voided row stays as the record of what happened; a correction is a new row.',
                (string) $income->income_no,
            ));
        }

        $this->assertCategory($data->financeCategoryId);
        $this->assertValueDate($data->valueDate, $actor);

        return $this->db->transaction(function () use ($income, $data, $actor): Income {
            $locked = $this->lock($income);

            $locked->forceFill([
                'finance_category_id' => $data->financeCategoryId,
                'branch_id' => $data->branchId,
                'context' => $data->context->value,
                'project_id' => $data->projectId,
                'client_id' => $data->clientId,
                'title' => mb_substr($data->title, 0, 150),
                'description' => $data->description,
                'received_from' => $data->counterparty === null ? null : mb_substr($data->counterparty, 0, 150),
                'amount' => $data->amount(),
                'received_on' => Carbon::parse($data->valueDate->toDateString(), Format::timezone())->toDateString(),
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

    public function void(Income $income, string $reason, User $actor): Income
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvoiceRuleException::reasonRequired('reason',
                'Voiding a recorded receipt takes a figure out of a report somebody may already have read.');
        }

        return $this->db->transaction(function () use ($income, $reason, $actor): Income {
            $locked = $this->lock($income);

            if ($locked->status === IncomeStatus::Voided) {
                return $locked;
            }

            $this->markVoided($locked, mb_substr($reason, 0, 255), $actor);

            return $locked->refresh();
        }, 3);
    }

    public function refund(Income $income, RefundData $data, ?User $actor = null): FinanceReversal
    {
        return $this->recordReversal($income, $data, $actor);
    }

    public function recomputeCaches(Income $income): Income
    {
        return $this->db->transaction(function () use ($income): Income {
            $locked = $this->lock($income);

            $sum = Money::of((string) ($this->db->table('finance_reversals')
                ->where('income_id', $locked->getKey())
                ->sum('amount') ?: Money::ZERO));

            $this->db->table('incomes')->where('id', $locked->getKey())->update([
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

    private function assertCategory(int $categoryId): void
    {
        $category = FinanceCategory::query()->find($categoryId);

        if ($category === null || $category->type !== FinanceCategoryType::Income) {
            throw InvoiceRuleException::refuse('finance_category_id',
                'That is not an income category. An expense category on a receipt would put the figure '
                .'on the wrong side of the profit-and-loss statement.');
        }
    }

    private function assertValueDate(CarbonInterface $date, ?User $actor): void
    {
        $today = Carbon::now(Format::timezone())->startOfDay();
        $value = Carbon::parse($date->toDateString(), Format::timezone())->startOfDay();

        if ($value->greaterThan($today)) {
            throw InvoiceRuleException::refuse('received_on',
                'Money that has not arrived yet has not been received. Record it on the day it does.');
        }

        $limit = (int) setting('finance.backdate_limit_days', 30);

        if ($limit > 0 && $value->lessThan($today->copy()->subDays($limit))
            && $actor?->can('income.change_status') !== true) {
            throw InvoiceRuleException::refuse('received_on', sprintf(
                'That is more than %d days ago, which lands it in a period somebody may already have '
                .'reported on.', $limit,
            ));
        }
    }

    private function markVoided(Income $income, string $reason, ?User $actor): void
    {
        $income->forceFill([
            'status' => IncomeStatus::Voided->value,
            'voided_by' => $actor?->getKey(),
            'voided_at' => now(),
            'void_reason' => $reason,
            'updated_by' => $actor?->getKey(),
        ])->save();
    }

    protected function voidAfterFullRefund(Model $target, string $reason, ?User $actor): void
    {
        if ($target instanceof Income && $target->status !== IncomeStatus::Voided) {
            $this->markVoided($target, $reason, $actor);
        }
    }

    private function lock(Income $income): Income
    {
        return Income::query()->whereKey($income->getKey())->lockForUpdate()->firstOrFail();
    }
}
