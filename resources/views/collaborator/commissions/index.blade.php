@extends('layouts.panel')

@section('title', 'Commissions')

@section('header')
    <x-ui.page-header title="Your commissions"
                      subtitle="Every commission you have earned, and what happened to it. Commission is earned when money is received — not when somebody registers."
                      icon="calculator">
        <x-slot:actions>
            @can('collaborator_portal.wallet')
                <x-ui.button variant="secondary" :href="route('collaborator.wallet.index')" icon="wallet">Wallet</x-ui.button>
            @endcan
            @can('collaborator_portal.statement_download')
                <x-ui.button variant="secondary" :href="route('collaborator.statement.index')" icon="document-text">Statement</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            <x-ui.form.select name="status" label="State" placeholder="Any state">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="purpose" label="Type" placeholder="Any type">
                @foreach ($purposes as $purpose)
                    <option value="{{ $purpose->value }}" @selected(request('purpose') === $purpose->value)>{{ $purpose->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('collaborator.commissions.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$entries->total() . ' ' . \Illuminate\Support\Str::plural('commission', $entries->total())"
               :subtitle="$range->label() . ' — net ' . money($earnedInRange)">
        <x-ui.table :is-empty="$entries->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">Type</th>
                <th class="px-4 py-3 text-right font-semibold">Worked out on</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-left font-semibold">State</th>
            </x-slot:head>

            @foreach ($entries as $entry)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('collaborator.commissions.show', $entry) }}"
                           class="block text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ app_date($entry->transaction_date) }}
                        </a>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $entry->reference }}</span>
                    </td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$entry->purpose->color()" size="xs">{{ $entry->purpose->label() }}</x-ui.badge>
                        @if (filled($entry->notes))
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($entry->notes, 60) }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                        @if (bccomp((string) $entry->base_amount, '0.00', 2) === 1)
                            {{ money($entry->base_amount) }}
                            @if ($entry->commission_rate !== null)
                                <span class="block">at {{ rtrim(rtrim((string) $entry->commission_rate, '0'), '.') }}%</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>

                    <td @class([
                        'px-4 py-3 text-right font-semibold tabular-nums',
                        'text-rose-600 dark:text-rose-400' => str_starts_with((string) $entry->signed_amount, '-'),
                        'text-slate-900 dark:text-white' => ! str_starts_with((string) $entry->signed_amount, '-'),
                    ])>{{ money($entry->signed_amount) }}</td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$entry->status->color()" size="xs">{{ $entry->status->label() }}</x-ui.badge>
                        @if (bccomp((string) $entry->reversed_amount, '0.00', 2) === 1)
                            <span class="mt-1 block text-xs text-amber-600 dark:text-amber-400">
                                {{ money($entry->reversed_amount) }} returned
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="calculator"
                                  title="No commission in this period"
                                  message="Commission appears when somebody you referred actually pays. A registration on its own earns nothing." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$entries" label="commissions" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
