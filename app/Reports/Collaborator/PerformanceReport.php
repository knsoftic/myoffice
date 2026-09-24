<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\CollaborationType;
use App\Enums\CollaboratorStatus;
use App\Enums\ReferralSubject;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Collaborator\Collaborator;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.performance` - one line per collaborator, from visit to payout (requirement 99).
 *
 * **The whole funnel in one row.** Visits, referrals, what those referrals were worth, what was
 * earned on them and what has actually been paid - because the question this report answers is
 * "is this partnership working", and that cannot be answered from any one of those numbers alone.
 * A collaborator with two hundred visits and no conversions and one with two conversions and no
 * visits are different problems.
 *
 * **The money comes from the wallet, not from a sum over the ledger.** `lifetime_earned`,
 * `available_balance` and `total_paid_out` are the wallet's own cached figures, and CLAUDE.md 5
 * guarantees they are re-derivable by summing the ledger - there is a reconciliation sweep whose
 * entire job is to keep that true. Summing the ledger here would be a second derivation of a figure
 * that already has one, and the two would differ exactly when the sweep had found a problem, which
 * is the worst possible moment for a report to disagree with the wallet.
 */
final class PerformanceReport extends Report
{
    public function key(): string
    {
        return 'co.performance';
    }

    public function title(): string
    {
        return 'Collaborator Performance';
    }

    public function description(): string
    {
        return 'Every collaborator from visits through referrals to commission earned and paid.';
    }

    public function icon(): string
    {
        return 'chart-bar';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Collaborator;
    }

    public function module(): string
    {
        return 'collaborators';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('collaborators.joining_date', 'Joined on');
    }

    public function columns(): array
    {
        $money = static fn (string $k, string $l): ColumnDefinition => ColumnDefinition::money($k, $l, 'collaborator_commissions');

        return [
            ColumnDefinition::link('collaborator', 'Collaborator'),
            ColumnDefinition::text('code', 'Code'),
            ColumnDefinition::badge('type', 'Type'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::number('visits', 'Visits', 'sum'),
            ColumnDefinition::number('students', 'Students referred', 'sum'),
            ColumnDefinition::number('projects', 'Projects referred', 'sum'),
            $money('project_value', 'Project value'),
            $money('earned', 'Commission earned'),
            $money('paid', 'Paid'),
            $money('available', 'Available'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', CollaboratorStatus::options()),
            FilterDefinition::multiselect('collaboration_type', 'Type', CollaborationType::options()),
            FilterDefinition::select('branch_id', 'Branch', static fn (): array => Branch::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function groupBy(): array
    {
        return ['type' => 'Type', 'status' => 'Status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $from = $request->range->start()->toDateString();
        $to = $request->range->end()->toDateString();

        $query = Collaborator::query()
            ->with('wallet')
            ->withCount([
                // Counted inside the period, because "how did this partner do in March" is the
                // question - a lifetime visit count would make every month look identical.
                'referralVisits as visits_count' => static fn ($q) => $q->whereBetween('created_at', [$from, $to]),
                'referrals as students_count' => static fn ($q) => $q
                    ->where('subject_type', ReferralSubject::Student->value)
                    ->whereBetween('referral_date', [$from, $to]),
                'referrals as projects_count' => static fn ($q) => $q
                    ->where('subject_type', ReferralSubject::Project->value)
                    ->whereBetween('referral_date', [$from, $to]),
            ]);

        if ($request->hasFilter('status')) {
            $query->whereIn('collaborators.status', (array) $request->filter('status'));
        }

        if ($request->hasFilter('collaboration_type')) {
            $query->whereIn('collaborators.collaboration_type', (array) $request->filter('collaboration_type'));
        }

        $rows = [];
        $totals = [
            'visits' => 0,
            'students' => 0,
            'projects' => 0,
            'project_value' => Money::ZERO,
            'earned' => Money::ZERO,
            'paid' => Money::ZERO,
            'available' => Money::ZERO,
        ];

        $wantsValue = in_array('project_value', $columns, true);

        $query->orderBy('collaborators.id')->chunkById(200, function ($collaborators) use (&$rows, &$totals, $columns, $wantsValue, $from, $to): void {
            foreach ($collaborators as $collaborator) {
                $wallet = $collaborator->wallet;

                // The value of the projects this partner brought in, which is a different figure
                // from what they earned on them - a low commission rate on large projects and a
                // high one on small projects look identical without it.
                $projectValue = $wantsValue
                    // Both columns qualified: once `projects` is joined, `referral_date` and
                    // `subject_type` are ambiguous and MariaDB refuses the query outright.
                    ? (string) ($collaborator->referrals()
                        ->join('projects', 'projects.id', '=', 'collaborator_referrals.project_id')
                        ->where('collaborator_referrals.subject_type', ReferralSubject::Project->value)
                        ->whereBetween('collaborator_referrals.referral_date', [$from, $to])
                        ->sum('projects.net_value') ?? '0')
                    : '0';

                $figures = [
                    'project_value' => Money::of($projectValue),
                    // The wallet's own cached figures - see the class note.
                    'earned' => (string) ($wallet?->lifetime_earned ?? '0.00'),
                    'paid' => (string) ($wallet?->total_paid_out ?? '0.00'),
                    'available' => (string) ($wallet?->available_balance ?? '0.00'),
                ];

                $rows[] = $this->row($columns, [
                    'collaborator' => $collaborator->name,
                    'code' => $collaborator->collaborator_code,
                    'type' => $collaborator->collaboration_type?->label(),
                    'status' => $collaborator->status?->label(),
                    'visits' => $collaborator->visits_count,
                    'students' => $collaborator->students_count,
                    'projects' => $collaborator->projects_count,
                    ...$figures,
                ]);

                $totals['visits'] += (int) $collaborator->visits_count;
                $totals['students'] += (int) $collaborator->students_count;
                $totals['projects'] += (int) $collaborator->projects_count;

                foreach (['project_value', 'earned', 'paid', 'available'] as $key) {
                    $totals[$key] = Money::add($totals[$key], $figures[$key] ?: '0');
                }
            }
        }, 'collaborators.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'referrals in the period; money from the wallet'],
        );
    }
}
