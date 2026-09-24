<?php

declare(strict_types=1);

namespace App\Reports\SoftwareHouse;

use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ReportGroup;
use App\Models\Project\Project;
use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use App\Support\ReportResult;

/**
 * `sh.projects` - the project register (requirement 99).
 *
 * **9.5 step 4 is one call: `Project::visibleTo($viewer)`.** That scope is Phase 6's, it already
 * knows about membership, management and branch, and it is the same one the project list screen
 * applies. A report that rebuilt the rule would be a second place for it to be wrong, and the
 * second place is always the one that does not get updated when the rule changes.
 *
 * **`progress_percent` is read, not recalculated.** `ProjectProgressService` owns the derivation and
 * writes the column; a report that called `derivedFor()` per row would recompute a stored figure
 * hundreds of times and could still disagree with the number on the project's own page.
 *
 * **`collaborator_id` is a display snapshot** (R5, D37) and is labelled as such. It is shown because
 * 99 asks for it, and it is never used to scope, join or total anything.
 */
final class ProjectsReport extends Report
{
    public function key(): string
    {
        return 'sh.projects';
    }

    public function title(): string
    {
        return 'Projects';
    }

    public function description(): string
    {
        return 'Every project with its client, manager, progress, value and what has been received against it.';
    }

    public function icon(): string
    {
        return 'rectangle-stack';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::SoftwareHouse;
    }

    public function module(): string
    {
        return 'projects';
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('projects.start_date', 'Started on', [
            'projects.deadline' => 'Due on',
            'projects.completed_on' => 'Completed on',
            'projects.created_at' => 'Added on',
        ]);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::link('project', 'Project'),
            ColumnDefinition::text('client', 'Client'),
            ColumnDefinition::text('manager', 'Project manager'),
            ColumnDefinition::badge('type', 'Type'),
            ColumnDefinition::badge('status', 'Status'),
            ColumnDefinition::badge('priority', 'Priority'),
            ColumnDefinition::date('start', 'Start'),
            ColumnDefinition::date('deadline', 'Deadline'),
            ColumnDefinition::percent('progress', 'Progress'),
            ColumnDefinition::money('value', 'Value', 'projects'),
            ColumnDefinition::money('received', 'Received', 'projects'),
            ColumnDefinition::text('collaborator', 'Collaborator'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('status', 'Status', ProjectStatus::options()),
            FilterDefinition::multiselect('priority', 'Priority', Priority::options()),
            FilterDefinition::multiselect('type', 'Type', ProjectType::options()),
            FilterDefinition::entity('client_id', 'Client', 'clients.view_any'),
            FilterDefinition::entity('project_manager_id', 'Project manager', 'users.view_any'),
            FilterDefinition::entity('collaborator_id', 'Collaborator', 'collaborators.view_any'),
            FilterDefinition::boolean('overdue', 'Past its deadline'),
        ];
    }

    public function groupBy(): array
    {
        return ['status' => 'Status', 'client' => 'Client', 'manager' => 'Project manager', 'type' => 'Type'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $dateColumn = $this->dateFilter()->resolve($request->dateColumn);

        $query = Project::query()
            ->visibleTo($viewer)
            ->with(['client:id,name', 'projectManager:id,name'])
            ->whereBetween($dateColumn, [$request->range->start(), $request->range->end()]);

        foreach (['status' => 'status', 'priority' => 'priority', 'type' => 'project_type'] as $filter => $column) {
            if ($request->hasFilter($filter)) {
                $query->whereIn('projects.'.$column, (array) $request->filter($filter));
            }
        }

        foreach (['client_id', 'project_manager_id', 'collaborator_id'] as $filter) {
            if ($request->hasFilter($filter)) {
                $query->where('projects.'.$filter, (int) $request->filter($filter));
            }
        }

        if ($request->booleanFilter('overdue') === true) {
            // "Overdue" means a deadline in the past that nothing has closed. A completed project
            // whose deadline has passed is late history, not an open problem.
            $query->whereNotNull('projects.deadline')
                ->whereDate('projects.deadline', '<', now())
                ->whereNotIn('projects.status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value]);
        } elseif ($request->booleanFilter('overdue') === false) {
            $query->where(static fn ($q) => $q
                ->whereNull('projects.deadline')
                ->orWhereDate('projects.deadline', '>=', now())
                ->orWhereIn('projects.status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value]));
        }

        $wantsReceived = in_array('received', $columns, true);

        if ($wantsReceived) {
            // One subquery rather than a query per row: the payments are the report's second table
            // and an N+1 here is a report that times out on a busy year.
            $query->withSum(['payments as received_total' => static fn ($q) => $q->received()], 'net_received_amount');
        }

        $rows = [];
        $totals = ['value' => Money::ZERO, 'received' => Money::ZERO];

        $query->orderBy('projects.id')->chunkById(200, function ($projects) use (&$rows, &$totals, $columns, $wantsReceived): void {
            foreach ($projects as $project) {
                $received = $wantsReceived ? Money::of((string) ($project->received_total ?? '0')) : Money::ZERO;

                $rows[] = $this->row($columns, [
                    'project' => $project->name,
                    'client' => $project->client?->name,
                    'manager' => $project->projectManager?->name,
                    'type' => $project->project_type?->label(),
                    'status' => $project->status?->label(),
                    'priority' => $project->priority?->label(),
                    'start' => app_date($project->start_date),
                    'deadline' => app_date($project->deadline),
                    'progress' => $project->progress_percent,
                    'value' => (string) $project->net_value,
                    'received' => $received,
                    // A display snapshot, never a scope - see the class note.
                    'collaborator' => $project->collaborator_id !== null ? '#'.$project->collaborator_id : null,
                ]);

                $totals['value'] = Money::add($totals['value'], (string) ($project->net_value ?? '0'));
                $totals['received'] = Money::add($totals['received'], $received);
            }
        }, 'projects.id', 'id');

        return new ReportResult(
            rows: $rows,
            totals: array_intersect_key($totals, array_flip($columns)),
            meta: ['basis' => 'project register'],
        );
    }
}
