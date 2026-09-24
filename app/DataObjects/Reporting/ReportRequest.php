<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * What was asked of a report (phase-19-23 §6.20).
 *
 * One object carried from the controller through the engine into the owning service, and stored
 * verbatim in `report_exports.filters` so a file can be explained and rebuilt months later.
 *
 * **It is readonly, and every narrowing returns a new one.** The engine strips filters the viewer
 * may not use ({@see self::without()}) before handing the request to the service — and because the
 * original is untouched, the stripped keys can still be named in `meta` so the reader is told the
 * report was narrowed rather than left to wonder why a number looks small.
 *
 * **`filters` holds only what was submitted.** An absent key means "not filtered", which is not the
 * same as an empty one: `['status' => []]` would narrow a multiselect to nothing and return an
 * empty report. {@see self::filter()} therefore treats `null`, `''` and `[]` alike as absent.
 */
final readonly class ReportRequest
{
    /**
     * @param  array<string, mixed>  $filters  submitted filter values, keyed by filter key
     * @param  string|null  $dateColumn  which column the range measures on; null = the report's default
     * @param  list<string>  $groupBy  grouping keys, checked against the report's own `groupBy()`
     * @param  list<string>  $columns  a column subset chosen in the picker; empty = all of them
     * @param  list<string>  $strippedFilters  keys removed because the viewer may not use them
     */
    public function __construct(
        public DateRange $range,
        public array $filters = [],
        public ?string $dateColumn = null,
        public ?string $sort = null,
        public string $direction = 'desc',
        public array $groupBy = [],
        public array $columns = [],
        public int $perPage = 50,
        public int $page = 1,
        public array $strippedFilters = [],
    ) {}

    /**
     * Build one from an HTTP request, using the institute's own default preset.
     *
     * The preset default comes from `reports.default_date_preset` rather than a literal, so an
     * institute that works in quarters is not handed "this month" by every report it opens.
     */
    public static function fromRequest(Request $request): self
    {
        $range = DateRange::make(
            preset: $request->string('preset')->toString() ?: (string) setting('reports.default_date_preset', 'month'),
            from: $request->input('from'),
            to: $request->input('to'),
        );

        $direction = strtolower((string) $request->input('direction', 'desc'));

        return new self(
            range: $range,
            filters: self::clean((array) $request->input('filters', [])),
            dateColumn: $request->input('date_column'),
            sort: $request->input('sort'),
            direction: in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc',
            groupBy: array_values(array_filter((array) $request->input('group_by', []), 'is_string')),
            columns: array_values(array_filter((array) $request->input('columns', []), 'is_string')),
            perPage: max(1, min(200, (int) $request->input('per_page', 50))),
            page: max(1, (int) $request->input('page', 1)),
        );
    }

    /**
     * Rebuild one from a stored `report_exports.filters` payload.
     *
     * The stored shape is whatever {@see self::toArray()} wrote, which is why the two live next to
     * each other: an export that cannot be reproduced is a number somebody has to take on trust.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $range = DateRange::make(
            preset: $payload['preset'] ?? null,
            from: $payload['from'] ?? null,
            to: $payload['to'] ?? null,
        );

        return new self(
            range: $range,
            filters: self::clean((array) ($payload['filters'] ?? [])),
            dateColumn: $payload['date_column'] ?? null,
            sort: $payload['sort'] ?? null,
            direction: ($payload['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            groupBy: array_values((array) ($payload['group_by'] ?? [])),
            columns: array_values((array) ($payload['columns'] ?? [])),
            perPage: max(1, min(200, (int) ($payload['per_page'] ?? 50))),
            page: max(1, (int) ($payload['page'] ?? 1)),
            strippedFilters: array_values((array) ($payload['stripped_filters'] ?? [])),
        );
    }

    /**
     * Drop the filters named, remembering that they were dropped.
     *
     * Used by the engine for filters the viewer may not use. Remembering matters: `meta` prints the
     * stripped keys, so a report that came back narrower than asked says so on its face.
     *
     * @param  list<string>  $keys
     */
    public function without(array $keys): self
    {
        if ($keys === []) {
            return $this;
        }

        $kept = array_diff_key($this->filters, array_flip($keys));

        // Only count a key as stripped if it was actually submitted — naming a filter the reader
        // never set would claim their report was narrowed when it was not.
        $actually = array_values(array_intersect($keys, array_keys($this->filters)));

        return new self(
            range: $this->range,
            filters: $kept,
            dateColumn: $this->dateColumn,
            sort: $this->sort,
            direction: $this->direction,
            groupBy: $this->groupBy,
            columns: $this->columns,
            perPage: $this->perPage,
            page: $this->page,
            strippedFilters: array_values(array_unique([...$this->strippedFilters, ...$actually])),
        );
    }

    /** The same request over a different page — the only thing pagination needs to change. */
    public function onPage(int $page): self
    {
        return new self(
            range: $this->range,
            filters: $this->filters,
            dateColumn: $this->dateColumn,
            sort: $this->sort,
            direction: $this->direction,
            groupBy: $this->groupBy,
            columns: $this->columns,
            perPage: $this->perPage,
            page: max(1, $page),
            strippedFilters: $this->strippedFilters,
        );
    }

    /**
     * The same request with no pagination — what an export runs.
     *
     * An export that honoured `perPage` would hand somebody the first fifty rows of a file they
     * asked to have all of.
     */
    public function unpaginated(): self
    {
        return new self(
            range: $this->range,
            filters: $this->filters,
            dateColumn: $this->dateColumn,
            sort: $this->sort,
            direction: $this->direction,
            groupBy: $this->groupBy,
            columns: $this->columns,
            perPage: PHP_INT_MAX,
            page: 1,
            strippedFilters: $this->strippedFilters,
        );
    }

    /**
     * One filter's value, or the fallback.
     *
     * `null`, `''` and `[]` all mean "not filtered". Treating an empty array as a real value is how
     * a multiselect nobody touched narrows a report to nothing.
     */
    public function filter(string $key, mixed $default = null): mixed
    {
        $value = $this->filters[$key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return $default;
        }

        return $value;
    }

    public function hasFilter(string $key): bool
    {
        return $this->filter($key) !== null;
    }

    /**
     * A tri-state boolean filter: true, false, or null for "not asked".
     *
     * {@see \App\Enums\ReportFilterType::isTriState()} explains why the third state has to survive:
     * "has outstanding" unset means every client, and "no" means only the ones who owe nothing.
     */
    public function booleanFilter(string $key): ?bool
    {
        $value = $this->filters[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /** @return list<int> */
    public function idsFilter(string $key): array
    {
        $value = $this->filter($key, []);

        return array_values(array_filter(array_map('intval', (array) $value)));
    }

    /**
     * A stable hash of everything that changes the figures — the cache key's second half.
     *
     * `page` and `perPage` are **in** it, because page two of a cached report is a different set of
     * rows. `strippedFilters` is in it too: the same request stripped differently for two viewers
     * is two different reports, and sharing a cache entry between them would leak the wider one.
     */
    public function fingerprint(): string
    {
        $filters = $this->filters;
        ksort($filters);

        return hash('sha256', json_encode([
            'preset' => $this->range->preset(),
            'from' => $this->range->start()->toDateString(),
            'to' => $this->range->end()->toDateString(),
            'date_column' => $this->dateColumn,
            'filters' => $filters,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'group_by' => $this->groupBy,
            'columns' => $this->columns,
            'per_page' => $this->perPage,
            'page' => $this->page,
            'stripped' => $this->strippedFilters,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The payload stored verbatim in `report_exports.filters`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->range->preset(),
            'from' => $this->range->start()->toDateString(),
            'to' => $this->range->end()->toDateString(),
            'filters' => $this->filters,
            'date_column' => $this->dateColumn,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'group_by' => $this->groupBy,
            'columns' => $this->columns,
            'per_page' => $this->perPage,
            'page' => $this->page,
            'stripped_filters' => $this->strippedFilters,
        ];
    }

    /**
     * Drop submitted keys that carry no value, so `filters` holds only what was actually asked.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function clean(array $filters): array
    {
        return array_filter(
            $filters,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
