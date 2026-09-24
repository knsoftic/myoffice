@extends('layouts.admin')

@section('title', $run->suite->label().' — integrity check')

{{--
    One integrity check run (route admin.integrity-checks.show, phase-24-25 §8.6).

    The evidence for one proof: the verdict, the counts, the exact command that produced them, and
    — for whoever holds `integrity_checks.view_logs` — the findings.

    Two things this file is deliberately not doing:

    1.  It never computes a figure. For the `wallet` suite it links to Phase 12's wallet screen and
        stops there (INV-26): a balance rendered here would be a second opinion about whether the
        ledger is intact, and two opinions is worse than one.

    2.  It never decides who may read the findings. `$findings` arrives as null when the reader
        lacks `integrity_checks.view_logs`, so there is nothing on this page to wrap in a `@can`
        and nothing for a later refactor to leak. Null and [] mean different things here, and the
        page says a different sentence for each.
--}}

@php
    $duration = $run->duration_ms === null
        ? '—'
        : ($run->duration_ms < 1000 ? app_number($run->duration_ms).' ms' : app_number($run->duration_ms / 1000, 1).' s');

    $severityColour = static fn (string $severity): string => match (strtolower($severity)) {
        'failed', 'fail', 'critical', 'error' => 'rose',
        'warning', 'warn' => 'amber',
        'passed', 'pass', 'ok' => 'emerald',
        default => 'slate',
    };

    // The identity rows. Built here rather than repeated as eight <div> pairs in the markup.
    $facts = [
        'Run reference' => $run->uuid,
        'Invocation' => $run->run_uuid,
        'Suite' => $run->suite->label(),
        'Scope' => $run->scope ?? 'everything',
        'Triggered by' => ucfirst($run->triggered_by),
        'Started by' => $run->creator?->name ?? 'the scheduler',
        'Started at' => $run->started_at ? app_datetime($run->started_at) : '—',
        'Finished at' => $run->finished_at ? app_datetime($run->finished_at) : '—',
        'Duration' => $duration,
        'Exit code' => $run->exit_code === null ? '—' : (string) $run->exit_code,
        'App version' => $run->app_version ?? '—',
    ];
@endphp

