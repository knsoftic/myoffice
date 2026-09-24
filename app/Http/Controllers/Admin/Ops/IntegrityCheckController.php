<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ops;

use App\Enums\IntegrityCheckStatus;
use App\Enums\IntegrityCheckSuite;
use App\Http\Controllers\Controller;
use App\Models\Ops\IntegrityCheckRun;
use App\Models\User;
use App\Policies\Ops\IntegrityCheckRunPolicy;
use App\Services\Ops\IntegrityCheckService;
use App\Support\CsvWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The register of proofs, and the one button that produces another (phase-24-25 §7.2, §8.6).
 *
 * **Every list on this screen is scoped before it is rendered, not while it is rendered.** §9
 * narrows the Accountant to the financial suites, and that narrowing is a `whereIn` on the base
 * query — so it survives pagination totals, a sort, an export and a typed URL. A view that merely
 * skipped rows would still tell an Accountant, through the row count on page 3, that six other
 * suites exist (`CLAUDE.md` §1.10).
 *
 * **A suite somebody may not see is a 404, never a 403** (§9). The difference is the whole point:
 * a 403 confirms that `/admin/integrity-checks/41` is a real run of a real suite, which is exactly
 * the fact the scoping withholds. So {@see self::show()} asks about the suite *before* it asks the
 * policy about the ability, and turns that first `false` into "no such run".
 *
 * **`findings` reach the view only when `integrity_checks.view_logs` says so.** The counts are a
 * verdict; the findings are a map of the database — table names, column names, route names, ids.
 * Admin holds `view` and not `view_logs` (§4.3), so this controller passes `null` rather than
 * passing the array and hoping the Blade wraps it in a `@can`. Data the view never receives cannot
 * leak through a copy button, a print stylesheet or a future refactor.
 *
 * Starting a run is `integrity_checks.create` plus the `integrity-run` limiter, and it is withheld
 * from Admin on purpose: whoever can produce evidence on demand can produce it until it says what
 * they want.
 */
final class IntegrityCheckController extends Controller
{
    /**
     * Columns a reader may sort by. Never raw input — this list reaches an `ORDER BY`.
     *
     * `checks_failed` is here because "show me the worst first" is the question somebody opens this
     * screen with, and sorting by status would order them alphabetically instead of by severity.
     */
    private const SORTABLE = [
        'started_at',
        'finished_at',
        'suite',
        'status',
        'duration_ms',
        'checks_failed',
        'triggered_by',
    ];

    /** Page sizes the select offers, mirroring the log viewers' set. */
    private const PER_PAGE_OPTIONS = [15, 25, 50, 100];

    /**
     * The register.
     */
    public function index(Request $request): View
    {
        $user = $this->actor($request);

        Gate::authorize('viewAny', IntegrityCheckRun::class);

        $policy = $this->policy();
        $visibleSuites = $policy->visibleSuites($user);

        $filters = $this->validateFilters($request, $visibleSuites);

        $sort = $this->sortColumn($filters['sort'] ?? null);
        $direction = $this->sortDirection($filters['direction'] ?? null);

        $base = $this->filtered($request, $filters, $visibleSuites);

        $runs = (clone $base)
            ->with('creator:id,name')
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage($filters))
            ->withQueryString();

