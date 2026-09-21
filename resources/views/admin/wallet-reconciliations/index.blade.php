@extends('layouts.admin')

@section('title', 'Wallet reconciliations')

@section('header')
    <x-ui.page-header title="Wallet reconciliations"
                      subtitle="A dated record that each wallet was re-derived from its ledger — and whether the two agreed."
                      icon="scale"
                      :back="route('admin.wallets.index')">
        <x-slot:actions>
            @if ($canRun)
                <x-ui.button variant="primary" icon="play" x-on:click.prevent="$dispatch('open-modal', 'run-reconciliation')">
                    Run now
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Last run" icon="clock" color="slate"
                        :value="$latest === null ? 'Never' : app_datetime($latest->checked_at)"
                        :delta-label="$latest === null ? 'the nightly job has not run yet' : $latest->run_type" />
        <x-ui.stat-card label="Wallets in that run" :value="$latestChecked" icon="wallet" color="sky" />
        <x-ui.stat-card label="Problems in that run" :value="$latestProblems" icon="exclamation-triangle"
                        :color="$latestProblems > 0 ? 'rose' : 'emerald'" />
        <x-ui.stat-card label="Open problems" :value="$openProblems" icon="flag"
                        :color="$openProblems > 0 ? 'rose' : 'emerald'"
                        delta-label="across every run ever recorded" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name, company or code" />

            <x-ui.form.select name="status" label="Result" placeholder="Any result">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="run" label="Run" :value="request('run')" placeholder="Run uuid" />

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="problems" value="1" @checked(request()->boolean('problems'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Problems only
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.wallet-reconciliations.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$rows->total() . ' ' . \Illuminate\Support\Str::plural('check', $rows->total())"
               subtitle="Every run is recorded, pass or fail. A table holding only failures proves nothing about the days it says nothing about.">
        <x-ui.table :is-empty="$rows->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Checked</th>
                <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
                <th class="px-4 py-3 text-right font-semibold">Available (ledger)</th>
                <th class="px-4 py-3 text-right font-semibold">Drift</th>
                <th class="px-4 py-3 text-left font-semibold">Result</th>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.wallet-reconciliations.show', $row) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ app_datetime($row->checked_at) }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $row->run_type }} · {{ $row->duration_ms }} ms</span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-900 dark:text-white">
                            {{ $row->collaborator?->displayName() ?? 'Collaborator #' . $row->collaborator_id }}
                        </span>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">
                            {{ $row->collaborator?->collaborator_code }}
                        </span>
                    </td>

                    <td class="px-4 py-3 text-right tabular-nums text-slate-900 dark:text-white">
                        {{ money($row->expected_available) }}
                    </td>

                    <td @class([
                        'px-4 py-3 text-right tabular-nums',
                        'text-slate-400' => bccomp((string) $row->drift_total, '0.00', 2) === 0,
                        'font-semibold text-rose-600 dark:text-rose-400' => bccomp((string) $row->drift_total, '0.00', 2) !== 0,
                    ])>
                        {{ bccomp((string) $row->drift_total, '0.00', 2) === 0 ? '—' : money($row->drift_total) }}
                    </td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge>
                        @if ($row->repaired)
                            <span class="mt-1 block text-xs text-sky-600 dark:text-sky-400">cache rewritten</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="scale"
                                  title="Nothing has been checked yet"
                                  message="The nightly job runs at 01:30. Run it now to record the first proof."
/>
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$rows" label="checks" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>

    @if ($canRun)
        <x-ui.modal name="run-reconciliation" title="Run the reconciliation" icon="scale">
            <form method="POST" action="{{ route('admin.wallet-reconciliations.run') }}" class="space-y-4">
                @csrf

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Eight checks per partner: the cache against the derivation, the closed identity, the payout
                    cross-check, allocation integrity, the bucket cross-foot, reversal groups, entitlement releases,
                    and that no payment was processed without leaving a trace of why.
                </p>

                <x-ui.form.select name="collaborator_id" label="Collaborator" placeholder="Everybody">
                    @foreach ($collaborators as $collaborator)
                        <option value="{{ $collaborator->id }}">
                            {{ $collaborator->company_name ?: $collaborator->name }} ({{ $collaborator->collaborator_code }})
                        </option>
                    @endforeach
                </x-ui.form.select>

                <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="repair" value="1"
                           class="mt-0.5 rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                    <span>
                        <span class="font-medium text-slate-900 dark:text-white">Repair a drifted cache</span>
                        <span class="mt-0.5 block text-xs">
                            Rewrites the wallet from the ledger where it is safe to. It never touches a commission
                            row, and it refuses outright on a ledger that disagrees with itself.
                        </span>
                    </span>
                </label>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'run-reconciliation')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Run</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
