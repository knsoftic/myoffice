@extends('layouts.admin')

@section('title', 'Integrity checks')

{{--
    Integrity check register (route admin.integrity-checks.index, phase-24-25 §8.6).

    The register of proofs: what was checked, when, by whom, and whether it held. Every row is
    append-only evidence (§2.3, D19) — there is no edit and no delete on this screen, and that
    absence is deliberate rather than unfinished.

    What this file is NOT allowed to do: filter rows. The suite narrowing of §9 is a `whereIn` in
    the controller, so an Accountant's paginator, totals, sort and export all agree with what they
    may see. A `@can` around a `<tr>` would leave the count on page 3 telling them what exists.

    Likewise `$runs` never carries findings — the controller only sends them on the detail screen,
    and only to `integrity_checks.view_logs`.

    Formatting goes through app_datetime() / app_number() so localization restyles the screen
    (D61: stored timestamps are UTC, the helpers convert). The one raw call is toIso8601String()
    inside <time datetime="…">, which is the machine value and is never read by a person.
--}}

@php
    $statusColour = [
        'passed' => 'emerald',
        'warning' => 'amber',
        'failed' => 'rose',
    ];

    $duration = static function (?int $ms): string {
        if ($ms === null) {
            return '—';
        }

        return $ms < 1000
            ? app_number($ms).' ms'
            : app_number($ms / 1000, 1).' s';
    };
@endphp

