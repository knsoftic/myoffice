<?php

declare(strict_types=1);

namespace App\Reports\System;

use App\DataObjects\Reporting\ActivityLogFilters;
use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ReportGroup;
use App\Models\Activity;
use App\Models\User;
use App\Reports\Report;
use App\Services\Audit\ActivityLogService;
use App\Support\Modules;
use App\Support\ReportResult;

/**
 * `sys.activity_log` - the 106 viewer as an exportable report (phase-19-23 6.20).
 *
 * **Not one of 99's 31.** The contract is explicit: this and `sys.audit_trail` "round out 106-108
 * and are **not** 99 reports", each gated by its own module rather than by `reports`. They exist
 * here so the log can be exported through the same engine, the same three formats and the same
 * queued-export path as everything else - rather than growing a second export mechanism that would
 * need its own row limit, its own retention and its own permission check.
 *
 * **Its `module()` is `activity_log`, not `reports`.** That is what makes a compliance reader with
 * `activity_log.view_logs` and no `reports.view_reports` able to reach it - and what keeps somebody
 * with the reports hub but no log permission out.
 *
 * Every row still passes through {@see ActivityLogService}, so the 9.5 module scope applies
 * identically here and on the screen. A report that queried `activity_log` directly would be a
 * second place for that scope to be forgotten.
 */
final class ActivityLogReport extends Report
{
    public function __construct(
        private readonly ActivityLogService $log,
    ) {}

    public function key(): string
    {
        return 'sys.activity_log';
    }

    public function title(): string
    {
        return 'Activity Log';
    }

    public function description(): string
    {
        return 'Who did what, when, from where - every recorded action in the period.';
    }

    public function icon(): string
    {
        return 'clipboard-document-list';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::System;
    }

    public function module(): string
    {
        return 'activity_log';
    }

    /**
     * `view_logs`, not `view_reports`.
     *
     * The base class would have derived `reports.view_reports` + `activity_log.view_reports`, and
     * neither is right: `activity_log` declares no `view_reports` ability at all, and requiring the
     * reports hub would lock out the compliance reader this report exists for.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['activity_log.view_logs'];
    }

    public function dateFilter(): ?DateFilter
    {
        return new DateFilter('activity_log.created_at', 'Happened on', required: true);
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::datetime('when', 'When'),
            ColumnDefinition::text('who', 'Who'),
            ColumnDefinition::badge('event', 'Event'),
            ColumnDefinition::text('description', 'What'),
            ColumnDefinition::text('module', 'Module'),
            ColumnDefinition::text('record', 'Record'),
            ColumnDefinition::text('ip_address', 'IP address'),
            ColumnDefinition::text('device', 'Device'),
            ColumnDefinition::text('reason', 'Reason given'),
        ];
    }

    public function filters(): array
    {
        return [
            FilterDefinition::multiselect('modules', 'Module', static fn (): array => Modules::names()),
            FilterDefinition::multiselect('events', 'Event', static fn (): array => self::events()),
            FilterDefinition::entity('causer_id', 'Person', 'users.view_any'),
            FilterDefinition::text('ip_address', 'IP address'),
            FilterDefinition::text('search', 'Text contains'),
            // The fastest way to find every deliberate override in a period, which is usually the
            // first thing an auditor asks for.
            FilterDefinition::boolean('with_reason', 'Only entries with a reason'),
        ];
    }

    public function groupBy(): array
    {
        return ['module' => 'Module', 'who' => 'Who', 'event' => 'Event'];
    }

    public function run(ReportRequest $request, array $columns, User $viewer): ReportResult
    {
        $filters = ActivityLogFilters::fromArray([
            'from' => $request->range->start()->toDateString(),
            'to' => $request->range->end()->toDateString(),
            'preset' => 'custom',
            'modules' => (array) $request->filter('modules', []),
            'events' => (array) $request->filter('events', []),
            'causer_id' => $request->filter('causer_id'),
            'ip_address' => $request->filter('ip_address'),
            'search' => $request->filter('search'),
            'with_reason' => $request->booleanFilter('with_reason') === true,
        ]);

        $rows = [];

        // Streamed rather than collected: a log export is the one that is genuinely large, and the
        // service hands back a builder precisely so this can chunk.
        $this->log->exportQuery($filters, $viewer)
            ->reorder('activity_log.id')
            ->chunkById(500, function ($activities) use (&$rows, $columns): void {
                foreach ($activities as $activity) {
                    $rows[] = $this->row($columns, $this->log->row($activity));
                }
            }, 'activity_log.id', 'id');

        return new ReportResult(
            rows: $rows,
            meta: ['basis' => 'activity log, scoped to the modules this reader may see'],
        );
    }

    /**
     * The events actually recorded, so the dropdown never offers one that matches nothing.
     *
     * @return array<string, string>
     */
    private static function events(): array
    {
        return Activity::query()
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->limit(100)
            ->pluck('event')
            ->mapWithKeys(static fn (mixed $e): array => [(string) $e => ucfirst(str_replace('_', ' ', (string) $e))])
            ->all();
    }
}
