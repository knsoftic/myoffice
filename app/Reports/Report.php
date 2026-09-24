<?php

declare(strict_types=1);

namespace App\Reports;

use App\DataObjects\Reporting\ChartDefinition;
use App\DataObjects\Reporting\ColumnDefinition;
use App\DataObjects\Reporting\DateFilter;
use App\DataObjects\Reporting\FilterDefinition;
use App\DataObjects\Reporting\ReportRequest;
use App\Enums\ExportFormat;
use App\Reports\Contracts\ReportDefinition;
use App\Support\ReportResult;

/**
 * What every one of the 31 reports inherits (phase-19-23 §6.20, [D-23-2]).
 *
 * A report subclass should be almost entirely `key()`, `title()`, `columns()`, `filters()` and a
 * `run()` that calls one service. Everything a report would otherwise have to remember lives here:
 *
 * **`permissions()` is derived, not typed.** INV-23-2 says the stack is `reports.view_reports` +
 * the source module's `view_reports` + `view_financial` when any column is money. Writing that list
 * out in 31 files is 31 chances to forget the third one — and forgetting it is invisible, because
 * the report still runs and just shows money to somebody who should not see it. So the base reads
 * the columns and builds the list. A report that needs something extra adds to it through
 * {@see self::extraPermissions()} rather than replacing it.
 *
 * **`isAvailable()` asks whether the source exists.** Phase 23 declares all 31 reports whether or
 * not every service has shipped. A report whose source is missing renders the [D-P5-1] empty state
 * naming the phase that brings it, and returns no rows — because a plausible zero is worse than a
 * blank. Somebody will read the zero as the answer.
 *
 * **`run()` delegates (INV-23-1).** The base does not provide one. That is deliberate: there is no
 * generic query to inherit, and a base-class `run()` returning an empty result would let a subclass
 * ship without a source and look like it worked.
 */
abstract class Report implements ReportDefinition
{
    /**
     * Service or model classes this report cannot run without.
     *
     * Named as strings rather than imported, because importing a class a phase has not shipped is
     * how an optional dependency becomes a fatal error at autoload time.
     *
     * @return list<class-string>
     */
    protected function requires(): array
    {
        return [];
    }

    /**
     * The phase that brings this report's source, for the empty state's sentence.
     *
     * Null means "it should be here" — and if `requires()` then finds a missing class, the empty
     * state says so without naming a phase rather than inventing one.
     */
    protected function arrivesWithPhase(): ?int
    {
        return null;
    }

    /**
     * Permissions this report needs beyond the derived three.
     *
     * @return list<string>
     */
    protected function extraPermissions(): array
    {
        return [];
    }

    public function description(): string
    {
        return '';
    }

    public function icon(): string
    {
        return $this->group()->icon();
    }

    /**
     * The stacked `can:` list (INV-23-2). Derived from the module and the columns.
     *
     * The `view_financial` entry is **not** added here even when the report has money columns, and
     * that is the subtle part: a viewer without it must still be able to open the report and see
     * every non-money column. Gating the whole report on `view_financial` would hide the student
     * register from anybody who may not see fees. The money columns gate themselves, one by one,
     * through {@see ColumnDefinition::isVisibleTo()}.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_unique([
            'reports.view_reports',
            $this->module().'.view_reports',
            ...$this->extraPermissions(),
        ]));
    }

    /**
     * Every money column's permission, for the engine to strip against.
     *
     * @return list<string>
     */
    public function financialPermissions(): array
    {
        $permissions = [];

        foreach ($this->columns() as $column) {
            if ($column->isFinancial() && $column->permission !== null) {
                $permissions[] = $column->permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    public function filters(): array
    {
        return [];
    }

    public function dateFilter(): ?DateFilter
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function groupBy(): array
    {
        return [];
    }

    /**
     * Every format, minus the ones this installation cannot produce.
     *
     * `ExportFormat::available()` is what removes `excel` when the package is absent — so a report
     * never has to know whether a spreadsheet writer is installed, and a format that would 500 is
     * never offered.
     *
     * @return list<ExportFormat>
     */
    public function formats(): array
    {
        return ExportFormat::available();
    }

    public function chart(): ?ChartDefinition
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return $this->missingDependency() === null;
    }

    public function unavailableReason(): ?string
    {
        $missing = $this->missingDependency();

        if ($missing === null) {
            return null;
        }

        $phase = $this->arrivesWithPhase();

        return $phase !== null
            ? sprintf('This report arrives with Phase %d. Its figures come from %s, which is not installed yet.', $phase, class_basename($missing))
            : sprintf('This report cannot run: %s is missing.', class_basename($missing));
    }

    /**
     * An empty result that explains itself.
     *
     * A subclass whose source is missing calls this from `run()` rather than returning zeroes. The
     * reason lands in `meta`, where the screen, the CSV header and the PDF footer all read it, so
     * the explanation travels with the file instead of living only on the page.
     */
    protected function unavailable(ReportRequest $request): ReportResult
    {
        return new ReportResult(
            meta: [
                'available' => false,
                'reason' => $this->unavailableReason() ?? 'This report is not available.',
                'report_key' => $this->key(),
                'preset' => $request->range->preset(),
            ],
        );
    }

    /**
     * Only the columns named, in the order this report declares them.
     *
     * Every `run()` calls this before shaping a row, so a column the engine stripped never reaches
     * the result — and the column order on screen, in the CSV and in the PDF is the report's own,
     * not the order the picker happened to submit.
     *
     * @param  list<string>  $keys
     * @return list<ColumnDefinition>
     */
    protected function only(array $keys): array
    {
        return array_values(array_filter(
            $this->columns(),
            static fn (ColumnDefinition $column): bool => in_array($column->key, $keys, true),
        ));
    }

    /**
     * Shape one source row into the report's row, keeping only the columns asked for.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function row(array $keys, array $values): array
    {
        $row = [];

        foreach ($this->columns() as $column) {
            if (in_array($column->key, $keys, true)) {
                $row[$column->key] = $values[$column->key] ?? null;
            }
        }

        return $row;
    }

    /**
     * The first `requires()` class that is not installed, or null.
     *
     * @return class-string|null
     */
    private function missingDependency(): ?string
    {
        foreach ($this->requires() as $class) {
            if (! class_exists($class) && ! interface_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Filters this report declares that the given viewer may not use.
     *
     * The engine calls this and hands the keys to `ReportRequest::without()`, so a stripped filter
     * is both absent from the bar and discarded from the submitted values — a filter somebody
     * cannot see must not still be narrowing their report.
     *
     * @return list<string>
     */
    public function filtersHiddenFrom(?\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        $hidden = [];

        foreach ($this->filters() as $filter) {
            if (! $filter->isVisibleTo($user)) {
                $hidden[] = $filter->key;
            }
        }

        return $hidden;
    }

    /**
     * Columns this viewer gets, and the labels of the ones withheld.
     *
     * @return array{0: list<ColumnDefinition>, 1: list<string>}
     */
    public function columnsFor(?\Illuminate\Contracts\Auth\Authenticatable $user): array
    {
        $visible = [];
        $omitted = [];

        foreach ($this->columns() as $column) {
            if ($column->isVisibleTo($user)) {
                $visible[] = $column;
            } else {
                $omitted[] = $column->label;
            }
        }

        return [$visible, $omitted];
    }
}
