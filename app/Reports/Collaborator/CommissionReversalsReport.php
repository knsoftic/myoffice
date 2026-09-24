<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ReportGroup;
use App\Models\User;
use App\Reports\Collaborator\Concerns\ReadsTheCommissionLedger;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.commission_reversals` - every clawback, with the entry it undoes (requirement 99).
 *
 * **This is the report the immutability rule exists for.** CLAUDE.md 3 says a wrong commission is
 * corrected by inserting a reversing negative row that references the original, never by editing or
 * deleting. The consequence is that the whole correction history is readable - and this report is
 * where it is read. A system that corrected by `UPDATE` would have nothing to show here at all.
 *
 * **Every row names its original entry and its reason.** A negative amount with no explanation is
 * the thing a collaborator disputes; a negative amount that says "refund on receipt FP-0042,
 * recorded by Asma on 3 March" is one they can check.
 */
final class CommissionReversalsReport extends Report
{
    use ReadsTheCommissionLedger;

    public function key(): string
    {
        return 'co.commission_reversals';
    }

    public function title(): string
    {
        return 'Commission Reversals';
    }

    public function description(): string
    {
        return 'Every commission clawed back, the entry it reverses, the reason and who recorded it.';
    }

    public function icon(): string
    {
        return 'arrow-uturn-left';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Collaborator;
    }

    public function module(): string
    {
        return 'payment_reversals';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('transaction_date', 'Reversed on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('entry_id', 'Reversal id'),
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::text('original_entry', 'Reverses entry'),
            ColumnDefinition::money('amount', 'Amount', 'collaborator_commissions'),
            ColumnDefinition::text('reason', 'Reason'),
            ColumnDefinition::date('date', 'Date'),
            ColumnDefinition::text('actor', 'Recorded by'),
        ];
    }

    public function filters(): array
    {
        return [
            $this->collaboratorFilter(),
            FilterDefinition::text('reason', 'Reason contains'),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = $this->ledgerQuery($request)
            // A reversal is exactly a row that points at the entry it undoes. Filtering on a
            // negative amount instead would also catch adjustments, which are a different thing.
            ->whereNotNull('collaborator_commission_ledger_entries.reverses_entry_id');

        if ($request->hasFilter('reason')) {
            $query->where('collaborator_commission_ledger_entries.notes', 'like', '%'.$request->filter('reason').'%');
        }

        $rows = [];
        $totals = ['amount' => Money::ZERO];

        $query->orderBy('collaborator_commission_ledger_entries.id')
            ->chunkById(300, function ($entries) use (&$rows, &$totals, $columns): void {
                foreach ($entries as $entry) {
                    $rows[] = $this->row($columns, [
                        'entry_id' => (string) $entry->getKey(),
                        'collaborator' => $entry->collaborator?->name,
                        'original_entry' => '#'.$entry->reverses_entry_id,
                        'amount' => $this->signedAmount($entry),
                        'reason' => $entry->cancel_reason ?? $entry->notes,
                        'date' => app_date($entry->transaction_date),
                        'actor' => $entry->canceller?->name,
                    ]);

                    $totals['amount'] = Money::add($totals['amount'], $this->signedAmount($entry));
                }
            }, 'collaborator_commission_ledger_entries.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'reversing ledger entries'],
        );
    }
}
