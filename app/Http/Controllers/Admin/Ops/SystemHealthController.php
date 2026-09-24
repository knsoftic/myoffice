<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ops;

use App\Http\Controllers\Controller;
use App\Models\Ops\IntegrityCheckRun;
use App\Models\User;
use App\Policies\Ops\IntegrityCheckRunPolicy;
use App\Services\Ops\SystemHealthService;
use App\Support\CsvWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The operator's ten-second screen (phase-24-25 §7.2, §8.4).
 *
 * **A probe that cannot be measured is amber, never green.** That single rule is why this
 * controller normalises everything `SystemHealthService` hands back instead of passing it through:
 * a missing key, an unrecognised status string, a probe that threw — each has an obvious lazy
 * reading ("nothing reported a problem") and each of those readings is a lie. `unknown` is a state
 * the view renders in amber with the reason, because a health screen that prints a plausible zero
 * for a failed probe is worse than one that admits it (§8.4, and Phase 2's widget says the same).
 *
 * **The service may not be there, and the screen still has to render.** `SystemHealthService` is
 * Phase 25's, built against §6.2, and this screen is not allowed to be a white page while it is
 * being written or if it is later removed from a trimmed install. So it is resolved through the
 * container behind a `class_exists()` guard, and its absence produces one honest amber report
 * rather than a stack trace.
 *
 * **The per-probe endpoint exists so one slow probe never blocks the page** (§8.4). The page is
 * server-rendered from the cheap `snapshot(false)` pass so it is readable with no JavaScript at
 * all; each card then re-measures itself through `admin.system-health.probe`, and they do it in
 * parallel because each card owns its own request. The `information_schema` aggregate and the
 * queue heartbeat are the two that are genuinely slow, and neither is allowed to hold the other
 * seven hostage.
 *
 * Nothing here recomputes money. The wallet figures are **read** from the reconciliation table
 * (INV-26): a health screen that re-derived a balance would be a second opinion about whether the
 * ledger is intact, and two opinions is worse than one.
 */
final class SystemHealthController extends Controller
{
    /**
     * The probe groups of §8.4, in the order an operator reads them.
     *
     * Declared here rather than taken from the service's key order so the screen's shape is stable
     * while the service grows: a new probe joins its group; a new group appends at the end under
     * its own key rather than silently landing in "Runtime".
     *
     * @var array<string, array{label: string, icon: string, blurb: string}>
     */
    private const GROUPS = [
        'runtime' => [
            'label' => 'Runtime',
            'icon' => 'server-stack',
            'blurb' => 'PHP, Laravel, the caches and which build is deployed.',
        ],
        'database' => [
            'label' => 'Database',
            'icon' => 'table-cells',
            'blurb' => 'Connection, size, pending migrations and the last constraint proof.',
        ],
        'queue' => [
            'label' => 'Queue',
            'icon' => 'queue-list',
            'blurb' => 'Driver, backlog, failures and whether a worker is alive.',
        ],
        'scheduler' => [
            'label' => 'Scheduler',
            'icon' => 'clock',
            'blurb' => 'Heartbeat, last run, next run and each command\'s last outcome.',
        ],
        'storage' => [
            'label' => 'Storage',
            'icon' => 'folder',
            'blurb' => 'Free space, writability, the public link and the upload directory.',
        ],
        'money' => [
            'label' => 'Money',
            'icon' => 'banknotes',
            'blurb' => 'Read from the last reconciliation run — never recomputed here (INV-26).',
        ],
        'backups' => [
            'label' => 'Backups',
            'icon' => 'rectangle-stack',
            'blurb' => 'Age of the newest archive, the newest proven restore point, offsite status.',
        ],
        'mail' => [
            'label' => 'Mail',
            'icon' => 'envelope',
            'blurb' => 'The configured mailer and how the last send went.',
        ],
    ];

    /** The four states a probe may be in. `unknown` is a real answer, not a missing one. */
    private const STATES = ['ok', 'warning', 'critical', 'unknown'];

    /**
     * Status words a service might use, mapped onto the four this screen renders.
     *
     * Generous on input and strict on output: a probe that says `degraded` should not fall through
     * to `unknown` merely because this file had not heard the word, and a probe that says something
     * genuinely unrecognised must not be painted green.
     *
     * @var array<string, string>
     */
    private const STATE_ALIASES = [
        'ok' => 'ok',
        'pass' => 'ok',
        'passed' => 'ok',
        'healthy' => 'ok',
        'up' => 'ok',
        'green' => 'ok',
        'good' => 'ok',
        'warn' => 'warning',
        'warning' => 'warning',
        'amber' => 'warning',
        'degraded' => 'warning',
        'slow' => 'warning',
        'critical' => 'critical',
        'error' => 'critical',
        'fail' => 'critical',
        'failed' => 'critical',
        'down' => 'critical',
        'red' => 'critical',
        'unknown' => 'unknown',
        'unmeasured' => 'unknown',
        'skipped' => 'unknown',
    ];

    /**
     * The screen.
     */
    public function index(Request $request): View
    {
        $user = $this->actor($request);

        abort_unless($user->can('system_health.view_any'), 403);

        $deep = $request->boolean('deep');
        $report = $this->report($deep);

        return view('admin.system-health.index', [
            'report' => $report,
            'deep' => $deep,
            'integrity' => $this->integrityStrip($user),
            'probeUrlTemplate' => $this->probeUrlTemplate(),
            'goLiveUrl' => $this->goLiveUrl(),
            'integrityUrl' => $this->integrityUrl(),
            'exportUrl' => $this->exportUrl($user),
            'canExport' => $user->can('system_health.export'),
            'canReadDetail' => $user->can('system_health.view'),
            'timezone' => $this->timezone($user),
        ]);
    }

    /**
     * One probe, re-measured, as JSON.
     *
     * Throttled at 30/minute by the route (§7.2): eight cards refreshing on a click is eight
     * requests, and a page somebody leaves open should not become a load generator.
     *
     * An unknown key is a **404**, not a 422 — the set of probes is not a secret, but an endpoint
     * that distinguishes "no such probe" from "that probe failed" through its status code is an
     * endpoint somebody enumerates.
     */
    public function probe(Request $request, string $key): JsonResponse
    {
        $user = $this->actor($request);

        abort_unless($user->can('system_health.view'), 403);

        $key = trim($key);

        abort_unless($key !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1, 404);

        $service = $this->service();

        if ($service === null) {
            return response()->json(
                $this->unavailableProbe($key, 'The health service is not installed on this build.'),
                200,
            );
        }

        try {
            $known = $this->probeKeys($service);

            // An empty list means the service does not advertise its keys; trust it to 404 itself.
            abort_unless($known === [] || in_array($key, $known, true), 404);

            $result = $service->probe($key);

            return response()->json($this->normaliseProbe($this->toArray($result), $key));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                $this->unavailableProbe($key, 'The probe could not be measured.'),
                200,
            );
        }
    }

    /**
     * The snapshot as CSV — one row per probe.
     *
     * This is the artefact a go-live pack or a support ticket carries, so it holds the threshold
     * and the remedy beside the reading: a number with nothing to judge it against is not evidence
     * of anything.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->actor($request);

        abort_unless($user->can('system_health.export'), 403);

        $report = $this->report($request->boolean('deep'));
        $timezone = $this->timezone($user);

        $rows = [];

        foreach ($report['groups'] as $group) {
            foreach ($group['probes'] as $probe) {
                $rows[] = [
                    'group' => $group['label'],
                    'probe' => $probe['label'],
                    'status' => $this->stateLabel($probe['status']),
                    'value' => (string) ($probe['value'] ?? ''),
                    'threshold' => (string) ($probe['threshold'] ?? ''),
                    'message' => (string) ($probe['message'] ?? ''),
                    'remedy' => (string) ($probe['remedy'] ?? ''),
                    'measured_at' => (string) ($probe['measured_at'] ?? ''),
                ];
            }
        }

        $filename = 'system-health-'.CarbonImmutable::now($timezone)->format('Y-m-d-His').'.csv';

        return (new CsvWriter(withBom: true))->download(
            $filename,
            ['Group', 'Probe', 'Status', 'Value', 'Threshold', 'What it means', 'What to do', 'Measured at'],
            $rows,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The report — normalised once, rendered everywhere
    |--------------------------------------------------------------------------
    */

    /**
     * The whole snapshot in the one shape the Blade and the CSV both read.
     *
     * @return array{available: bool, reason: string|null, status: string, generated_at: string|null, deep: bool, groups: list<array{key: string, label: string, icon: string, blurb: string, status: string, probes: list<array<string, mixed>>}>, counts: array<string, int>, trends: array{labels: list<string>, series: list<array{name: string, data: list<int|float>}>}|null}
     */
    private function report(bool $deep): array
    {
        $service = $this->service();

        if ($service === null) {
            return $this->unavailableReport(
                'The health service is not installed on this build.',
                $deep,
            );
        }

        try {
            $raw = $this->toArray($service->snapshot($deep));
        } catch (Throwable $exception) {
            report($exception);

            return $this->unavailableReport('The snapshot could not be taken.', $deep);
        }

        $probes = $this->collectProbes($raw);

        /*
        | The belt behind §8.4's rule. A snapshot with no probes in it is not a healthy system, it
        | is a payload this file could not read — the service always measures at least nine probes,
        | so an empty list can only mean the key it packs them under changed again. Without this
        | line that condition renders as a green header over four zeroes, which is the single most
        | dangerous thing this screen can display: "a probe that cannot run renders amber with
        | 'could not be measured' and the reason, never green". Degrading to the amber panel costs
        | an operator one glance; the green empty page costs them the outage.
        */
        if ($probes === []) {
            return $this->unavailableReport('The snapshot returned no probes.', $deep);
        }

        $groups = $this->groupProbes($probes);
        $counts = $this->countStates($probes);

        return [
            'available' => true,
            'reason' => null,
            // The service's own verdict when it gave one; otherwise the worst probe, because a
            // report whose overall status disagrees with its own cards is the one nobody trusts.
            'status' => $this->normaliseState($raw['status'] ?? null) === 'unknown'
                ? $this->worstState($probes)
                : $this->normaliseState($raw['status'] ?? null),
            'generated_at' => $this->text($raw['generated_at'] ?? $raw['measured_at'] ?? null, 64),
            'deep' => $deep,
            'groups' => $groups,
            'counts' => $counts,
            'trends' => $this->normaliseTrends($raw['trends'] ?? null),
        ];
    }

    /**
     * Every probe in the payload, whichever shape the DTO used.
     *
     * `HealthReport::toArray()` may hand back `groups[].probes[]` or a flat map keyed by probe key;
     * both are reasonable and this screen reads either, so a change of mind in §6.2 is not a change
     * to this file.
     *
     * **`checks` is the key the service that exists actually uses, and this method now reads it**
     * — after `probes`, because either name is legitimate and only one can be present. Being
     * liberal about the shape was the intent here and it still produced the worst possible failure:
     * `SystemHealthService::snapshot()` returns `['status', 'generated_at', 'deep', 'app_version',
     * 'counts', 'checks']`, this method looked for `groups` and `probes`, found neither, and
     * returned an empty list — so the page rendered a green "OK" header over "0 of 0 probes", with
     * no probe cards and a CSV export that was a header row. Tolerance for several key names is
     * worth nothing if the one the collaborating class uses is not among them; that is why the
     * caller now refuses an empty probe list outright rather than trusting this method to have
     * guessed right.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function collectProbes(array $raw): array
    {
        $probes = [];

        $groups = $raw['groups'] ?? null;

        if (is_array($groups)) {
            foreach ($groups as $groupKey => $group) {
                if (! is_array($group)) {
                    continue;
                }

                $key = is_string($group['key'] ?? null) ? $group['key'] : (is_string($groupKey) ? $groupKey : null);
                $members = $group['probes'] ?? $group;

                if (! is_array($members)) {
                    continue;
                }

                foreach ($members as $probeKey => $probe) {
                    if (! is_array($probe)) {
                        continue;
                    }

                    $probe['group'] = $probe['group'] ?? $key;
                    $probes[] = $this->normaliseProbe($probe, is_string($probeKey) ? $probeKey : null);
                }
            }
        }

        $flat = $raw['probes'] ?? $raw['checks'] ?? null;

        if (is_array($flat)) {
            foreach ($flat as $probeKey => $probe) {
                if (! is_array($probe)) {
                    continue;
                }

                $probes[] = $this->normaliseProbe($probe, is_string($probeKey) ? $probeKey : null);
            }
        }

        // Two shapes present at once would double every card; the key is the identity.
        $unique = [];

        foreach ($probes as $probe) {
            $unique[$probe['key']] = $probe;
        }

        return array_values($unique);
    }

    /**
     * One probe in the shape the card renders.
     *
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function normaliseProbe(array $probe, ?string $fallbackKey = null): array
    {
        $key = $this->text($probe['key'] ?? $fallbackKey, 64) ?? 'probe';
        $group = $this->text($probe['group'] ?? null, 64);

        return [
            'key' => $key,
            'label' => $this->text($probe['label'] ?? $probe['name'] ?? null, 120) ?? $this->humanise($key),
            'group' => $group !== null && array_key_exists($group, self::GROUPS) ? $group : 'runtime',
            'status' => $this->normaliseState($probe['status'] ?? $probe['state'] ?? null),
            // The service reports a number and its `unit` in two keys; this screen has one slot for
            // each of them, so they are joined here — see {@see self::measurement()}.
            'value' => $this->measurement($probe['value'] ?? null, $probe['unit'] ?? null, 190),
            'threshold' => $this->measurement($probe['threshold'] ?? $probe['expected'] ?? null, $probe['unit'] ?? null, 190),
            'message' => $this->text($probe['message'] ?? $probe['detail'] ?? $probe['reason'] ?? null, 500),
            // §8.4: "a red probe expands to the remediation command". Null when there is nothing
            // useful to say — an empty disclosure triangle is worse than none.
            'remedy' => $this->text($probe['remedy'] ?? $probe['remediation'] ?? $probe['command'] ?? null, 300),
            'measured_at' => $this->text($probe['measured_at'] ?? $probe['checked_at'] ?? null, 64),
        ];
    }

    /**
     * Probes arranged into the §8.4 groups, empty groups dropped.
     *
     * @param  list<array<string, mixed>>  $probes
     * @return list<array{key: string, label: string, icon: string, blurb: string, status: string, probes: list<array<string, mixed>>}>
     */
    private function groupProbes(array $probes): array
    {
        $groups = [];

        foreach (self::GROUPS as $key => $meta) {
            $members = array_values(array_filter(
                $probes,
                static fn (array $probe): bool => $probe['group'] === $key,
            ));

            if ($members === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'blurb' => $meta['blurb'],
                'status' => $this->worstState($members),
                'probes' => $members,
            ];
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $probes
     * @return array<string, int>
     */
    private function countStates(array $probes): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        $counts['total'] = count($probes);

        foreach ($probes as $probe) {
            $counts[$probe['status']]++;
        }

        return $counts;
    }

    /**
     * The worst state present. `unknown` outranks `ok` deliberately: a group with one unmeasured
     * probe is not a green group.
     *
     * @param  list<array<string, mixed>>  $probes
     */
    private function worstState(array $probes): string
    {
        $rank = ['ok' => 0, 'unknown' => 1, 'warning' => 2, 'critical' => 3];
        $worst = 'ok';

        foreach ($probes as $probe) {
            if (($rank[$probe['status']] ?? 1) > ($rank[$worst] ?? 0)) {
                $worst = $probe['status'];
            }
        }

        return $worst;
    }

    private function normaliseState(mixed $state): string
    {
        if (! is_string($state)) {
            return 'unknown';
        }

        return self::STATE_ALIASES[strtolower(trim($state))] ?? 'unknown';
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'OK',
            'warning' => 'Warning',
            'critical' => 'Critical',
            default => 'Not measured',
        };
    }

    /**
     * The 14-day failed-job and error series (§8.4), when the service reports one.
     *
     * The keys are `x-ui.chart`'s: `label`, `data`, `color`. The colour is a Tailwind token from
     * the same set every enum's `color()` returns, so a chart matches the badges beside it — and it
     * is assigned here rather than in the Blade because a series with no colour is drawn in the
     * default palette, which would make "failed jobs" green.
     *
     * @return array{labels: list<string>, series: list<array{label: string, data: list<int|float>, color: string}>}|null
     */
    private function normaliseTrends(mixed $trends): ?array
    {
        if (! is_array($trends)) {
            return null;
        }

        $labels = [];

        foreach ($trends['labels'] ?? [] as $label) {
            if (is_scalar($label)) {
                $labels[] = mb_substr((string) $label, 0, 32);
            }
        }

        // Failures first, warnings second — the order the series arrive in decides which colour
        // each gets, and the first series on this chart is always the one about things breaking.
        $palette = ['rose', 'amber', 'sky', 'slate'];
        $series = [];

        foreach ($trends['series'] ?? [] as $name => $entry) {
            $data = is_array($entry) ? ($entry['data'] ?? $entry) : null;

            if (! is_array($data)) {
                continue;
            }

            $numbers = [];

            foreach ($data as $point) {
                $numbers[] = is_numeric($point) ? 0 + $point : 0;
            }

            $series[] = [
                'label' => $this->text(is_array($entry) ? ($entry['label'] ?? $entry['name'] ?? null) : null, 64)
                    ?? (is_string($name) ? $this->humanise($name) : 'Series'),
                'data' => $numbers,
                'color' => $this->text(is_array($entry) ? ($entry['color'] ?? null) : null, 16)
                    ?? $palette[count($series) % count($palette)],
            ];
        }

        return $labels === [] || $series === [] ? null : ['labels' => $labels, 'series' => $series];
    }

    /*
    |--------------------------------------------------------------------------
    | The honest failure shapes
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function unavailableReport(string $reason, bool $deep): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'status' => 'unknown',
            'generated_at' => null,
            'deep' => $deep,
            'groups' => [],
            'counts' => array_fill_keys([...self::STATES, 'total'], 0),
            'trends' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableProbe(string $key, string $reason): array
    {
        return $this->normaliseProbe([
            'key' => $key,
            'status' => 'unknown',
            'message' => $reason,
        ], $key);
    }

    /*
    |--------------------------------------------------------------------------
    | Neighbours: the integrity strip, and the two tabs
    |--------------------------------------------------------------------------
    */

    /**
     * The newest integrity run per suite, for the "Integrity" panel of §8.4.
     *
     * Scoped by the same policy the integrity screens use — a health screen is not a side door
     * around §9's suite narrowing — and skipped entirely for somebody who may not read runs.
     *
     * @return list<array{suite: string, label: string, status: string, colour: string, ran_at: \Carbon\CarbonInterface|null, blocking: bool, id: int|null}>
     */
    private function integrityStrip(User $user): array
    {
        if (! Gate::allows('viewAny', IntegrityCheckRun::class)) {
            return [];
        }

        try {
            $policy = app(IntegrityCheckRunPolicy::class);

            $values = array_map(
                static fn ($suite): string => $suite->value,
                $policy->visibleSuites($user),
            );

            if ($values === []) {
                return [];
            }

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
                ->orderByDesc('started_at')
                ->get()
                ->map(static fn (IntegrityCheckRun $run): array => [
                    'suite' => $run->suite->value,
                    'label' => $run->suite->label(),
                    'status' => $run->status->label(),
                    'colour' => $run->status->color(),
                    'ran_at' => $run->started_at,
                    'blocking' => $run->blocksGoLive(),
                    'id' => (int) $run->getKey(),
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            // The integrity table is another phase's; its absence is not this screen's failure.
            return [];
        }
    }

    /**
     * The probe endpoint with a placeholder the card substitutes, so the URL is built by the
     * router once rather than concatenated in JavaScript eight times.
     */
    private function probeUrlTemplate(): ?string
    {
        if (! RouteFacade::has('admin.system-health.probe')) {
            return null;
        }

        try {
            return route('admin.system-health.probe', ['probe' => '__probe__']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * §8.5's tab, which is another slice's screen. Linked when it exists, absent when it does not
     * — a tab that 404s is worse than a tab that is not there.
     */
    private function goLiveUrl(): ?string
    {
        if (! RouteFacade::has('admin.system-health.go-live')) {
            return null;
        }

        try {
            return route('admin.system-health.go-live');
        } catch (Throwable) {
            return null;
        }
    }

    private function integrityUrl(): ?string
    {
        if (! RouteFacade::has('admin.integrity-checks.index') || ! Gate::allows('viewAny', IntegrityCheckRun::class)) {
            return null;
        }

        try {
            return route('admin.integrity-checks.index');
        } catch (Throwable) {
            return null;
        }
    }

    private function exportUrl(User $user): ?string
    {
        if (! $user->can('system_health.export') || ! RouteFacade::has('admin.system-health.export')) {
            return null;
        }

        try {
            return route('admin.system-health.export');
        } catch (Throwable) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The service, or null on a build that does not have it.
     *
     * `class_exists()` first so the container is never asked to resolve a class that is not there:
     * a `BindingResolutionException` on an ops screen would be reported as an outage when the
     * truth is simply "not installed".
     */
    private function service(): ?SystemHealthService
    {
        if (! class_exists(SystemHealthService::class)) {
            return null;
        }

        try {
            $service = app(SystemHealthService::class);

            return $service instanceof SystemHealthService ? $service : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The probe keys the service advertises, tolerating either a list or a keyed map.
     *
     * @return list<string>
     */
    private function probeKeys(SystemHealthService $service): array
    {
        try {
            $probes = $service->probes();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($probes)) {
            return [];
        }

        $keys = [];

        foreach ($probes as $key => $probe) {
            if (is_string($key)) {
                $keys[] = $key;
            } elseif (is_string($probe)) {
                $keys[] = $probe;
            } elseif (is_array($probe) && is_string($probe['key'] ?? null)) {
                $keys[] = $probe['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * A readonly DTO as an array — §6.2 promises `toArray()`, and this tolerates a build where the
     * service already returns one.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $array = $value->toArray();

            return is_array($array) ? $array : [];
        }

        return [];
    }

    /**
     * A reading with its unit attached, when the unit belongs to it.
     *
     * **A number whose unit was dropped is not a reading.** `SystemHealthService` measures in nine
     * different units — ms, minutes, hours, jobs, writable directories, percent free — and emits
     * the number in `value` and the word in `unit`. This screen was rendering only the number, so
     * the backups card read "12" beside "expected 36" and an operator had to know from memory
     * whether those were hours or days. §8.4 asks for "the measured value, the threshold it is
     * judged against"; a bare integer is neither, and the CSV that goes into a support ticket was
     * carrying the same ambiguity.
     *
     * The unit is appended only to an actual number. `unit: 'reachable'` sits beside `value: true`
     * and `unit: 'version'` beside `value: '1.4.0'`, and "Yes reachable" or "1.4.0 version" would
     * be worse than the bare word — a unit qualifies a quantity, and those two are not quantities.
     */
    private function measurement(mixed $value, mixed $unit, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        if ($text === null || ! is_string($unit) || ! (is_int($value) || is_float($value))) {
            return $text;
        }

        $unit = trim($unit);

        return $unit === '' ? $text : mb_substr($text.' '.$unit, 0, $limit);
    }

    private function text(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? null : mb_substr($text, 0, $limit);
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? mb_substr($encoded, 0, $limit) : null;
    }

    /**
     * `failed_jobs` -> `Failed jobs`. Only ever a fallback: a service that names its probes is
     * always preferred to this file guessing.
     */
    private function humanise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

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
}
