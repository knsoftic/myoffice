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
 * `co.payout_history` - the payout register, every status (requirement 99).
 *
 * **Every payout, not just the paid ones.** A rejected request and a cancelled one are part of the
 * history somebody is asking about when they ask what happened to their withdrawal - and the three
 * actor columns say who moved it at each step. `co.paid_commission` is the narrower report for
 * money that actually left.
 *
 * **Bank details are never selected.** `account_details_encrypted` holds them and
 * `account_last4` is what a person needs to recognise their own account; putting the full details
 * on an exportable report would spread them to every spreadsheet the file is ever copied into.
 */
final class PayoutHistoryReport extends Report
{
    public function key(): string
    {
        return 'co.payout_history';
    }

    public function title(): string
    {
        return 'Payout History';
    }

    public function description(): string
    {
        return 'Every payout request with who approved it, who paid it and what happened to the ones that did not go through.';
    }

    public function icon(): string
    {
        return 'clipboard-document-list';
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
        return new DateFilter('collaborator_payouts.requested_at', 'Requested on', [
            'collaborator_payouts.approved_at' => 'Approved on',
            'collaborator_payouts.paid_on' => 'Paid on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('payout_no', 'Payout no'),
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::date('requested', 'Requested'),
            ColumnDefinition::date('approved', 'Approved'),
            ColumnDefinition::date('paid', 'Paid'),
            ColumnDefinition::money('amount', 'Amount', 'collaborator_payouts'),
            ColumnDefinition::badge('method', 'Method'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::text('actor', 'Last actor'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('collaborator_id', 'Collaborator', static fn (): array => Collaborator::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('status', 'Status', PayoutStatus::options()),
            FilterDefinition::multiselect('method', 'Method', PayoutMethod::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator', 'status' => 'Status', 'method' => 'Method'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = CollaboratorPayout::query()
            ->with(['collaborator:id,name', 'paidBy:id,name', 'approvedBy:id,name', 'requestedBy:id,name'])
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        if ($request->hasFilter('collaborator_id')) {
            $query->where('collaborator_payouts.collaborator_id', (int) $request->filter('collaborator_id'));
        }

        foreach (['status' => 'status', 'method' => 'method'] as $filter => $column) {
            if ($request->hasFilter($filter)) {
                $query->whereIn('collaborator_payouts.'.$column, (array) $request->filter($filter));
            }
        }

        $rows = [];
        $totals = ['amount' => Money::ZERO];

        $query->orderBy('collaborator_payouts.id')->chunkById(300, function ($payouts) use (&$rows, &$totals, $columns): void {
            foreach ($payouts as $payout) {
                // The furthest step anybody took, which is what "who last touched this" means on a
                // register that shows every status.
                $actor = $payout->paidBy?->name ?? $payout->approvedBy?->name ?? $payout->requestedBy?->name;

                $rows[] = $this->row($columns, [
                    'payout_no' => $payout->payout_no,
                    'collaborator' => $payout->collaborator?->name,
                    'requested' => app_date($payout->requested_at),
                    'approved' => app_date($payout->approved_at),
                    'paid' => app_date($payout->paid_on),
                    'amount' => (string) $payout->amount,
                    'method' => $payout->method?->label(),
                    'status' => $payout->status?->label(),
                    'actor' => $actor,
                ]);

                $totals['amount'] = Money::add($totals['amount'], (string) ($payout->amount ?? '0'));
            }
        }, 'collaborator_payouts.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'payout register, every status'],
        );
    }
}
