<?php

declare(strict_types=1);

namespace App\Reports\Collaborator\Concerns;

use App\DataObjects\Reporting\FilterDefinition;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared by the five `co.*` reports that read `collaborator_commission_ledger_entries`.
 *
 * **The ledger is immutable and append-only** (CLAUDE.md 3, D16), which changes what a report
 * over it has to be careful about:
 *
 * - **A reversal is a negative row, not a deleted one.** Every total here is a sum of `amount`
 *   including the negatives, so a refunded commission nets to zero rather than disappearing. A
 *   report that filtered the negatives out would show money that was clawed back as if it had been
 *   earned, which is the one mistake that matters on a commission statement.
 *
 * - **`amount` is a magnitude and `signed_amount` is the figure.** This is the trap. `amount` is
 *   always positive - a 2,500 clawback is stored as `amount = 2500.00, entry_type = debit` - and the
 *   sign lives in the generated column `signed_amount`
 *   (`CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END`). A report that showed or
 *   totalled `amount` would print a clawback as earnings and overstate a statement by twice every
 *   reversal. **Every one of these reports reads {@see self::signedAmount()}**, never `amount`, and
 *   it is the same column the wallet aggregates over, so a report and a wallet balance cannot
 *   disagree.
 *
 * - **There is no `deleted_at`**, so no soft-delete scope to remember and none to forget.
 */
trait ReadsTheCommissionLedger
{
    /**
     * The ledger, scoped to the period and to whichever collaborator was asked for.
     *
     * @return Builder<CollaboratorCommissionLedgerEntry>
     */
    protected function ledgerQuery(\App\DataObjects\Reporting\ReportRequest $request, string $dateColumn = 'transaction_date'): Builder
    {
        $query = CollaboratorCommissionLedgerEntry::query()
            ->with(['collaborator:id,name,collaborator_code'])
            ->whereBetween('collaborator_commission_ledger_entries.'.$dateColumn, [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('collaborator_id')) {
            $query->where('collaborator_commission_ledger_entries.collaborator_id', (int) $request->filter('collaborator_id'));
        }

        if ($request->hasFilter('status')) {
            $query->whereIn('collaborator_commission_ledger_entries.status', (array) $request->filter('status'));
        }

        return $query;
    }

    /**
     * The signed figure for one ledger entry: positive for a credit, negative for a debit.
     *
     * Always this, never `$entry->amount` - see the class note. It exists as a method rather than
     * being read inline so that the rule has one place to be stated and one place to be got wrong.
     */
    protected function signedAmount(CollaboratorCommissionLedgerEntry $entry): string
    {
        return \App\Support\Money::of((string) ($entry->signed_amount ?? '0'));
    }

    /** The collaborator picker every one of these reports offers. */
    protected function collaboratorFilter(): FilterDefinition
    {
        return FilterDefinition::select(
            'collaborator_id',
            'Collaborator',
            static fn (): array => Collaborator::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
        );
    }
}
