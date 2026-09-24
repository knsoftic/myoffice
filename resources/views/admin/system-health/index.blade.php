@extends('layouts.admin')

@section('title', 'System health')

{{--
    System health (route admin.system-health.index, phase-24-25 §8.4).

    One screen an operator reads in ten seconds. Every card is a probe: a measured value, the
    threshold it is judged against, and — when it is red — what to do about it.

    **Amber is the honest colour, and this screen uses it freely.** §8.4: a probe that cannot be
    measured renders amber with the reason, never green. The controller normalises every status
    into exactly four states and maps an unrecognised one to `unknown`, so a probe this file has
    never heard of cannot be painted as healthy by accident.

    **No empty state, deliberately.** §8.4 says so: a system always has a state. What it can have
    is an unavailable *service*, and that is the amber panel at the top, not an empty page.

    The page is server-rendered from the cheap snapshot so it is readable with JavaScript off;
    each card then re-measures itself through admin.system-health.probe. The requests are per card
    and therefore parallel — one slow probe never blocks the other seven (§8.4).
--}}

@php
    $dot = [
        'ok' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'critical' => 'bg-rose-500',
        'unknown' => 'bg-slate-400 dark:bg-slate-500',
    ];

    $badgeColour = [
        'ok' => 'emerald',
        'warning' => 'amber',
        'critical' => 'rose',
        'unknown' => 'slate',
    ];

    $stateLabel = [
        'ok' => 'OK',
        'warning' => 'Warning',
        'critical' => 'Critical',
        'unknown' => 'Not measured',
    ];

    $overall = $report['status'];

    $tabs = array_values(array_filter([
        ['label' => 'Probes', 'url' => route('admin.system-health.index'), 'active' => true, 'icon' => 'server-stack'],
        $integrityUrl ? ['label' => 'Integrity', 'url' => $integrityUrl, 'icon' => 'shield-check'] : null,
        $goLiveUrl ? ['label' => 'Go-live', 'url' => $goLiveUrl, 'icon' => 'clipboard-document-check'] : null,
    ]));
@endphp