        return view('admin.integrity-checks.index', [
            'runs' => $runs,
            'summary' => $this->summary($base),
            'latestPerSuite' => $this->latestPerSuite($visibleSuites),
            'sort' => $sort,
            'direction' => $direction,
            'suiteOptions' => $this->suiteOptions($visibleSuites),
            'statusOptions' => IntegrityCheckStatus::options(),
            'triggerOptions' => $this->triggerOptions(),
            'perPageOptions' => $this->perPageOptionsForSelect(),
            'hasFilters' => $this->hasActiveFilters($filters),
            // A narrowed reader is told *that* they are narrowed, without being told what to.
            // "Financial suites only" is an honest label; a silently short list is not.
            'narrowed' => ! $policy->seesEverySuite($user),
            'canRun' => Gate::allows('create', IntegrityCheckRun::class),
            'canExport' => Gate::allows('exportAny', IntegrityCheckRun::class),
            'runnableSuites' => Gate::allows('create', IntegrityCheckRun::class)
                ? $this->suiteOptions($visibleSuites)
                : [],
            'timezone' => $this->timezone($user),
        ]);
    }

    /**
     * Run a suite now, and record that it ran.
     *
     * Synchronous on purpose at this size: the service *is* the recorder, and a queued run would
     * leave the operator staring at a register that does not change while they wonder whether the
     * worker is alive — which is itself one of the things this screen exists to answer. `--suite=all`
     * runs nine commands and is genuinely slow; the `integrity-run` limiter (3/minute) and
     * `integrity_checks.create` being Super Admin's alone are what keep that from being a problem.
     * §10.5's queued job is the right home for it the day a suite outgrows a request.
     */
    public function store(Request $request, IntegrityCheckService $service): RedirectResponse
    {
        $user = $this->actor($request);

        Gate::authorize('create', IntegrityCheckRun::class);

        $policy = $this->policy();
        $visibleSuites = $policy->visibleSuites($user);

        /*
        | Validation belongs in a Form Request (CLAUDE.md §9). It is inline here only because this
        | slice may not add files outside its list; the rules are self-contained so lifting them
        | into `StoreIntegrityCheckRunRequest` is a move, not a rewrite.
        |
        | `suite` is validated against the suites this person may SEE, not against every case: a
        | reader narrowed to the financial suites must not be able to start — and therefore learn
        | the verdict of — a `security` run by posting its value.
        */
        $validated = $request->validate([
            'suite' => [
                'required',
                'string',
                Rule::in([...$this->suiteValues($visibleSuites), 'all']),
            ],
            'scope' => ['nullable', 'string', 'max:190'],
        ], [], [
            'suite' => 'check suite',
            'scope' => 'scope',
        ]);

        $scope = is_string($validated['scope'] ?? null) && trim($validated['scope']) !== ''
            ? trim($validated['scope'])
            : null;

        $context = ['scope' => $scope, 'trigger' => 'manual'];

        try {
            if ($validated['suite'] === 'all') {
                $runs = $service->runAll($context);
                $first = $runs[0] ?? null;

                $this->record($user, $first, 'all', $scope, count($runs));

                session()->flash('toast', [
                    'type' => $this->toastType($service->worst($runs)),
                    'title' => 'All checks ran',
                    'message' => $service->worst($runs)->label().' — '.count($runs).' suites recorded.',
                ]);

                return $first === null
                    ? redirect()->route('admin.integrity-checks.index')
                    : redirect()->route('admin.integrity-checks.index', ['run_uuid' => $first->run_uuid]);
            }

            $suite = IntegrityCheckSuite::from($validated['suite']);
            $run = $service->run($suite, $context);

            $this->record($user, $run, $suite->value, $scope, 1);

            session()->flash('toast', [
                'type' => $this->toastType($run->status),
                'title' => $suite->label(),
                'message' => $run->summary(),
            ]);

            return redirect()->route('admin.integrity-checks.show', $run);
        } catch (Throwable $exception) {
            /*
            | The service records a crashed *suite* as a failed run. This catch is for the layer
            | above that — the command could not be dispatched at all — and it must not leave the
            | operator with a blank page: "the check did not run" is itself a finding, and the one
            | most worth saying out loud.
            */
            report($exception);

            session()->flash('toast', [
                'type' => 'error',
                'title' => 'The check could not be started',
                'message' => 'Nothing was recorded. The application log has the detail.',
            ]);

            return redirect()->route('admin.integrity-checks.index');
        }
    }

    /**
     * One run: its verdict, its siblings from the same invocation, and — for whoever may read
     * them — its findings.
     */
    public function show(Request $request, IntegrityCheckRun $run): View
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        // Suite first, and a 404 rather than a 403 — see the class note and §9.
        abort_unless($policy->viewSuite($user, $run->suite), 404);

        Gate::authorize('view', $run);

        $canReadFindings = Gate::allows('viewFindings', $run);
        $findings = $canReadFindings ? $this->normaliseFindings($run) : null;

        return view('admin.integrity-checks.show', [
            'run' => $run->loadMissing('creator:id,name'),
            'siblings' => $this->siblings($run, $policy->visibleSuites($user)),
            // Null, not an empty array: the view has to be able to tell "no findings" from
            // "not yours to read", and it says different things about each.
            'findings' => $findings,
            // Built here, not in the Blade: the copy-as-CSV button (§8.6) needs one exact string,
            // and a view that assembles CSV is a view that will one day forget to escape a cell.
            'findingsCsv' => $findings === null ? null : $this->findingsCsv($findings),
            'canReadFindings' => $canReadFindings,
            'canExport' => Gate::allows('export', $run),
            'canRun' => Gate::allows('create', IntegrityCheckRun::class),
            'walletRouteExists' => RouteFacade::has('admin.wallets.show'),
            'timezone' => $this->timezone($user),
        ]);
    }

    /**
     * One run's evidence as CSV.
     *
     * The verdict and the counts always; the findings only with `integrity_checks.view_logs`. An
     * Accountant exporting a wallet proof for a dispute needs the statement "this reconciled on
     * this date", and that is what they get — not the column-by-column map underneath it (§4.1).
     */
    public function export(Request $request, IntegrityCheckRun $run): StreamedResponse
    {
        $user = $this->actor($request);
        $policy = $this->policy();

        abort_unless($policy->viewSuite($user, $run->suite), 404);

        Gate::authorize('export', $run);

        $includeFindings = Gate::allows('viewFindings', $run);
        $timezone = $this->timezone($user);

        $rows = [[
            'field' => 'Run',
            'value' => $run->uuid,
        ]];

        foreach ($this->evidenceLines($run, $timezone, $includeFindings) as $field => $value) {
            $rows[] = ['field' => $field, 'value' => $value];
        }

        if ($includeFindings) {
            foreach ($this->normaliseFindings($run) as $index => $finding) {
                $rows[] = [
                    'field' => 'Finding '.($index + 1),
                    'value' => implode(' | ', array_filter([
                        $finding['code'],
                        $finding['severity'],
                        $finding['subject'],
                        $finding['expected'] === null ? null : 'expected: '.$finding['expected'],
                        $finding['actual'] === null ? null : 'actual: '.$finding['actual'],
                    ], static fn (?string $part): bool => $part !== null && $part !== '')),
                ];
            }
        }

        $filename = 'integrity-'.$run->suite->value.'-'
            .CarbonImmutable::now($timezone)->format('Y-m-d-His').'.csv';

        return (new CsvWriter(withBom: true))->download(
            $filename,
            ['Field', 'Value'],
            $rows,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * The base query: scoped to the visible suites first, then filtered.
     *
     * The `whereIn` is unconditional even when every suite is visible. A scope that is applied
     * "only when needed" is a scope somebody later decides is not needed.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<IntegrityCheckSuite>  $visibleSuites
     * @return Builder<IntegrityCheckRun>
     */
    private function filtered(Request $request, array $filters, array $visibleSuites): Builder
    {
        $query = IntegrityCheckRun::query()
            ->whereIn('suite', $this->suiteValues($visibleSuites));

        if (($suite = $this->stringFilter($filters, 'suite')) !== null) {
            $query->forSuite($suite);
        }

        if (($status = $this->stringFilter($filters, 'status')) !== null) {
            $query->where('status', $status);
        }

        if (($trigger = $this->stringFilter($filters, 'triggered_by')) !== null) {
            $query->where('triggered_by', $trigger);
        }

        if (($runUuid = $this->stringFilter($filters, 'run_uuid')) !== null) {
            $query->forRun($runUuid);
        }

        if (($filters['blocking'] ?? null) === '1') {
            $query->blocking();
        }

        if (($from = $this->boundary($filters, 'from', false, $request)) !== null) {
            $query->where('started_at', '>=', $from);
        }

        if (($to = $this->boundary($filters, 'to', true, $request)) !== null) {
            $query->where('started_at', '<=', $to);
        }

        if (($term = $this->stringFilter($filters, 'search')) !== null) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(function (Builder $inner) use ($escaped): void {
                $inner
                    ->where('uuid', 'like', $escaped)
                    ->orWhere('run_uuid', 'like', $escaped)
                    ->orWhere('scope', 'like', $escaped)
                    ->orWhere('command', 'like', $escaped)
                    ->orWhere('app_version', 'like', $escaped);
            });
        }

        return $query;
    }

    /**
     * Counts over the whole filtered range, not just the page.
     *
     * @param  Builder<IntegrityCheckRun>  $base
     * @return array{total: int, statuses: array<string, int>, blocking: int, last_run_at: string|null}
     */
    private function summary(Builder $base): array
    {
        $statuses = [];

        foreach (IntegrityCheckStatus::cases() as $case) {
            $statuses[$case->value] = 0;
        }

        $total = 0;
        $blocking = 0;
        $lastRunAt = null;

        try {
            $rows = (clone $base)
                ->selectRaw('status as status_value, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status_value');

            foreach ($rows as $status => $aggregate) {
                $statuses[(string) $status] = (int) $aggregate;
                $total += (int) $aggregate;
            }

            $blocking = (clone $base)->blocking()->count();
            $lastRunAt = (clone $base)->max('started_at');
        } catch (Throwable) {
            // A summary that cannot be computed leaves zeros rather than failing the register.
            // The rows below it are the thing somebody came for.
        }

        return [
            'total' => $total,
            'statuses' => $statuses,
            'blocking' => (int) $blocking,
            'last_run_at' => is_string($lastRunAt) ? $lastRunAt : ($lastRunAt?->toIso8601String()),
        ];
    }

    /**
     * The newest run of each visible suite — the strip that answers "is anything red right now".
     *
     * One query, not one per suite: nine `latest()` calls on a page is how an ops screen becomes
     * the slowest screen in the system (§6.4).
     *
     * @param  list<IntegrityCheckSuite>  $visibleSuites
     * @return array<string, IntegrityCheckRun>
     */
    private function latestPerSuite(array $visibleSuites): array
    {
        $values = $this->suiteValues($visibleSuites);

        if ($values === []) {
            return [];
        }

        try {
            $newestIds = IntegrityCheckRun::query()
                ->selectRaw('MAX(id) as id')
                ->whereIn('suite', $values)
                ->groupBy('suite')
                ->pluck('id')
                ->all();

            if ($newestIds === []) {
                return [];
            }

            return IntegrityCheckRun::query()
                ->whereIn('id', $newestIds)
                ->get()
                ->keyBy(static fn (IntegrityCheckRun $run): string => $run->suite->value)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The other suites of the same invocation (`run_uuid`), so "the sweep on Tuesday" is readable
     * as one thing — scoped, because a sibling is still a row.
     *
     * @param  list<IntegrityCheckSuite>  $visibleSuites
     * @return \Illuminate\Support\Collection<int, IntegrityCheckRun>
     */
    private function siblings(IntegrityCheckRun $run, array $visibleSuites)
    {
        try {
            return IntegrityCheckRun::query()
                ->forRun($run->run_uuid)
                ->whereIn('suite', $this->suiteValues($visibleSuites))
                ->whereKeyNot($run->getKey())
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filter validation — inline only because this slice may not add a Form Request
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<IntegrityCheckSuite>  $visibleSuites
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request, array $visibleSuites): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            // Scoped to the visible suites: a filter that accepted `security` from an Accountant
            // would return an empty page, and an empty page is still an answer about what exists.
            'suite' => ['nullable', 'string', Rule::in($this->suiteValues($visibleSuites))],
            'status' => ['nullable', 'string', Rule::in(IntegrityCheckStatus::values())],
            'triggered_by' => ['nullable', 'string', Rule::in(IntegrityCheckRun::TRIGGERS)],
            'run_uuid' => ['nullable', 'string', 'max:64'],
            'blocking' => ['nullable', 'string', Rule::in(['0', '1'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in($this->perPageOptions())],
        ], [], [
            'triggered_by' => 'trigger',
            'run_uuid' => 'run reference',
            'from' => 'start date',
            'to' => 'end date',
            'per_page' => 'page size',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A date typed in the reader's timezone, snapped to the whole day, in the storage timezone
     * (D61: stored timestamps are UTC, the reader types local dates).
     *
     * @param  array<string, mixed>  $filters
     */
    private function boundary(array $filters, string $key, bool $endOfDay, Request $request): ?CarbonImmutable
    {
        $value = $this->stringFilter($filters, $key);

        if ($value === null) {
            return null;
        }

        try {
            $timezone = $this->timezone($this->actor($request));
            $date = CarbonImmutable::parse($value, $timezone);

            return ($endOfDay ? $date->endOfDay() : $date->startOfDay())
                ->setTimezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        foreach (['search', 'suite', 'status', 'triggered_by', 'run_uuid', 'blocking', 'from', 'to'] as $key) {
            if ($this->stringFilter($filters, $key) !== null) {
                return true;
            }
        }

        return false;
    }

    private function sortColumn(mixed $sort): string
    {
        return is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : 'started_at';
    }

    private function sortDirection(mixed $direction): string
    {
        return is_string($direction) && strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $value = $filters['per_page'] ?? null;

        if ($value === null) {
            return per_page();
        }

        return in_array((int) $value, $this->perPageOptions(), true) ? (int) $value : per_page();
    }

    /**
     * The sizes the rules accept and the select offers: the fixed set plus whatever
     * `appearance.table_page_size` is, or the select could not show the size the page rendered at.
     *
     * @return list<int>
     */
    private function perPageOptions(): array
    {
        $options = self::PER_PAGE_OPTIONS;
        $options[] = per_page();
        $options = array_values(array_unique($options));
        sort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function perPageOptionsForSelect(): array
    {
        $options = [];

        foreach ($this->perPageOptions() as $size) {
            $options[(string) $size] = $size.' / page';
        }

        return $options;
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation helpers — the view does no arithmetic and no lookups
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<IntegrityCheckSuite>  $suites
     * @return list<string>
     */
    private function suiteValues(array $suites): array
    {
        return array_values(array_map(
            static fn (IntegrityCheckSuite $suite): string => $suite->value,
            $suites,
        ));
    }

    /**
     * @param  list<IntegrityCheckSuite>  $suites
     * @return array<string, string>
     */
    private function suiteOptions(array $suites): array
    {
        $options = [];

        foreach ($suites as $suite) {
            $options[$suite->value] = $suite->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function triggerOptions(): array
    {
        $options = [];

        foreach (IntegrityCheckRun::TRIGGERS as $trigger) {
            $options[$trigger] = ucfirst($trigger);
        }

        return $options;
    }

    /**
     * Findings in the one shape the view renders, whatever a command produced.
     *
     * §2.3 promises a findings entry carries codes and figures, never a person's name or amount;
     * the keys below are the contract's five, and anything else a command emitted is folded into
     * `actual` rather than rendered blind. The clamp is the same one the service applies — a
     * command that printed a megabyte must not become a megabyte of HTML.
     *
     * @return list<array{code: string, severity: string, subject: string|null, expected: string|null, actual: string|null, collaborator_id: int|null}>
     */
    private function normaliseFindings(IntegrityCheckRun $run): array
    {
        $findings = $run->findings;

        if (! is_array($findings)) {
            return [];
        }

        $normalised = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $normalised[] = [
                'code' => $this->text($finding['code'] ?? null, 190) ?? '—',
                'severity' => $this->text($finding['severity'] ?? null, 32) ?? 'unknown',
                'subject' => $this->text($finding['subject'] ?? null, 190),
                'expected' => $this->text($finding['expected'] ?? null, 2000),
                'actual' => $this->text($finding['actual'] ?? null, 4000),
                'collaborator_id' => $this->collaboratorId($finding),
            ];
        }

        return $normalised;
    }

    /**
     * The collaborator a wallet finding is about, when the finding names one.
     *
     * §8.6 asks for a link to that partner's wallet screen — **Phase 12's**, which shows a balance
     * derived from the ledger. Nothing here computes a balance, and nothing here guesses an id out
     * of free text: an explicit `collaborator_id`, or a subject written as `collaborator:41`.
     * Anything looser would turn "wallet 3 of 9 drifted" into a link to collaborator 3.
     *
     * @param  array<string, mixed>  $finding
     */
    private function collaboratorId(array $finding): ?int
    {
        $explicit = $finding['collaborator_id'] ?? null;

        if (is_int($explicit) || (is_string($explicit) && ctype_digit($explicit))) {
            return (int) $explicit > 0 ? (int) $explicit : null;
        }

        $subject = $finding['subject'] ?? null;

        if (is_string($subject) && preg_match('/^collaborator[\s:#]+(\d+)$/i', trim($subject), $matches) === 1) {
            return (int) $matches[1] > 0 ? (int) $matches[1] : null;
        }

        return null;
    }

    /**
     * The findings as one CSV string, for the copy button.
     *
     * Every cell goes through {@see CsvWriter::escape()} — a finding's `actual` is command output,
     * and a spreadsheet opening `=HYPERLINK(...)` from a pasted audit trail is a real attack, not a
     * theoretical one.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function findingsCsv(array $findings): string
    {
        $lines = ['Code,Severity,Subject,Expected,Actual'];

        foreach ($findings as $finding) {
            $cells = [];

            foreach (['code', 'severity', 'subject', 'expected', 'actual'] as $column) {
                $cell = CsvWriter::escape($finding[$column] ?? null);
                // Quote always, and double any quote inside: one unescaped newline in command
                // output would otherwise split a row in half when it is pasted.
                $cells[] = '"'.str_replace('"', '""', $cell).'"';
            }

            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    /**
     * One scalar as display text, clamped. An array or object becomes compact JSON rather than
     * "Array" — a finding whose value is a row count per table is still readable that way.
     */
    private function text(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? null : mb_substr($text, 0, $limit);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? mb_substr($encoded, 0, $limit) : null;
    }

    /**
     * The evidence lines both the CSV and a reader's eye want, in order.
     *
     * @return array<string, string>
     */
    private function evidenceLines(IntegrityCheckRun $run, string $timezone, bool $includeFindings): array
    {
        return [
            'Invocation' => $run->run_uuid,
            'Suite' => $run->suite->label(),
            'Status' => $run->status->label(),
            'Scope' => (string) ($run->scope ?? ''),
            'Checks total' => (string) $run->checks_total,
            'Checks passed' => (string) $run->checks_passed,
            'Checks warned' => (string) $run->checks_warned,
            'Checks failed' => (string) $run->checks_failed,
            'Blocks go-live' => $run->blocksGoLive() ? 'Yes' : 'No',
            'Started at' => $run->started_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
            'Finished at' => $run->finished_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
            'Duration (ms)' => (string) ($run->duration_ms ?? ''),
            'Triggered by' => $run->triggered_by,
            'Started by' => (string) ($run->creator?->name ?? ''),
            'Command' => (string) ($run->command ?? ''),
            'Exit code' => (string) ($run->exit_code ?? ''),
            'App version' => (string) ($run->app_version ?? ''),
            'Findings released by retention' => $run->findingsReleased() ? 'Yes' : 'No',
            'Findings truncated' => $run->findings_truncated ? 'Yes' : 'No',
            'Findings included in this export' => $includeFindings ? 'Yes' : 'No (requires integrity_checks.view_logs)',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The activity row for a manual run.
     *
     * A run is already a permanent record of itself; this is the record of a *person* asking for
     * one, which is a different fact and the one an auditor asks about when a verdict is disputed.
     */
    private function record(User $user, ?IntegrityCheckRun $run, string $suite, ?string $scope, int $recorded): void
    {
        try {
            $logger = activity('integrity_checks')->causedBy($user);

            if ($run instanceof IntegrityCheckRun) {
                $logger->performedOn($run);
            }

            $logger
                ->withProperties([
                    'suite' => $suite,
                    'scope' => $scope,
                    'run_uuid' => $run?->run_uuid,
                    'runs_recorded' => $recorded,
                    'status' => $run?->status->value,
                ])
                ->log('integrity_check.run');
        } catch (Throwable) {
            // The proof is written and the operator has their answer; an activity row that could
            // not be written must not undo either.
        }
    }

    private function toastType(IntegrityCheckStatus $status): string
    {
        return match ($status) {
            IntegrityCheckStatus::Passed => 'success',
            IntegrityCheckStatus::Warning => 'warning',
            IntegrityCheckStatus::Failed => 'error',
        };
    }

    private function policy(): IntegrityCheckRunPolicy
    {
        return app(IntegrityCheckRunPolicy::class);
    }

    /**
     * The signed-in user, typed. The route stack guarantees one; this makes that a statement the
     * type system can hold rather than a comment.
     */
    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function timezone(User $user): string
    {
        try {
            return $user->effectiveTimezone();
        } catch (Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
