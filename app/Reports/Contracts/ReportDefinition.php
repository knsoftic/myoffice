<?php

declare(strict_types=1);

namespace App\Reports\Contracts;

use App\DataObjects\Reporting\ChartDefinition;
use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExportFormat;
use App\Enums\ReportGroup;
use App\Support\ReportResult;

/**
 * One report, declared (phase-19-23 §6.20, [D-23-2]).
 *
 * **Reports are declarations, not controllers.** A new report is one class dropped into
 * `app/Reports/{Group}/` — no route, no controller method, no view. The registry discovers it, the
 * engine runs it, one screen renders it, and the exporter writes it out in four formats.
 *
 * **INV-23-1: `run()` delegates and never re-implements.** The figure on a report must be the same
 * figure the module's own screen shows, and the only way to guarantee that is for both to come from
 * the same service. A report that builds its own `SUM()` is a second source of truth for a number
 * the business already has one of, and the two will drift — usually quietly, usually in the
 * direction of the one nobody checks. Every `run()` below calls the service named in §99's *Source*
 * column and shapes what it gets back.
 *
 * **A report whose service does not exist yet renders an empty state, not a fabricated number**
 * ([D-P5-1]). Phase 23 declares all 31 whether or not every source has shipped; the ones whose
 * dependencies are missing say "this report arrives with Phase N" and return no rows. A plausible
 * zero is worse than a blank: somebody will read it as the answer.
 */
interface ReportDefinition
{
    /**
     * `in.pending_fees` — stable for ever: it is in routes, in `report_exports.report_key`, and in
     * whatever saved filters a user has bookmarked.
     */
    public function key(): string;

    public function title(): string;

    public function description(): string;

    public function icon(): string;

    public function group(): ReportGroup;

    /** The **source** module slug. Drives the module gate, and money columns' `view_financial`. */
    public function module(): string;

    /**
     * The stacked `can:` list, checked in full before anything runs.
     *
     * `reports.view_reports` plus the source module's own `view_reports`, plus `view_financial`
     * when a column is money (INV-23-2). Every one must be held — this is an AND, not an OR.
     *
     * @return list<string>
     */
    public function permissions(): array;

    /** @return list<FilterDefinition> */
    public function filters(): array;

    /** The named date column this report measures on. Null when the report is not time-bounded. */
    public function dateFilter(): ?DateFilter;

    /** @return list<ColumnDefinition> */
    public function columns(): array;

    /**
     * Keys this report may be grouped by.
     *
     * @return array<string, string> key => label
     */
    public function groupBy(): array;

    /**
     * Run it. **Delegates** to the service §99 names (INV-23-1).
     *
     * The request reaching here has already been narrowed: filters the viewer may not use are gone,
     * and `$columns` holds only the keys they are allowed. A `run()` that ignores that and selects
     * everything would put a withheld money column into the cache and the file.
     *
     * @param  list<string>  $columns  the column keys that survived the permission strip
     */
    public function run(ReportRequest $request, array $columns): ReportResult;

    /**
     * Which formats this report supports.
     *
     * @return list<ExportFormat>
     */
    public function formats(): array;

    /** The chart rendered above the table, or null. */
    public function chart(): ?ChartDefinition;

    /**
     * Is this report's source service available in this installation?
     *
     * False renders the [D-P5-1] empty state naming the phase that brings it, rather than a report
     * of zeroes somebody will read as the answer.
     */
    public function isAvailable(): bool;

    /** When unavailable, the sentence the empty state shows. Null when it is available. */
    public function unavailableReason(): ?string;
}