@section('header')
    <x-ui.page-header
        title="Integrity checks"
        subtitle="Every proof this system has run against itself, and what it found."
        icon="shield-check"
        :badge="app_number($runs->total()).' runs'"
        :badge-color="$summary['blocking'] > 0 ? 'rose' : 'slate'"
    >
        <x-slot:actions>
            @if ($canRun)
                <x-ui.button icon="arrow-path" x-on:click="$dispatch('open-modal', 'run-integrity-check')">
                    Run check
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        `navigating` drives the table's skeleton while a filter change is in flight: the filter bar
        submits itself, so the rows on screen belong to the previous query from that moment on.
    --}}
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >

        @if ($narrowed)
            {{--
                §9: the Accountant sees the financial suites only. Said out loud, because a list
                that is quietly short is a list somebody draws the wrong conclusion from.
            --}}
            <div class="flex items-start gap-2.5 rounded-xl bg-sky-50 p-3 text-sm text-sky-800 ring-1 ring-sky-200/70 dark:bg-sky-500/10 dark:text-sky-200 dark:ring-sky-500/20">
                <x-ui.icon name="information-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                <p>
                    You are seeing the <strong>financial</strong> proofs — the constraint and wallet
                    runs that stand behind a commission figure. Other suites are operations checks and
                    are not part of this view.
                </p>
            </div>
        @endif

        {{-- ── Summary over the filtered range ───────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat-card
                label="Runs in range"
                :value="app_number($summary['total'])"
                icon="clipboard-document-check"
                color="slate"
            />

            <x-ui.stat-card
                label="Blocking go-live"
                :value="app_number($summary['blocking'])"
                :delta-label="$summary['blocking'] > 0 ? 'failures, and warnings on money' : 'nothing blocking'"
                icon="exclamation-triangle"
                :color="$summary['blocking'] > 0 ? 'rose' : 'emerald'"
            />

            <x-ui.stat-card
                label="Warnings"
                :value="app_number($summary['statuses']['warning'] ?? 0)"
                delta-label="how something breaks later"
                icon="exclamation-circle"
                :color="($summary['statuses']['warning'] ?? 0) > 0 ? 'amber' : 'slate'"
            />

            <x-ui.stat-card
                label="Last run"
                :value="$summary['last_run_at'] ? app_datetime($summary['last_run_at']) : '—'"
                icon="clock"
                color="sky"
            />
        </div>

        {{-- ── Newest verdict per suite ──────────────────────────────────────────────── --}}
        @if (! empty($latestPerSuite))
            <x-ui.card title="Where each suite stands" subtitle="The newest run of every suite you can see." icon="shield-check">
                <ul role="list" class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($latestPerSuite as $suiteValue => $latest)
                        <li>
                            <a
                                href="{{ route('admin.integrity-checks.show', $latest) }}"
                                class="flex items-start justify-between gap-3 rounded-lg p-3 ring-1 ring-slate-200/70 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:ring-slate-800 dark:hover:bg-slate-800/40"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $latest->suite->label() }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                                        <time datetime="{{ $latest->started_at?->toIso8601String() }}">
                                            {{ $latest->started_at ? app_datetime($latest->started_at) : 'never' }}
                                        </time>
                                    </span>
                                </span>

                                {{-- The badge carries its label text, so status is never colour alone (A-h). --}}
                                <x-ui.badge :color="$latest->status->color()" size="sm">
                                    {{ $latest->status->label() }}
                                </x-ui.badge>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        {{-- ── Filters ───────────────────────────────────────────────────────────────── --}}
        <x-ui.filter-bar placeholder="Search run reference, scope, command…">
            <x-ui.form.select
                name="suite"
                :options="$suiteOptions"
                :selected="request('suite')"
                placeholder="Any suite"
                size="sm"
                icon="shield-check"
                aria-label="Filter by suite"
            />

            <x-ui.form.select
                name="status"
                :options="$statusOptions"
                :selected="request('status')"
                placeholder="Any status"
                size="sm"
                icon="check-badge"
                aria-label="Filter by status"
            />

            <x-ui.form.select
                name="triggered_by"
                :options="$triggerOptions"
                :selected="request('triggered_by')"
                placeholder="Any trigger"
                size="sm"
                icon="arrow-path"
                aria-label="Filter by trigger"
            />

            <x-ui.form.select
                name="blocking"
                :options="['1' => 'Blocking only']"
                :selected="request('blocking')"
                placeholder="Blocking or not"
                size="sm"
                icon="exclamation-triangle"
                aria-label="Filter by whether the run blocks a go-live"
            />

            <x-ui.form.input
                type="date"
                name="from"
                :value="request('from')"
                size="sm"
                aria-label="From date"
                class="sm:max-w-[11rem]"
            />

            <x-ui.form.input
                type="date"
                name="to"
                :value="request('to')"
                size="sm"
                aria-label="To date"
                class="sm:max-w-[11rem]"
            />

            <x-ui.form.select
                name="per_page"
                :options="$perPageOptions"
                :selected="(string) request('per_page', $runs->perPage())"
                size="sm"
                icon="list-bullet"
                aria-label="Rows per page"
            />
        </x-ui.filter-bar>

        {{-- ── The register ──────────────────────────────────────────────────────────── --}}
        <x-ui.table
            loading="navigating"
            :is-empty="$runs->isEmpty()"
            :columns="8"
            caption="Integrity check runs, newest first"
        >
            <x-slot:head>
                <x-ui.th-sortable column="started_at" :sort="$sort" :direction="$direction" default="desc">
                    Run at
                </x-ui.th-sortable>

                <x-ui.th-sortable column="suite" :sort="$sort" :direction="$direction">
                    Suite
                </x-ui.th-sortable>

                <th scope="col" class="px-4 py-3 text-left font-semibold">Scope</th>

                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">
                    Status
                </x-ui.th-sortable>

                <x-ui.th-sortable column="checks_failed" :sort="$sort" :direction="$direction" align="right" :numeric="true" default="desc">
                    Passed / warned / failed
                </x-ui.th-sortable>

                <x-ui.th-sortable column="duration_ms" :sort="$sort" :direction="$direction" align="right" :numeric="true">
                    Duration
                </x-ui.th-sortable>

                <x-ui.th-sortable column="triggered_by" :sort="$sort" :direction="$direction">
                    Triggered by
                </x-ui.th-sortable>

                <th scope="col" class="px-4 py-3 text-right font-semibold">
                    <span class="sr-only">Actions</span>
                </th>
            </x-slot:head>

            @foreach ($runs as $run)
                {{-- A blocking run is tinted, so a bad night is visible without reading the column. --}}
                <tr @class(['bg-rose-50/60 dark:bg-rose-500/5' => $run->blocksGoLive()])>
                    <td class="px-4 py-3.5 align-top">
                        <a
                            href="{{ route('admin.integrity-checks.show', $run) }}"
                            class="font-medium text-brand-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:text-brand-300"
                        >
                            <time datetime="{{ $run->started_at?->toIso8601String() }}">
                                {{ $run->started_at ? app_datetime($run->started_at) : '—' }}
                            </time>
                        </a>
                        <div class="mt-0.5 font-mono text-2xs text-slate-400 dark:text-slate-500">
                            {{ \Illuminate\Support\Str::limit($run->run_uuid, 14, '…') }}
                        </div>
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <span class="font-medium text-slate-900 dark:text-white">{{ $run->suite->label() }}</span>
                        @if ($run->suite->isFinancial())
                            <span class="mt-0.5 block text-2xs font-medium uppercase tracking-wider text-rose-600 dark:text-rose-400">
                                Money
                            </span>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top text-slate-600 dark:text-slate-300">
                        {{ $run->scope ?? '—' }}
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <x-ui.badge :color="$run->status->color()" size="sm">
                            {{ $run->status->label() }}
                        </x-ui.badge>

                        @if ($run->blocksGoLive())
                            <span class="mt-1 block text-2xs font-medium text-rose-600 dark:text-rose-400">
                                Blocks go-live
                            </span>
                        @endif
                    </td>

                    <td class="px-4 py-3.5 align-top text-right tabular-nums">
                        <span class="text-emerald-600 dark:text-emerald-400">{{ app_number($run->checks_passed) }}</span>
                        <span class="text-slate-300 dark:text-slate-600">/</span>
                        <span class="text-amber-600 dark:text-amber-400">{{ app_number($run->checks_warned) }}</span>
                        <span class="text-slate-300 dark:text-slate-600">/</span>
                        <span class="text-rose-600 dark:text-rose-400">{{ app_number($run->checks_failed) }}</span>
                        <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                            of {{ app_number($run->checks_total) }}
                        </div>
                    </td>

                    <td class="px-4 py-3.5 align-top text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $duration($run->duration_ms) }}
                    </td>

                    <td class="px-4 py-3.5 align-top">
                        <span class="text-slate-700 dark:text-slate-200">{{ ucfirst($run->triggered_by) }}</span>
                        <div class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">
                            {{-- Null means the scheduler, and that null is information, not a gap. --}}
                            {{ $run->creator?->name ?? 'scheduler' }}
                        </div>
                    </td>

                    <td class="px-4 py-3.5 align-top text-right">
                        <div class="inline-flex items-center gap-1">
                            <x-ui.button
                                variant="ghost"
                                size="sm"
                                icon="eye"
                                :href="route('admin.integrity-checks.show', $run)"
                            >
                                View
                            </x-ui.button>

                            @if ($canExport)
                                <x-ui.icon-button
                                    icon="arrow-down-tray"
                                    :href="route('admin.integrity-checks.export', $run)"
                                    label="Export {{ $run->suite->label() }} run of {{ app_datetime($run->started_at) }}"
                                />
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($hasFilters)
                    <x-ui.empty-state
                        icon="funnel"
                        title="No runs match these filters"
                        message="Clear the filters to see the whole register."
                    />
                @else
                    <x-ui.empty-state
                        icon="shield-check"
                        title="No checks have been run yet"
                        message="A proof nobody has run is a proof nobody has. The scheduler runs the financial suites nightly; you can also run one now."
                    >
                        @if ($canRun)
                            <x-slot:action>
                                <x-ui.button icon="arrow-path" x-on:click="$dispatch('open-modal', 'run-integrity-check')">
                                    Run all checks
                                </x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$runs" label="runs" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection

@if ($canRun)
    @push('modals')
        {{--
            Running a check writes a permanent record, so the modal asks for the suite deliberately
            rather than offering a one-click "run everything" on the page itself. The route carries
            the `integrity-run` limiter (3/minute) and `can:integrity_checks.create` — this modal is
            the convenience, never the control.
        --}}
        <x-ui.modal name="run-integrity-check" title="Run an integrity check" icon="shield-check" size="md">
            <form id="run-integrity-check-form" method="POST" action="{{ route('admin.integrity-checks.store') }}" class="space-y-4">
                @csrf

                <x-ui.form.select
                    name="suite"
                    label="Suite"
                    :options="array_merge(['all' => 'All suites (slower)'], $runnableSuites)"
                    :selected="old('suite')"
                    required
                    help="Each suite delegates to the command that owns it. Nothing is repaired — a proof that fixes what it finds is not a proof."
                />

                <x-ui.form.input
                    name="scope"
                    label="Scope"
                    :value="old('scope')"
                    optional
                    placeholder="e.g. collaborator:41"
                    help="Narrows the run where the suite supports it. Leave empty to check everything."
                />

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    The run happens now and may take a while. Its verdict is recorded whatever it says,
                    and the record can never be edited or deleted.
                </p>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="hide(true)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="run-integrity-check-form" icon="arrow-path">Run check</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endpush
@endif