@section('header')
    <x-ui.page-header
        :title="$run->suite->label()"
        :subtitle="$run->suite->description()"
        icon="shield-check"
        :back="route('admin.integrity-checks.index')"
        :badge="$run->status->label()"
        :badge-color="$run->status->color()"
        :breadcrumbs="[
            ['label' => 'Integrity checks', 'url' => route('admin.integrity-checks.index')],
            ['label' => $run->started_at ? app_datetime($run->started_at) : 'Run'],
        ]"
    >
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.integrity-checks.export', $run)">
                    Export evidence
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-4">

        @if ($run->blocksGoLive())
            {{--
                §6.13 / D: a failure always blocks; a warning blocks only on a financial suite. The
                banner states which of the two this is, because "blocked" without a reason is the
                message people learn to click past.
            --}}
            <div class="flex items-start gap-2.5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">This run blocks a go-live.</p>
                    <p class="mt-0.5">
                        @if ($run->status->value === 'warning')
                            A warning on a money suite is not "probably fine": either the ledger re-derives
                            the balance or somebody's money is wrong.
                        @else
                            A check the system depends on did not hold. Fix the cause and run the suite
                            again — this record stays either way.
                        @endif
                    </p>
                </div>
            </div>
        @endif

        {{-- ── The figures ───────────────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat-card
                label="Verdict"
                :value="$run->status->label()"
                :delta-label="$run->status->description()"
                icon="check-badge"
                :color="$run->status->color()"
            />

            <x-ui.stat-card
                label="Checks passed"
                :value="app_number($run->checks_passed).' / '.app_number($run->checks_total)"
                icon="check-circle"
                :color="$run->checks_passed === $run->checks_total ? 'emerald' : 'slate'"
            />

            <x-ui.stat-card
                label="Warned / failed"
                :value="app_number($run->checks_warned).' / '.app_number($run->checks_failed)"
                icon="exclamation-triangle"
                :color="$run->checks_failed > 0 ? 'rose' : ($run->checks_warned > 0 ? 'amber' : 'slate')"
            />

            <x-ui.stat-card
                label="Duration"
                :value="$duration"
                :delta-label="$run->started_at ? app_datetime($run->started_at) : null"
                icon="clock"
                color="sky"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">

            {{-- ── Identity and reproduction ─────────────────────────────────────────── --}}
            <x-ui.card title="This run" subtitle="What was asked, and of which build." icon="finger-print">
                <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ($facts as $label => $value)
                        <div class="flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0">
                            <dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="min-w-0 break-words text-right font-medium text-slate-900 dark:text-white">
                                {{ $value }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                @if (filled($run->command))
                    <div class="mt-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Reproduce it
                        </h3>
                        <pre class="mt-1.5 overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100 dark:bg-slate-950 dark:text-slate-200"><code>{{ $run->command }}</code></pre>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                            The suite delegates to the command that owns the logic; nothing is repaired by a run.
                        </p>
                    </div>
                @endif
            </x-ui.card>

            {{-- ── Findings ──────────────────────────────────────────────────────────── --}}
            <div class="xl:col-span-2">
                @if ($findings === null)
                    {{-- Not "no findings": not yours to read. §4.1 keeps `view_logs` separate from
                         `view` because the findings name tables, columns and routes. --}}
                    <x-ui.card title="Findings" icon="clipboard-document-list">
                        <x-ui.empty-state
                            level="h3"
                            icon="lock-closed"
                            title="The findings are not part of your view"
                            message="The verdict and the counts above are. Reading the raw findings — the tables, columns and routes a check names — needs the integrity_checks.view_logs permission."
                            :compact="true"
                        />
                    </x-ui.card>
                @elseif ($run->findingsReleased())
                    <x-ui.card title="Findings" icon="clipboard-document-list">
                        <x-ui.empty-state
                            level="h3"
                            icon="clock"
                            title="The findings have been released by retention"
                            message="The verdict and the counts are kept for ever; the detail behind them was cleared once the retention window passed. A failed run keeps its findings for three years."
                            :compact="true"
                        />
                    </x-ui.card>
                @elseif ($findings === [])
                    <x-ui.card title="Findings" icon="clipboard-document-list">
                        <x-ui.empty-state
                            level="h3"
                            icon="check-circle"
                            title="Nothing to report"
                            message="Every check in this suite held. A passing run has no findings, and that is not the same as having lost them."
                            :compact="true"
                        />
                    </x-ui.card>
                @else
                    <x-ui.card
                        title="Findings"
                        :subtitle="app_number(count($findings)).' recorded'.($run->findings_truncated ? ' — more existed than were stored' : '')"
                        icon="clipboard-document-list"
                        :padded="false"
                    >
                        <x-slot:actions>
                            {{--
                                Copy-as-CSV (§8.6). The string is built server side and escaped by
                                CsvWriter, so nothing here assembles a cell. Alpine only reads it.
                            --}}
                            <div x-data="{
                                csv: @js($findingsCsv),
                                copied: false,
                                async copy() {
                                    try {
                                        await navigator.clipboard.writeText(this.csv);
                                        this.copied = true;
                                        setTimeout(() => { this.copied = false }, 2500);
                                    } catch (error) {
                                        window.dispatchEvent(new CustomEvent('toast', {
                                            detail: { type: 'error', message: 'Your browser refused the clipboard. Use Export evidence instead.' },
                                        }));
                                    }
                                },
                            }">
                                <x-ui.button variant="secondary" size="sm" icon="clipboard" x-on:click="copy()">
                                    <span x-show="! copied">Copy as CSV</span>
                                    <span x-cloak x-show="copied">Copied</span>
                                </x-ui.button>
                            </div>
                        </x-slot:actions>

                        @if ($run->findings_truncated)
                            <p class="border-b border-amber-200/70 bg-amber-50 px-4 py-2.5 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                                Only the first {{ app_number(\App\Models\Ops\IntegrityCheckRun::MAX_FINDINGS) }}
                                findings were stored. The two-hundredth tells a reader nothing the first twenty did not —
                                what matters is that more existed, and this line is how you know.
                            </p>
                        @endif

                        <x-ui.table :is-empty="false" :columns="5" caption="Findings recorded by this run" :flush="true">
                            <x-slot:head>
                                <th scope="col" class="px-4 py-3 text-left font-semibold">Code</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold">Severity</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold">Subject</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold">Expected</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold">Actual</th>
                            </x-slot:head>

                            @foreach ($findings as $finding)
                                <tr>
                                    <td class="px-4 py-3.5 align-top font-mono text-xs text-slate-700 dark:text-slate-300">
                                        {{ $finding['code'] }}
                                    </td>

                                    <td class="px-4 py-3.5 align-top">
                                        <x-ui.badge :color="$severityColour($finding['severity'])" size="sm">
                                            {{ ucfirst($finding['severity']) }}
                                        </x-ui.badge>
                                    </td>

                                    <td class="px-4 py-3.5 align-top text-slate-700 dark:text-slate-300">
                                        {{ $finding['subject'] ?? '—' }}

                                        @if ($finding['collaborator_id'] !== null && $walletRouteExists)
                                            {{-- Phase 12's wallet screen. The balance is theirs to state, not ours. --}}
                                            <a
                                                href="{{ route('admin.wallets.show', $finding['collaborator_id']) }}"
                                                class="mt-1 block text-xs font-medium text-brand-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:text-brand-300"
                                            >
                                                Open this partner's wallet
                                            </a>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3.5 align-top text-slate-600 dark:text-slate-400">
                                        <span class="block max-w-xs break-words">{{ $finding['expected'] ?? '—' }}</span>
                                    </td>

                                    <td class="px-4 py-3.5 align-top text-slate-600 dark:text-slate-400">
                                        <span class="block max-w-md whitespace-pre-wrap break-words font-mono text-xs">{{ $finding['actual'] ?? '—' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endif
            </div>
        </div>

        {{-- ── The rest of the same invocation ───────────────────────────────────────── --}}
        @if ($siblings->isNotEmpty())
            <x-ui.card
                title="The rest of this sweep"
                subtitle="One invocation writes one row per suite, all sharing an invocation reference."
                icon="rectangle-stack"
                :padded="false"
            >
                <x-ui.table :is-empty="false" :columns="4" caption="Other suites from the same invocation" :flush="true">
                    <x-slot:head>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Suite</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Passed / warned / failed</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($siblings as $sibling)
                        <tr @class(['bg-rose-50/60 dark:bg-rose-500/5' => $sibling->blocksGoLive()])>
                            <td class="px-4 py-3.5 font-medium text-slate-900 dark:text-white">
                                {{ $sibling->suite->label() }}
                            </td>

                            <td class="px-4 py-3.5">
                                <x-ui.badge :color="$sibling->status->color()" size="sm">
                                    {{ $sibling->status->label() }}
                                </x-ui.badge>
                            </td>

                            <td class="px-4 py-3.5 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_number($sibling->checks_passed) }} /
                                {{ app_number($sibling->checks_warned) }} /
                                {{ app_number($sibling->checks_failed) }}
                            </td>

                            <td class="px-4 py-3.5 text-right">
                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    icon="eye"
                                    :href="route('admin.integrity-checks.show', $sibling)"
                                >
                                    View
                                </x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        {{--
            No delete, no edit, and the page says so rather than leaving a reader to notice the
            absence. `integrity_check_runs` is append-only (D19, §2.3): evidence somebody can
            delete after reading it is not evidence.
        --}}
        <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <x-ui.icon name="lock-closed" class="h-3.5 w-3.5" />
            This record cannot be edited or deleted. Retention may release the findings; the verdict is kept for ever.
        </p>
    </div>
@endsection
