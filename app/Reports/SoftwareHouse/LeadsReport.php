<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\InquirySource;
use App\Enums\LeadStatus;
use App\Enums\ReportGroup;
use App\Models\Crm\Lead;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.leads` - the pipeline (requirement 99).
 *
 * **Scoped by `Lead::visibleTo($viewer)`** - 9.5 step 4, Phase 5's own rule, unchanged.
 *
 * **"Stale" is a setting, not a number in this file.** `crm.stale_lead_days` is what the board, the
 * follow-up sweep and this filter all read, so a lead the board calls stale and a report that calls
 * it fresh can never happen.
 */
final class LeadsReport extends Report
{
    public function key(): string
    {
        return 'sh.leads';
    }

    public function title(): string
    {
        return 'Leads';
    }

    public function description(): string
    {
        return 'The pipeline: every lead with its source, owner, budget, next follow-up and how long it has been sitting.';
    }

    public function icon(): string
    {
        return 'funnel';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'leads';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('leads.created_at', 'Received on', [
            'leads.converted_at' => 'Converted on',
            'leads.last_contacted_at' => 'Last contacted on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('lead', 'Lead'),
            ColumnDefinition::text('company', 'Company'),
            ColumnDefinition::badge('source', 'Source'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::text('assignee', 'Owner'),
            ColumnDefinition::money('budget', 'Budget', 'leads'),
            ColumnDefinition::date('follow_up', 'Next follow-up'),
            ColumnDefinition::number('age', 'Age (days)'),
            ColumnDefinition::date('converted', 'Converted'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', LeadStatus::options()),
            FilterDefinition::multiselect('source', 'Source', InquirySource::options()),
            FilterDefinition::entity('assigned_to', 'Owner', 'users.view_any'),
            FilterDefinition::entity('service_id', 'Service', 'services.view_any'),
            FilterDefinition::boolean('stale', 'Untouched too long'),
        ];
    }

    public function groupBy(): array
    {
        return ['status' => 'Status', 'source' => 'Source', 'assignee' => 'Owner'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Lead::query()
            ->visibleTo($viewer)
            ->with('assignee:id,name')
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        if ($request->hasFilter('status')) {
            $query->whereIn('leads.status', (array) $request->filter('status'));
        }

        if ($request->hasFilter('source')) {
            $query->whereIn('leads.source', (array) $request->filter('source'));
        }

        foreach (['assigned_to', 'service_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('leads.'.$filter, (int) $request->filter($filter));
            }
        }

        $stale = $request->booleanFilter('stale');

        if ($stale !== null) {
            // The same key the board and the follow-up sweep read - see the class note.
            $cutoff = now()->subDays(max(1, (int) setting('crm.stale_lead_days', 7)));

            $query->where(static function ($q) use ($cutoff, $stale): void {
                if ($stale) {
                    $q->whereNull('leads.last_activity_at')->orWhere('leads.last_activity_at', '<', $cutoff);
                } else {
                    $q->whereNotNull('leads.last_activity_at')->where('leads.last_activity_at', '>=', $cutoff);
                }
            });
        }

        $rows = [];
        $totals = ['budget' => Money::ZERO, 'age' => 0];

        $query->orderBy('leads.id')->chunkById(200, function ($leads) use (&$rows, &$totals, $columns): void {
            foreach ($leads as $lead) {
                $rows[] = $this->row($columns, [
                    'lead' => $lead->name,
                    'company' => $lead->company,
                    'source' => $lead->source?->label(),
                    'status' => $lead->status?->label(),
                    'assignee' => $lead->assignee?->name,
                    'budget' => (string) ($lead->budget_amount ?? '0.00'),
                    'follow_up' => app_date($lead->follow_up_at),
                    // Age is measured to conversion when there was one, and to today when there was
                    // not: a lead that closed in March is not four hundred days old.
                    'age' => (int) $lead->created_at->diffInDays($lead->converted_at ?? now(), absolute: true),
                    'converted' => app_date($lead->converted_at),
                ]);

                $totals['budget'] = Money::add($totals['budget'], (string) ($lead->budget_amount ?? '0'));
            }
        }, 'leads.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key(['budget' => $totals['budget']], array_flip($columns)),
            meta: ['basis' => 'pipeline'],
        );
    }
}
