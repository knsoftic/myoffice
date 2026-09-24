<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\CommissionStatus;
use App\Enums\ReportGroup;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\User;
use App\Reports\Collaborator\Concerns\ReadsTheCommissionLedger;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.pending_commission` - what is earned but not yet payable (requirement 99).
 *
 * **One row per collaborator, and it answers a liability question.** The figure here is what the
 * business owes but has not released: commission that is earned and sitting behind
 * `collaborator.wallet_hold_days`, or waiting for an approval. A finance officer closing a month
 * needs one number per person, not one per ledger entry.
 *
 * **"Oldest entry" and "hold until" are the two columns that make it actionable.** A pending total
 * with no age is a number; a pending total whose oldest line is ninety days old is a question.
 *
 * **Reversals are included with their sign**, as everywhere in these reports - see
 * {@see ReadsTheCommissionLedger}. A pending balance that ignored a clawback would overstate the
 * liability, which on a report headed "what we owe" is the wrong direction to be wrong in.
 */
final class PendingCommissionReport extends Report
{
    use ReadsTheCommissionLedger;

    public function key(): string
    {
        return 'co.pending_commission';
    }

    public function title(): string
    {
        return 'Pending Commission';
    }

    public function description(): string
    {
        return 'Commission earned but not yet available to withdraw, per collaborator, with how long it has been waiting.';
    }

    public function icon(): string
    {
        return 'clock';
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
        return new DateFilter('transaction_date', 'Earned on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('collaborator', 'Collaborator'),
            ColumnDefinition::text('code', 'Code'),
            ColumnDefinition::number('entries', 'Pending entries', 'sum'),
            ColumnDefinition::money('pending_total', 'Pending total', 'collaborator_commissions'),
            ColumnDefinition::date('oldest_entry', 'Oldest entry'),
            ColumnDefinition::number('age_days', 'Age (days)'),
            ColumnDefinition::date('hold_until', 'Hold until'),
        ];
    }

    public function filters(): array
    {
        return [
            $this->collaboratorFilter(),
            FilterDefinition::select('age_bucket', 'Age', [
                'under_30' => 'Under 30 days',
                'd30_60' => '30 to 60 days',
                'd60_90' => '60 to 90 days',
                'over_90' => 'Over 90 days',
            ]),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        // Pending and approved are both "earned, not yet payable". `available` has been released and
        // belongs on the wallet, not here; `paid` has gone.
        $query = $this->ledgerQuery($request)
            ->whereIn('collaborator_commission_ledger_entries.status', [
                CommissionStatus::Pending->value,
                CommissionStatus::Approved->value,
            ]);

        $byCollaborator = [];

        $query->orderBy('collaborator_commission_ledger_entries.id')
            ->chunkById(500, function ($entries) use (&$byCollaborator): void {
                foreach ($entries as $entry) {
                    $key = (int) $entry->collaborator_id;

                    $byCollaborator[$key] ??= [
                        'collaborator' => $entry->collaborator?->name,
                        'code' => $entry->collaborator?->collaborator_code,
                        'entries' => 0,
                        'pending_total' => Money::ZERO,
                        'oldest_entry' => null,
                        'hold_until' => null,
                    ];

                    $byCollaborator[$key]['entries']++;
                    $byCollaborator[$key]['pending_total'] = Money::add(
                        $byCollaborator[$key]['pending_total'],
                        $this->signedAmount($entry),
                    );

                    $date = $entry->transaction_date;

                    if ($date !== null && ($byCollaborator[$key]['oldest_entry'] === null || $date->lessThan($byCollaborator[$key]['oldest_entry']))) {
                        $byCollaborator[$key]['oldest_entry'] = $date;
                    }

                    // The furthest hold is when the whole pending balance becomes payable - which is
                    // the date somebody actually needs. The earliest would be misleading: part of
                    // the balance releasing does not release the balance.
                    $hold = $entry->hold_until;

                    if ($hold !== null && ($byCollaborator[$key]['hold_until'] === null || $hold->greaterThan($byCollaborator[$key]['hold_until']))) {
                        $byCollaborator[$key]['hold_until'] = $hold;
                    }
                }
            }, 'collaborator_commission_ledger_entries.id', 'id');

        $bucket = $request->filter('age_bucket');

        $rows = [];
        $totals = ['entries' => 0, 'pending_total' => Money::ZERO];

        foreach ($byCollaborator as $entry) {
            $age = $entry['oldest_entry'] === null
                ? 0
                : (int) $entry['oldest_entry']->diffInDays(now(), absolute: true);

            if ($bucket !== null && ! $this->inBucket($age, (string) $bucket)) {
                continue;
            }

            $rows[] = $this->row($columns, [
                'collaborator' => $entry['collaborator'],
                'code' => $entry['code'],
                'entries' => $entry['entries'],
                'pending_total' => $entry['pending_total'],
                'oldest_entry' => app_date($entry['oldest_entry']),
                'age_days' => $age,
                'hold_until' => app_date($entry['hold_until']),
            ]);

            $totals['entries'] += $entry['entries'];
            $totals['pending_total'] = Money::add($totals['pending_total'], $entry['pending_total']);
        }

        // Oldest first: this is a list of things that have been waiting.
        usort($rows, static fn (array $a, array $b): int => ($b['age_days'] ?? 0) <=> ($a['age_days'] ?? 0));

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'ledger entries not yet available, net of reversals'],
        );
    }

    private function inBucket(int $age, string $bucket): bool
    {
        return match ($bucket) {
            'under_30' => $age < 30,
            'd30_60' => $age >= 30 && $age < 60,
            'd60_90' => $age >= 60 && $age < 90,
            'over_90' => $age >= 90,
            default => true,
        };
    }
}
