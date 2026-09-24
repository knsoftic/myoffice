<?php

declare(strict_types=1);

namespace App\Reports\Collaborator;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ProjectStatus;
use App\Enums\ReferralSubject;
use App\Enums\ReportGroup;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `co.referred_projects` - which projects came from which collaborator (requirement 99).
 *
 * The project half of `co.referred_students`, driven from the same attribution table for the same
 * reason.
 *
 * **Value, received and commission are three different questions** and all three are shown. A
 * project worth 500,000 that has been paid 50,000 has earned its referrer commission on 50,000 -
 * commission follows money received (CLAUDE.md 5), never the contract value - and a report showing
 * only the value would make every partner look owed far more than they are.
 */
final class ReferredProjectsReport extends Report
{
    public function key(): string
    {
        return 'co.referred_projects';
    }

    public function title(): string
    {
        return 'Referred Projects';
    }

    public function description(): string
    {
        return 'Projects brought in by each collaborator, what has been received against them and what was earned.';
    }

    public function icon(): string
    {
        return 'briefcase';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Collaborator;
    }

    public function module(): string
    {
        return 'collaborator_referrals';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('collaborator_referrals.referral_date', 'Referred on');
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::text('collaborator', 'Collaborator'),
            ColumnDefinition::link('project', 'Project'),
            ColumnDefinition::text('client', 'Client'),
            ColumnDefinition::money('value', 'Project value', 'projects'),
            ColumnDefinition::money('received', 'Received', 'project_payments'),
            ColumnDefinition::percent('rate', 'Rate'),
            ColumnDefinition::money('commission', 'Commission earned', 'collaborator_commissions'),
            ColumnDefinition::badge('status', 'Project status'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::select('collaborator_id', 'Collaborator', static fn (): array => Collaborator::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
            FilterDefinition::multiselect('status', 'Project status', ProjectStatus::options()),
        ];
    }

    public function groupBy(): array
    {
        return ['collaborator' => 'Collaborator', 'status' => 'Project status'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $query = CollaboratorReferral::query()
            ->where('collaborator_referrals.subject_type', ReferralSubject::Project->value)
            ->whereNotNull('collaborator_referrals.project_id')
            ->with(['collaborator:id,name', 'project:id,name,client_id,net_value,status,commission_rate', 'project.client:id,name'])
            ->whereBetween('collaborator_referrals.referral_date', [
                $request->range->start()->toDateString(),
                $request->range->end()->toDateString(),
            ]);

        if ($request->hasFilter('collaborator_id')) {
            $query->where('collaborator_referrals.collaborator_id', (int) $request->filter('collaborator_id'));
        }

        if ($request->hasFilter('status')) {
            $statuses = (array) $request->filter('status');
            $query->whereHas('project', static fn ($q) => $q->whereIn('status', $statuses));
        }

        $rows = [];
        $totals = ['value' => Money::ZERO, 'received' => Money::ZERO, 'commission' => Money::ZERO];

        $query->orderBy('collaborator_referrals.id')->chunkById(200, function ($referrals) use (&$rows, &$totals, $columns): void {
            foreach ($referrals as $referral) {
                $projectId = (int) $referral->project_id;

                $received = (string) (\Illuminate\Support\Facades\DB::table('project_payments')
                    ->where('project_id', $projectId)
                    ->whereNot('status', 'voided')
                    ->sum('net_received_amount') ?? '0');

                $commission = (string) (\Illuminate\Support\Facades\DB::table('collaborator_commission_ledger_entries')
                    ->where('collaborator_id', $referral->collaborator_id)
                    ->where('project_id', $projectId)
                    ->sum('signed_amount') ?? '0');

                $rows[] = $this->row($columns, [
                    'collaborator' => $referral->collaborator?->name,
                    'project' => $referral->project?->name,
                    'client' => $referral->project?->client?->name,
                    'value' => (string) ($referral->project?->net_value ?? '0.00'),
                    'received' => Money::of($received),
                    'rate' => $referral->project?->commission_rate,
                    'commission' => Money::of($commission),
                    'status' => $referral->project?->status?->label(),
                ]);

                $totals['value'] = Money::add($totals['value'], (string) ($referral->project?->net_value ?? '0'));
                $totals['received'] = Money::add($totals['received'], $received ?: '0');
                $totals['commission'] = Money::add($totals['commission'], $commission ?: '0');
            }
        }, 'collaborator_referrals.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'referral rows; commission follows money received'],
        );
    }
}
