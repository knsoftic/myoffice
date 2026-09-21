@extends('layouts.admin')

@section('title', 'Reconciliation of ' . ($row->collaborator?->displayName() ?? 'a wallet'))

@section('header')
    <x-ui.page-header :title="$row->collaborator?->displayName() ?? 'Collaborator #' . $row->collaborator_id"
                      :subtitle="'Checked ' . app_datetime($row->checked_at) . ' · ' . $row->run_type . ' run'"
                      icon="scale"
                      :badge="$row->status->label()"
                      :badge-color="$row->status->color()"
                      :back="route('admin.wallet-reconciliations.index')">
        <x-slot:actions>
            @can('collaborator_wallets.view')
                <x-ui.button variant="secondary" :href="route('admin.wallets.show', $row->collaborator_id)" icon="wallet">
                    Open the wallet
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div @class([
        'mb-4 rounded-lg border p-4 text-sm',
        'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200' => $row->matchesLedger(),
        'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200' => ! $row->matchesLedger(),
    ])>
        <p class="font-semibold">{{ $row->summary() }}</p>
        @if ($row->repaired)
            <p class="mt-1">
                The cache was rewritten from the ledger
                @if ($row->repairedBy) by {{ $row->repairedBy->name }} @endif
                @if ($row->repaired_at) on {{ app_datetime($row->repaired_at) }} @endif.
                No commission row was touched — a repair moves the copy, never the original.
            </p>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Bucket by bucket" subtitle="What the ledger said, and what the wallet was holding at the moment of the check.">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Bucket</th>
                        <th class="px-4 py-3 text-right font-semibold">Ledger</th>
                        <th class="px-4 py-3 text-right font-semibold">Cache</th>
                        <th class="px-4 py-3 text-right font-semibold">Difference</th>
                    </x-slot:head>

                    @foreach (\App\Models\Collaborator\CollaboratorWalletReconciliation::BUCKETS as $bucket)
                        @php
                            $expected = (string) $row->getAttribute('expected_' . $bucket);
                            $stored = (string) $row->getAttribute('stored_' . $bucket);
                            $agrees = bccomp($expected, $stored, 2) === 0;
                        @endphp
                        <tr>
                            <td class="px-4 py-3 capitalize text-slate-700 dark:text-slate-200">{{ $bucket }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($expected) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($stored) }}</td>
                            <td @class([
                                'px-4 py-3 text-right tabular-nums',
                                'text-slate-400' => $agrees,
                                'font-semibold text-rose-600 dark:text-rose-400' => ! $agrees,
                            ])>{{ $agrees ? '—' : money(bcsub($stored, $expected, 2)) }}</td>
                        </tr>
                    @endforeach

                    <tr class="bg-slate-50 dark:bg-slate-900/60">
                        <td class="px-4 py-3 font-medium text-slate-700 dark:text-slate-200">Ledger rows</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ $row->expected_entry_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $row->stored_entry_count }}</td>
                        <td @class([
                            'px-4 py-3 text-right tabular-nums',
                            'text-slate-400' => $row->expected_entry_count === $row->stored_entry_count,
                            'font-semibold text-rose-600 dark:text-rose-400' => $row->expected_entry_count !== $row->stored_entry_count,
                        ])>
                            {{ $row->expected_entry_count === $row->stored_entry_count ? '—' : $row->stored_entry_count - $row->expected_entry_count }}
                        </td>
                    </tr>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="What each check found" subtitle="Spine §6.5.3 — R1 and R8 are drift, R2 to R7 mean the ledger disagrees with itself.">
                @if ($findings === [])
                    <x-ui.empty-state icon="check-circle"
                                      title="Every check passed"
                                      message="The wallet equalled its ledger to the paisa, and every structural invariant held." />
                @else
                    <ul class="divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        @foreach ($findings as $finding)
                            <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                <x-ui.badge :color="($finding['severity'] ?? '') === 'failed' ? 'rose' : 'amber'" size="xs">
                                    {{ $finding['check'] ?? '?' }}
                                </x-ui.badge>
                                <div class="min-w-0">
                                    <p class="text-slate-800 dark:text-slate-100">{{ $finding['message'] ?? '' }}</p>
                                    @if (! empty($finding['details']))
                                        <pre class="mt-1 overflow-x-auto rounded bg-slate-50 p-2 text-xs text-slate-600 dark:bg-slate-950 dark:text-slate-400">{{ json_encode($finding['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="The run">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Total drift</dt>
                        <dd class="text-right font-medium tabular-nums text-slate-900 dark:text-white">{{ money($row->drift_total) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Closed identity (R2)</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $row->identity_holds ? 'Holds' : 'Fails' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Payout cross-check (R3)</dt>
                        <dd class="text-right font-medium tabular-nums text-slate-900 dark:text-white">{{ money($row->payout_cross_check) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Allocation mismatches (R4)</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $row->allocation_mismatch_count }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Orphan allocations (R4)</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $row->orphan_allocation_count }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Bucket cross-foot (R5)</dt>
                        <dd class="text-right font-medium tabular-nums text-slate-900 dark:text-white">{{ money($row->bucket_crossfoot_diff) }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Reversal groups (R6)</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $row->reversal_group_mismatch_count }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Run</dt>
                        <dd class="text-right font-mono text-xs text-slate-600 dark:text-slate-300">
                            <a href="{{ route('admin.wallet-reconciliations.index', ['run' => $row->run_uuid]) }}" class="underline">
                                {{ \Illuminate\Support\Str::limit($row->run_uuid, 13) }}
                            </a>
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($siblings->isNotEmpty())
                <x-ui.card title="Others in the same run" subtitle="So “was it only this partner?” is answerable here.">
                    <ul class="divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        @foreach ($siblings as $sibling)
                            <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                <a href="{{ route('admin.wallet-reconciliations.show', $sibling) }}"
                                   class="truncate text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">
                                    {{ $sibling->collaborator?->displayName() ?? 'Collaborator #' . $sibling->collaborator_id }}
                                </a>
                                <x-ui.badge :color="$sibling->status->color()" size="xs">{{ $sibling->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
