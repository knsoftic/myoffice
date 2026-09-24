<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Enums\ReportGroup;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.paid_commission` - money that actually left the business (requirement 99).
 *
 * **Paid payouts only**, which is what separates this from `co.payout_history`. This report answers
 * "what did we pay out in March" - a cash question - and a requested-but-unpaid payout is not cash.
 *
 * **`entry_count` is the payout's own stored count of the ledger entries it settled**, maintained by
 * `PayoutService` when the allocations are written. Counting `collaborator_payout_allocations` here
 * would be a second count of the same thing, and the allocations are append-only precisely so the
 * stored figure can be trusted.
 */
final class PaidCommissionReport extends Report
{
    public function key(): string
    {
        return 'co.paid_commission';
    }

    public function title(): string
    {
        return 'Paid Commission';
    }

    public function description(): string
    {
        return 'Payouts that have actually been paid, with the method, the reference and how many ledger entries each settled.';
    }

    public function icon(): string
    {
        return 'banknotes';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Collaborator;
    }

    public function module(): string
    {
        return 'collaborator_payouts';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('collaborator_payouts.paid_on', 'Paid on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::text('payout_no', 'Payout no'),
            ColumnDefinition::money('amount', 'Amount', 'collaborator_payouts'),
            ColumnDefinition::badge('method', 'Method'),
            ColumnDefinition::date('paid_on', 'Paid on'),
            ColumnDefinition::text('reference', 'Reference'),
            ColumnDefinition::number('entries', 'Entries settled', 'sum'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('collaborator_id', 'Collaborator', static fn (): array => Collaborator::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('method', 'Method', PayoutMethod::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator', 'method' => 'Method'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = CollaboratorPayout::query()
            ->with('collaborator:id,name')
            ->where('collaborator_payouts.status', PayoutStatus::Paid->value)
            ->whereNotNull('collaborator_payouts.paid_on')
            ->whereBetween('collaborator_payouts.paid_on', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('collaborator_id')) {
            $query->where('collaborator_payouts.collaborator_id', (int) $request->filter('collaborator_id'));
        }

        if ($request->hasFilter('method')) {
            $query->whereIn('collaborator_payouts.method', (array) $request->filter('method'));
        }

        $rows = [];
        $totals = ['amount' => Money::ZERO, 'entries' => 0];

        $query->orderBy('collaborator_payouts.id')->chunkById(300, function ($payouts) use (&$rows, &$totals, $columns): void {
            foreach ($payouts as $payout) {
                $rows[] = $this->row($columns, [
                    'collaborator' => $payout->collaborator?->name,
                    'payout_no' => $payout->payout_no,
                    'amount' => (string) $payout->amount,
                    'method' => $payout->method?->label(),
                    'paid_on' => app_date($payout->paid_on),
                    'reference' => $payout->transaction_id,
                    'entries' => $payout->entry_count,
                ]);

                $totals['amount'] = Money::add($totals['amount'], (string) ($payout->amount ?? '0'));
                $totals['entries'] += (int) ($payout->entry_count ?? 0);
            }
        }, 'collaborator_payouts.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'payouts actually paid'],
        );
    }
}
