<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\DataObjects\Reporting\ReportRequest;
use App\DataObjects\Reporting\ReportSchema;
use App\Enums\ExportFormat;
use App\Models\User;
use App\Reports\Contracts\ReportDefinition;
use App\Reports\Report;
use App\Services\Reporting\Exceptions\ExportFormatUnavailableException;
use App\Services\Reporting\Exceptions\ExportTooLargeException;
use App\Support\ReportRegistry;
use App\Support\ReportResult;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The one way a report is run (phase-19-23 §6.20, §9.5).
 *
 * **§9.5 names six steps in order, and this class is that order.** Every report goes through all
 * six, every time, whoever asks:
 *
 *   1. `reports.view_reports` — the hub gate.
 *   2. The report's module gate. A disabled module denies even a Super Admin (`Gate::before` step 1).
 *   3. The report's own `permissions()` stack, every entry, as an AND.
 *   4. **The source module's own isolation scope** — applied inside `run()`, by delegating to the
 *      service that owns the figure. This is the step that cannot live here: the engine does not
 *      know that a project is scoped by membership and a student by branch and course, and a
 *      generic `where('branch_id', …)` bolted on from outside would be wrong for most of the 31.
 *   5. The branch clause, likewise the delegate's.
 *   6. Column-level permission, **here**: a withheld column is absent from the SELECT and from the
 *      file (INV-23-2), which is why the surviving keys are passed *into* `run()` rather than
 *      filtered out of its result. Filtering afterwards would still have queried the value, cached
 *      it, and put it in the export the job wrote from the cache.
 *
 * **A filter the viewer may not use is stripped as well as hidden.** Hiding a control while still
 * honouring a submitted value would let somebody narrow a report by a dimension they were never
 * shown — and watch the row count change, which is an enumeration oracle. `ReportRequest::without()`
 * remembers which keys were dropped so `meta` can say the report was narrowed.
 *
 * **The cache key includes the viewer.** Two people with different scopes asking the same question
 * are asking two different questions, and one cache entry between them would hand the narrower
 * viewer the wider answer. The key is `(report, user, fingerprint)` and the fingerprint already
 * carries the stripped-filter list, so even two users with the same id-less request hash apart when
 * they were narrowed differently.
 */
