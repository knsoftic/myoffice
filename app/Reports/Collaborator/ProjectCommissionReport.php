<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\CommissionStatus;
use App\Enums\ReportGroup;
use App\Models\User;
use App\Reports\Collaborator\Concerns\ReadsTheCommissionLedger;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.project_commission` - commission earned on project payments (requirement 99).
 *
 * The mirror of `co.student_commission` over the other half of the same ledger. See
 * {@see ReadsTheCommissionLedger} for the rules both share.
 */
final class ProjectCommissionReport extends Report
{
    use ReadsTheCommissionLedger;

    public function key(): string
    {
        return 'co.project_commission';
    }

    public function title(): string
    {
        return 'Project Commission';
    }

    public function description(): string
    {
        return 'Every commission entry earned on a project payment, with the receipt it came from and the rate applied.';
    }

    public function icon(): string
    {
        return 'rectangle-stack';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Collaborator;
    }

    public function module(): string
    {
        return 'collaborator_commissions';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('transaction_date', 'Earned on', [
            'available_at' => 'Available on',
            'paid_at' => 'Paid on',
        ]);
    }

    public function columns(): array
    {
        $money = static fn (string $k, string $l): ColumnDefinition => ColumnDefinition::money($k, $l, 'collaborator_commissions');

        return [
            ColumnDefinition::text('entry_id', 'Ledger id'),
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::text('project', 'Project'),
            ColumnDefinition::text('payment', 'Payment'),
            $money('base', 'Base'),
            ColumnDefinition::percent('rate', 'Rate'),
            $money('amount', 'Commission'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::date('date', 'Date'),
        ];
    }

    public function filters(): array
    {
        return [
            $this->collaboratorFilter(),
            FilterDefinition::multiselect('status', 'Status', CommissionStatus::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator', 'status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = $this->ledgerQuery($request, $dateColumn)
            ->whereNotNull('collaborator_commission_ledger_entries.project_payment_id')
            ->with(['project:id,name']);

        $rows = [];
        $totals = ['base' => Money::ZERO, 'amount' => Money::ZERO];

        $query->orderBy('collaborator_commission_ledger_entries.id')
            ->chunkById(300, function ($entries) use (&$rows, &$totals, $columns): void {
                foreach ($entries as $entry) {
                    $rows[] = $this->row($columns, [
                        'entry_id' => (string) $entry->getKey(),
                        'collaborator' => $entry->collaborator?->name,
                        'project' => $entry->project?->name,
                        'payment' => $entry->project_payment_id !== null ? '#'.$entry->project_payment_id : null,
                        'base' => (string) $entry->base_amount,
                        'rate' => $entry->commission_rate,
                        'amount' => $this->signedAmount($entry),
                        'status' => $entry->status?->label(),
                        'date' => app_date($entry->transaction_date),
                    ]);

                    $totals['base'] = Money::add($totals['base'], (string) ($entry->base_amount ?? '0'));
                    $totals['amount'] = Money::add($totals['amount'], $this->signedAmount($entry));
                }
            }, 'collaborator_commission_ledger_entries.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'commission ledger, project payments, net of reversals'],
        );
    }
}