@section('header')
    <x-ui.page-header
        title="System health"
        subtitle="What this installation is doing right now, measured rather than configured."
        icon="server-stack"
        :badge="$stateLabel[$overall]"
        :badge-color="$badgeColour[$overall]"
    >
        <x-slot:actions>
            @if ($canReadDetail && $probeUrlTemplate)
                {{-- Dispatches to every card at once; each card owns its own request. --}}
                <x-ui.button variant="secondary" icon="arrow-path" x-on:click="$dispatch('health-refresh')">
                    Re-measure
                </x-ui.button>
            @endif

            @if ($canExport && $exportUrl)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="$exportUrl">
                    Export CSV
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-4">

        @if (count($tabs) > 1)
            <x-ui.tabs :tabs="$tabs" />
        @endif

        @unless ($report['available'])
            {{--
                The service could not be reached. Amber, never red and never silent: "we could not
                measure" is a different statement from "it is broken", and a screen that conflates
                them teaches its readers to ignore it.
            --}}
            <div class="flex items-start gap-2.5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/20">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">Health could not be measured</p>
                    <p class="mt-0.5">{{ $report['reason'] }}</p>
                    <p class="mt-1 text-xs opacity-80">
                        Nothing below is a claim that the system is healthy. The console commands
                        (<code>php artisan integrity:verify</code>, <code>php artisan security:audit</code>)
                        answer the same questions and do not depend on this screen.
                    </p>
                </div>
            </div>
        @endunless

        {{-- ── How many of each ──────────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat-card
                label="Healthy"
                :value="app_number($report['counts']['ok'])"
                :delta-label="'of '.app_number($report['counts']['total']).' probes'"
                icon="check-circle"
                color="emerald"
            />

            <x-ui.stat-card
                label="Warnings"
                :value="app_number($report['counts']['warning'])"
                delta-label="not broken today"
                icon="exclamation-circle"
                :color="$report['counts']['warning'] > 0 ? 'amber' : 'slate'"
            />

            <x-ui.stat-card
                label="Critical"
                :value="app_number($report['counts']['critical'])"
                delta-label="needs attention now"
                icon="exclamation-triangle"
                :color="$report['counts']['critical'] > 0 ? 'rose' : 'slate'"
            />

            <x-ui.stat-card
                label="Not measured"
                :value="app_number($report['counts']['unknown'])"
                delta-label="a probe that could not run"
                icon="question-mark-circle"
                :color="$report['counts']['unknown'] > 0 ? 'amber' : 'slate'"
            />
        </div>

        @if ($report['generated_at'])
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Measured
                <time datetime="{{ $report['generated_at'] }}">{{ app_datetime($report['generated_at']) }}</time>{{ $report['deep'] ? ', including the slow probes.' : '.' }}
            </p>
        @endif

        {{-- ── One card per probe group ──────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($report['groups'] as $group)
                <x-ui.card :title="$group['label']" :subtitle="$group['blurb']" :icon="$group['icon']" :padded="false">
                    <x-slot:actions>
                        <x-ui.badge :color="$badgeColour[$group['status']]" size="sm">
                            {{ $stateLabel[$group['status']] }}
                        </x-ui.badge>
                    </x-slot:actions>

                    <ul role="list" class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($group['probes'] as $probe)
                            <li
                                class="px-4 py-3.5"
                                x-data="{
                                    probe: @js($probe),
                                    busy: false,
                                    open: false,
                                    endpoint: @js($probeUrlTemplate),
                                    states: @js($stateLabel),
                                    stateLabel() {
                                        return this.states[this.probe.status] ?? 'Not measured';
                                    },
                                    async refresh() {
                                        if (! this.endpoint || this.busy) {
                                            return;
                                        }

                                        this.busy = true;

                                        try {
                                            const response = await fetch(
                                                this.endpoint.replace('__probe__', encodeURIComponent(this.probe.key)),
                                                {
                                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                                    credentials: 'same-origin',
                                                },
                                            );

                                            if (! response.ok) {
                                                throw new Error(String(response.status));
                                            }

                                            this.probe = await response.json();
                                        } catch (error) {
                                            // A failed re-measure is itself an unmeasured probe. It
                                            // must not leave the previous reading on screen looking
                                            // current — that is the one lie this page cannot tell.
                                            this.probe = Object.assign({}, this.probe, {
                                                status: 'unknown',
                                                value: null,
                                                message: 'This probe could not be re-measured.',
                                            });
                                        } finally {
                                            this.busy = false;
                                        }
                                    },
                                }"
                                x-on:health-refresh.window="refresh()"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-start gap-2.5">
                                        {{-- The dot is an accent; the state is also written out below, so status is never colour alone (A-h). --}}
                                        <span
                                            class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot[$probe['status']] }}"
                                            x-bind:class="{
                                                'bg-emerald-500': probe.status === 'ok',
                                                'bg-amber-500': probe.status === 'warning',
                                                'bg-rose-500': probe.status === 'critical',
                                                'bg-slate-400 dark:bg-slate-500': probe.status === 'unknown',
                                            }"
                                            aria-hidden="true"
                                        ></span>

                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-slate-900 dark:text-white" x-text="probe.label">
                                                {{ $probe['label'] }}
                                            </p>

                                            {{--
                                                Every reactive node also carries its server-rendered
                                                text. Alpine replaces it; with JavaScript off the
                                                page still says what the snapshot measured, which is
                                                the difference between a degraded screen and a blank
                                                one.
                                            --}}
                                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                                <span x-text="stateLabel()">{{ $stateLabel[$probe['status']] }}</span><span
                                                    x-text="probe.threshold ? ' · expected ' + probe.threshold : ''"
                                                >{{ $probe['threshold'] ? ' · expected '.$probe['threshold'] : '' }}</span>
                                            </p>

                                            <p
                                                @class(['mt-1 text-xs text-slate-600 dark:text-slate-300', 'hidden' => ! $probe['message']])
                                                x-bind:class="{ 'hidden': ! probe.message }"
                                                x-text="probe.message"
                                            >{{ $probe['message'] }}</p>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <span
                                            class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white"
                                            x-show="! busy"
                                            x-text="probe.value ?? '—'"
                                        >{{ $probe['value'] ?? '—' }}</span>

                                        {{-- role="status" so a screen reader is told the region is updating (A-g). --}}
                                        <div x-cloak x-show="busy" role="status" class="w-16">
                                            <x-ui.skeleton variant="text" :count="1" />
                                            <span class="sr-only">Measuring this probe…</span>
                                        </div>

                                        @if ($canReadDetail && $probeUrlTemplate)
                                            <x-ui.icon-button
                                                icon="arrow-path"
                                                size="sm"
                                                :label="'Re-measure '.$probe['label']"
                                                x-on:click="refresh()"
                                                x-bind:disabled="busy"
                                            />
                                        @endif
                                    </div>
                                </div>

                                {{-- §8.4: a red probe expands to the remediation command. --}}
                                <div
                                    @class(['mt-2 pl-[1.125rem]', 'hidden' => ! $probe['remedy'] || $probe['status'] === 'ok'])
                                    x-bind:class="{ 'hidden': ! probe.remedy || probe.status === 'ok' }"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded text-xs font-medium text-brand-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:text-brand-300"
                                        x-on:click="open = ! open"
                                        x-bind:aria-expanded="open ? 'true' : 'false'"
                                        aria-expanded="false"
                                    >
                                        <x-ui.icon name="wrench-screwdriver" class="h-3.5 w-3.5" />
                                        <span x-text="open ? 'Hide what to do' : 'What to do'">What to do</span>
                                    </button>

                                    {{-- x-cloak, not `hidden`: with JavaScript off the remedy is simply
                                         shown, which is better than a disclosure nobody can open. --}}
                                    <pre
                                        x-cloak
                                        x-show="open"
                                        class="mt-1.5 overflow-x-auto rounded-lg bg-slate-900 p-2.5 text-2xs text-slate-100 dark:bg-slate-950 dark:text-slate-200"
                                    ><code x-text="probe.remedy">{{ $probe['remedy'] }}</code></pre>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach
        </div>

        {{-- ── 14 days of failed jobs and errors ─────────────────────────────────────── --}}
        @if ($report['trends'])
            <x-ui.card title="Failed jobs and errors" subtitle="The last 14 days." icon="chart-bar">
                <x-ui.chart
                    id="system-health-trends"
                    type="line"
                    :labels="$report['trends']['labels']"
                    :series="$report['trends']['series']"
                    :height="240"
                    y-label="Count"
                    :decimals="0"
                    table-label="Day"
                    summary="Failed queue jobs and logged errors per day over the last 14 days"
                />
            </x-ui.card>
        @endif

        {{-- ── Where each proof stands ───────────────────────────────────────────────── --}}
        @if (! empty($integrity))
            <x-ui.card
                title="Integrity"
                subtitle="The newest run of each suite. The verdict belongs to the suite, not to this screen."
                icon="shield-check"
                :padded="false"
            >
                @if ($integrityUrl)
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="sm" icon="arrow-right" :href="$integrityUrl">
                            Open the register
                        </x-ui.button>
                    </x-slot:actions>
                @endif

                <x-ui.table :is-empty="false" :columns="3" caption="Newest integrity check per suite" :flush="true">
                    <x-slot:head>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Suite</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Last run</th>
                    </x-slot:head>

                    @foreach ($integrity as $row)
                        <tr @class(['bg-rose-50/60 dark:bg-rose-500/5' => $row['blocking']])>
                            <td class="px-4 py-3.5 font-medium text-slate-900 dark:text-white">
                                {{ $row['label'] }}
                            </td>

                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="$row['colour']" size="sm">{{ $row['status'] }}</x-ui.badge>

                                @if ($row['blocking'])
                                    <span class="ml-1 text-2xs font-medium text-rose-600 dark:text-rose-400">blocks go-live</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-right text-slate-600 dark:text-slate-300">
                                <time datetime="{{ $row['ran_at']?->toIso8601String() }}">
                                    {{ $row['ran_at'] ? app_datetime($row['ran_at']) : '—' }}
                                </time>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        <p class="flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
            <x-ui.icon name="information-circle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Money figures here are read from the last reconciliation run and are never recomputed on this
            screen — a health page that re-derived a balance would be a second opinion about whether the
            ledger is intact.
        </p>
    </div>
@endsection
