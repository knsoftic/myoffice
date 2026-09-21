@extends('layouts.admin')

@section('title', 'Commission discrepancies')

@section('header')
    <x-ui.page-header title="Commission discrepancies"
                      subtitle="Promises that released more than they later turned out to be worth. Nothing is ever clawed back automatically — these wait for a person."
                      icon="exclamation-triangle"
                      :back="route('admin.commissions.index')" />
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card :label="$showResolved ? 'Resolved' : 'Open'" :value="$openCount" icon="flag"
                        :color="$openCount > 0 && ! $showResolved ? 'amber' : 'slate'" />
        <x-ui.stat-card label="Over-released" :value="money($openTotal)" icon="scale" color="rose"
                        delta-label="released above the revised promise" />
        <x-ui.stat-card label="Money moved by this screen" value="Rs 0.00" icon="lock-closed" color="emerald"
                        delta-label="accepting is a note, not a transaction" />
    </div>

    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
        <p class="font-medium text-slate-900 dark:text-white">Why a row is here</p>
        <p class="mt-1">
            A discount applied <em>after</em> a payment, or a project value revised downward, lowers what the
            promise was worth. Commission already released against the old figure stands — it was correct when
            it was released, and no money left the company wrongly. The difference is reported here instead.
        </p>
        <p class="mt-1">
            There are two honest endings. Post a <strong>manual adjustment</strong> with a written reason, which
            writes a real ledger row. Or <strong>accept it</strong>, which writes a note and closes the row and
            moves nothing.
        </p>
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name, company or code" />

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="resolved" value="1" @checked($showResolved)
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Show ones already dealt with
            </label>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.commission-discrepancies.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$rows->total() . ' ' . \Illuminate\Support\Str::plural('discrepancy', $rows->total())">
        <x-ui.table :is-empty="$rows->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
                <th class="px-4 py-3 text-left font-semibold">Document</th>
                <th class="px-4 py-3 text-right font-semibold">Promised</th>
                <th class="px-4 py-3 text-right font-semibold">Released</th>
                <th class="px-4 py-3 text-right font-semibold">Over</th>
                <th class="px-4 py-3 text-left font-semibold">Why</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.wallets.show', $row->collaborator_id) }}"
                           class="block text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $row->collaborator?->displayName() ?? 'Collaborator #' . $row->collaborator_id }}
                        </a>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">
                            {{ $row->collaborator?->collaborator_code }}
                        </span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-700 dark:text-slate-200">{{ $row->document_type }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            opened {{ app_date($row->opened_on) }}
                        </span>
                    </td>

                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($row->entitlement_amount) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($row->released_amount) }}</td>

                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-rose-600 dark:text-rose-400">
                        {{ money($row->over_released_amount) }}
                    </td>

                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $row->supersede_reason ?? '—' }}
                        @if ($row->closed_on !== null)
                            <span class="mt-1 block text-xs text-emerald-600 dark:text-emerald-400">
                                closed {{ app_date($row->closed_on) }}
                            </span>
                            @if (isset($acceptedReasons[$row->id]))
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                                    Accepted: {{ $acceptedReasons[$row->id] }}
                                </span>
                            @endif
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right">
                        @if ($canAccept && $row->closed_on === null)
                            <form method="POST" action="{{ route('admin.commission-discrepancies.accept', $row) }}"
                                  class="inline-flex items-center gap-2">
                                @csrf
                                <input type="text" name="reason" required minlength="10" maxlength="255"
                                       placeholder="Why this is being accepted"
                                       class="w-56 rounded-lg border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                                <x-ui.button type="submit" size="sm" variant="secondary" icon="check">Accept</x-ui.button>
                            </form>
                        @elseif ($row->closed_on !== null)
                            <span class="text-xs text-slate-400">dealt with</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="check-circle"
                                  :title="$showResolved ? 'Nothing has been accepted yet' : 'No discrepancies'"
                                  message="Every promise has released no more than it turned out to be worth." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$rows" label="discrepancies" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
