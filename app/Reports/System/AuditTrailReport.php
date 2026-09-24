<?php

declare(strict_types=1);

namespace App\Reports\System;

use App\DataObjects\Reporting\ActivityLogFilters;
use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ReportGroup;
use App\Models\User;
use App\Reports\Report;
use App\Services\Audit\AuditTrailService;
use App\Support\Modules;
use App\Support\ReportResult;

/**
 * `sys.audit_trail` - the 107 trail as an exportable report (phase-19-23 6.20).
 *
 * Gated on `audit_trail.view_logs`, which 4.1 made a module of its own precisely so a compliance
 * reader can be given old-and-new values without the whole operational log, and an operator can be
 * given the log without everyone's salary history.
 *
 * **A withheld figure carries its marker into the file.** {@see AuditTrailService::row()} renders
 * the changes as `field: old -> new` lines and substitutes `[withheld]` where the reader may not
 * see a figure - so an exported trail states what it is not showing rather than appearing complete.
 * An export that silently dropped the withheld values would be the one document where the omission
 * is invisible, because nobody reading a CSV can tell which cells were filtered.
 */
final class AuditTrailReport extends Report
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function key(): string
    {
        return 'sys.audit_trail';
    }

    public function title(): string
    {
        return 'Audit Trail';
    }

    public function description(): string
    {
        return 'What changed, from what to what, who changed it and why.';
    }

    public function icon(): string
    {
        return 'finger-print';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::System;
    }

    public function module(): string
    {
        return 'audit_trail';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['audit_trail.view_logs'];
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('activity_log.created_at', 'Changed on', required: true);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::datetime('when', 'When'),
            ColumnDefinition::text('who', 'Who'),
            ColumnDefinition::text('module', 'Module'),
            ColumnDefinition::text('record', 'Record'),
            ColumnDefinition::badge('event', 'Event'),
            ColumnDefinition::badge('sensitivity', 'Sensitivity'),
            ColumnDefinition::text('changes', 'What changed'),
            ColumnDefinition::text('reason', 'Reason given'),
        ];
    }

    public function filters(): array
    {
        return [
            // Defaults to `reports.audit_sensitive_modules` when left unset - the setting decides
            // what the trail opens on, never what it can reach.
            FilterDefinition::multiselect('modules', 'Module', static fn (): array => Modules::names()),
            FilterDefinition::entity('causer_id', 'Person', 'users.view_any'),
            FilterDefinition::text('search', 'Text contains'),
            FilterDefinition::boolean('with_reason', 'Only changes with a reason'),
        ];
    }

    public function groupBy(): array
    {
        return ['module' => 'Module', 'who' => 'Who', 'sensitivity' => 'Sensitivity'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $filters = ActivityLogFilters::fromArray([
            'from' => $request->range->start()->toDateString(),
            'to' => $request->range->end()->toDateString(),
            'preset' => 'custom',
            'modules' => (array) $request->filter('modules', []),
            'causer_id' => $request->filter('causer_id'),
            'search' => $request->filter('search'),
            'with_reason' => $request->booleanFilter('with_reason') === true,
        ]);

        $rows = [];
        $withheld = 0;

        $this->audit->exportQuery($filters, $viewer)
            ->reorder('activity_log.id')
            ->chunkById(300, function ($activities) use (&$rows, &$withheld, $columns, $viewer): void {
                foreach ($activities as $activity) {
                    $row = $this->audit->row($activity, $viewer);

                    if (str_contains((string) $row['changes'], AuditTrailService::WITHHELD)) {
                        $withheld++;
                    }

                    $rows[] = $this->row($columns, $row);
                }
            }, 'activity_log.id', 'id');

        return new ReportResult(
            rows: $rows,
            meta: [
                'basis' => 'recorded changes with a before and an after',
                // Counted and printed: a reader should be told how much of what they are holding is
                // masked, rather than having to notice the markers themselves.
                'withheld_rows' => $withheld,
            ],
        );
    }
}
