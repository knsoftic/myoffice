<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Enums\ExportFormat;
use App\Enums\ReportGroup;

/**
 * One report, described for one viewer (phase-19-23 §6.20 `describe()`).
 *
 * This is the JSON the filter bar and the column picker are built from, and it is **already
 * narrowed**: `ReportEngine::describe()` strips the columns and filters this person may not use
 * before building it. Nothing here names a permission or hints at a withheld column, because a
 * schema that said "and there is a `commission_amount` column you cannot see" would tell a reader
 * about a figure they were not shown (INV-23-2).
 *
 * `omittedColumns` is the deliberate exception, and it holds *labels*, not keys: the screen prints
 * "Some columns are not shown: Commission earned, Paid" so a reader knows the table is narrower
 * than the report's own definition — without being told what the values are. Knowing a column
 * exists is not knowing what is in it, and a total that is quietly missing a column is worse than
 * one that says so.
 */
final readonly class ReportSchema
{
    /**
     * @param  list<ColumnDefinition>  $columns  the ones this viewer gets
     * @param  list<FilterDefinition>  $filters  the ones this viewer may use
     * @param  list<string>  $omittedColumns  labels of columns withheld from this viewer
     * @param  list<string>  $groupBy  the allowed grouping keys
     * @param  list<ExportFormat>  $formats  the formats this report and this installation both support
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $icon,
        public ReportGroup $group,
        public string $module,
        public array $columns,
        public array $filters,
        public ?DateFilter $dateFilter = null,
        public ?ChartDefinition $chart = null,
        public array $omittedColumns = [],
        public array $groupBy = [],
        public array $formats = [],
    ) {}

    /** @return list<string> */
    public function columnKeys(): array
    {
        return array_map(static fn (ColumnDefinition $column): string => $column->key, $this->columns);
    }

    /** Was anything withheld from this viewer? */
    public function isNarrowed(): bool
    {
        return $this->omittedColumns !== [];
    }

    /** Does this report have any money in it, for this viewer? */
    public function hasFinancialColumns(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->isFinancial()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'group' => $this->group->value,
            'group_label' => $this->group->label(),
            'module' => $this->module,
            'columns' => array_map(static fn (ColumnDefinition $c): array => $c->toArray(), $this->columns),
            'filters' => array_map(static fn (FilterDefinition $f): array => $f->toArray(), $this->filters),
            'date_filter' => $this->dateFilter?->toArray(),
            'chart' => $this->chart?->toArray(),
            'omitted_columns' => $this->omittedColumns,
            'group_by' => $this->groupBy,
            'formats' => array_map(static fn (ExportFormat $f): array => [
                'value' => $f->value,
                'label' => $f->label(),
                'icon' => $f->icon(),
            ], $this->formats),
        ];
    }
}