final class ReportEngine
{
    public function __construct(
        private readonly ReportExportService $exports,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * Run a report for a viewer.
     *
     * @throws NotFoundHttpException the key is unknown
     * @throws AccessDeniedHttpException the module is off, or a permission is missing
     */
    public function run(string $key, ReportRequest $request, User $user): ReportResult
    {
        $definition = $this->authorise($key, $user);

        [$columns, $omitted] = $this->columnsFor($definition, $user);
        $request = $this->narrow($definition, $request, $user);

        if ($columns === []) {
            // Every column was withheld. Running the query would produce rows of nothing, and a
            // table of empty rows reads as "no data" when the truth is "not for you".
            return $this->stamp(
                new ReportResult(meta: ['withheld' => true]),
                $definition,
                $request,
                $columns,
                $omitted,
            );
        }

        if (! $definition->isAvailable()) {
            return $this->stamp(
                $this->unavailableResult($definition, $request),
                $definition,
                $request,
                $columns,
                $omitted,
            );
        }

        $result = $this->cached($definition, $request, $user, $columns);

        return $this->stamp($result, $definition, $request, $columns, $omitted);
    }

    /**
     * The JSON the filter bar and the column picker are built from.
     *
     * Already narrowed: nothing here names a permission or hints at a withheld value. The one
     * exception is `omittedColumns`, which carries *labels* so the screen can say the table is
     * narrower than the report's definition — knowing a column exists is not knowing what is in it,
     * and a total quietly missing a column is worse than one that says so.
     */
    public function describe(string $key, User $user): ReportSchema
    {
        $definition = $this->authorise($key, $user);

        [$columns, $omitted] = $this->columnsFor($definition, $user);

        $filters = array_values(array_filter(
            $definition->filters(),
            static fn ($filter): bool => $filter->isVisibleTo($user),
        ));

        $chart = $definition->chart()?->forColumns(
            array_map(static fn ($column): string => $column->key, $columns),
        );

        return new ReportSchema(
            key: $definition->key(),
            title: $definition->title(),
            description: $definition->description(),
            icon: $definition->icon(),
            group: $definition->group(),
            module: $definition->module(),
            columns: $columns,
            filters: $filters,
            dateFilter: $definition->dateFilter(),
            chart: $chart,
            omittedColumns: $omitted,
            groupBy: array_keys($definition->groupBy()),
            formats: $definition->formats(),
        );
    }

    /**
     * Export a report: stream it now, queue it, or refuse it.
     *
     * **Three ways out, and each is the right answer to a different size of question.** A report
     * small enough to build inside a request is built inside the request, because a queued file
     * somebody has to come back for is a worse answer to "give me these forty rows". Above
     * `reports.sync_row_limit` it becomes a `report_exports` row and a job, because a request that
     * streams for two minutes is a request that times out half-written and leaves a truncated CSV
     * that looks complete. Above `reports.export_max_rows` it is refused **naming the count**:
     * "too many rows" tells somebody nothing, and "your filters match 412,900 rows and the limit
     * is 200,000" tells them how much to narrow by.
     *
     * The branch is taken on a **counted** result rather than an estimate, which is why the report
     * is run here before the decision. A report that streamed 200,000 rows because somebody guessed
     * low is the failure this guards against, and an estimate would sometimes guess low.
     *
     * @throws ExportTooLargeException above `reports.export_max_rows`
     * @throws ExportFormatUnavailableException the format needs a package this server lacks
     *
     * @return Response|\App\Models\Reporting\ReportExport
     */
    public function export(string $key, ReportRequest $request, ExportFormat $format, User $user): mixed
    {
        // Re-authorised, deliberately: the export route is reached separately from the screen, and
        // a permission may have been withdrawn since the table was rendered.
        $definition = $this->authorise($key, $user);

        if (! in_array($format, $definition->formats(), true) || ! $format->isAvailable()) {
            throw ExportFormatUnavailableException::for($format);
        }

        $request = $request->unpaginated();

        $result = $this->run($key, $request, $user);
        $rows = $result->rowCount();

        $max = (int) setting('reports.export_max_rows', 200000);

        if ($rows > $max) {
            throw ExportTooLargeException::for($definition->title(), $rows, $max);
        }

        if ($rows <= $this->exports->syncLimitFor($definition)) {
            return $this->exporter->export(
                $result,
                $format,
                Str::slug($definition->title()),
                'admin.reports.print',
                ['definition' => $definition],
            );
        }

        return $this->exports->queue($definition, $request, $format, $user);
    }

    /**
     * Steps 1–3 of §9.5, in order, for one key.
     *
     * A key nobody may see is a **404**, not a 403: telling somebody a report exists that they may
     * not open is a small disclosure, and there is no reason to make it.
     */
    public function authorise(string $key, User $user): ReportDefinition
    {
        $definition = ReportRegistry::definition($key);

        if ($definition === null) {
            throw new NotFoundHttpException(sprintf('There is no report called [%s].', $key));
        }

        $gate = app(Gate::class)->forUser($user);

        // Step 1: the hub gate, stated separately from the report's own stack so the failure says
        // which of the two is missing.
        if (! $gate->allows('reports.view_reports')) {
            throw new AccessDeniedHttpException('You do not have permission to run reports.');
        }

        // Steps 2 and 3, both inside the registry's own rule, so the hub listing and a direct URL
        // can never disagree about what a person may open.
        if (! ReportRegistry::allows($user, $definition)) {
            throw new NotFoundHttpException(sprintf('There is no report called [%s].', $key));
        }

        return $definition;
    }

    /**
     * Step 6: the columns this viewer gets, and the labels of the ones withheld.
     *
     * @return array{0: list<\App\DataObjects\Reporting\ColumnDefinition>, 1: list<string>}
     */
    private function columnsFor(ReportDefinition $definition, User $user): array
    {
        if ($definition instanceof Report) {
            [$visible, $omitted] = $definition->columnsFor($user);
        } else {
            $visible = [];
            $omitted = [];

            foreach ($definition->columns() as $column) {
                $column->isVisibleTo($user) ? $visible[] = $column : $omitted[] = $column->label;
            }
        }

        return [$visible, $omitted];
    }

    /**
     * Drop the filters this viewer may not use — from the bar **and** from the submitted values.
     */
    private function narrow(ReportDefinition $definition, ReportRequest $request, User $user): ReportRequest
    {
        $hidden = [];

        foreach ($definition->filters() as $filter) {
            if (! $filter->isVisibleTo($user)) {
                $hidden[] = $filter->key;
            }
        }

        return $request->without($hidden);
    }

    /**
     * Run it, or hand back what the cache holds.
     *
     * `reports.cache_ttl_seconds` of 0 means no cache, and that is a real setting rather than a
     * degenerate one: an institute watching today's collections wants the number to move.
     *
     * A cache failure is never fatal. A report that 500s because Redis is down is worse than a
     * report that is merely slow, so a throwing store falls through to running the query.
     *
     * @param  list<\App\DataObjects\Reporting\ColumnDefinition>  $columns
     */
    private function cached(ReportDefinition $definition, ReportRequest $request, User $user, array $columns): ReportResult
    {
        $keys = array_map(static fn ($column): string => $column->key, $columns);
        $ttl = (int) setting('reports.cache_ttl_seconds', 300);

        if ($ttl <= 0) {
            return $definition->run($request, $keys, $user);
        }

        $cacheKey = $this->cacheKey($definition, $request, $user);

        try {
            $hit = Cache::get($cacheKey);

            if ($hit instanceof ReportResult) {
                return $hit->with(['cached' => true]);
            }
        } catch (Throwable) {
            return $definition->run($request, $keys, $user);
        }

        $result = $definition->run($request, $keys, $user);

        try {
            Cache::put($cacheKey, $result, $ttl);
        } catch (Throwable) {
            // Not cacheable. The answer is still right.
        }

        return $result;
    }

    /**
     * `(report, viewer, everything that changes the figures)`.
     *
     * The viewer is in it because two people with different scopes asking the same question are
     * asking two different questions — see the class note.
     */
    private function cacheKey(ReportDefinition $definition, ReportRequest $request, User $user): string
    {
        return sprintf(
            'reports.result.%s.%d.%s',
            $definition->key(),
            $user->getKey(),
            $request->fingerprint(),
        );
    }

    private function unavailableResult(ReportDefinition $definition, ReportRequest $request): ReportResult
    {
        return new ReportResult(meta: [
            'available' => false,
            'reason' => $definition->unavailableReason() ?? 'This report is not available.',
        ]);
    }

    /**
     * Stamp `meta` with everything a reader six months later would need to interpret the figures.
     *
     * Phase 13's `ReportResult` docblock puts it plainly: a screen, a CSV and a PDF built from one
     * result must not be able to disagree about what they are showing. That only works if the
     * result carries the answer, so the date column, the filters in force, the omitted columns and
     * the stripped filters all go here rather than being re-derived by each renderer.
     *
     * @param  list<\App\DataObjects\Reporting\ColumnDefinition>  $columns
     * @param  list<string>  $omitted
     */
    private function stamp(
        ReportResult $result,
        ReportDefinition $definition,
        ReportRequest $request,
        array $columns,
        array $omitted,
    ): ReportResult {
        $dateFilter = $definition->dateFilter();

        return $result->with([
            'report_key' => $definition->key(),
            'report_title' => $definition->title(),
            'group' => $definition->group()->value,
            'module' => $definition->module(),

            // What "this month" meant. The single most important line in the printed header.
            'date_column' => $dateFilter?->resolve($request->dateColumn),
            'date_label' => $dateFilter?->label,
            'preset' => $request->range->preset(),
            'from' => $request->range->start()->toDateString(),
            'to' => $request->range->end()->toDateString(),
            'range_label' => $request->range->label(),

            'filters' => $request->filters,
            'stripped_filters' => $request->strippedFilters,
            'omitted_columns' => $omitted,
            'columns' => array_map(static fn ($column): array => $column->toArray(), $columns),

            'row_count' => $result->rowCount(),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }
}
