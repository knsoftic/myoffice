@extends('layouts.admin')

@section('title', 'Payments received')

@php
    $sourceRoute = static function (object $row): ?string {
        return match ($row->source) {
            'project_payment' => \Illuminate\Support\Facades\Route::has('admin.project-payments.show')
                ? route('admin.project-payments.show', $row->source_id) : null,
            'income' => route('admin.income.show', $row->source_id),
            default => null,
        };
    };

    $sourceColor = [
        'project_payment' => 'brand',
        'student_fee' => 'violet',
        'income' => 'emerald',
    ];
@endphp

@section('header')
    <x-ui.page-header title="Payments received"
                      subtitle="Every rupee that came in, whichever business it came from. Read-only — each row links back to the register that owns it."
                      icon="banknotes">
        <x-slot:actions>
            @can('payments.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.payments.export', ['format' => 'csv'] + request()->query())">
                    Export
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($omitted !== [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-semibold">This is not every payment.</p>
            <p class="mt-1">
                Not included, because you may not see them: <strong>{{ implode(', ', $omitted) }}</strong>.
                They are left out of the union and out of the totals rather than quietly folded into a
                number you would have no way of questioning.
            </p>
        </div>
    @endif

    @if ($totals !== [])
        <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Receipts" :value="$totals['entries']" icon="receipt-percent" color="slate"
                            :delta-label="$range->label()" />
            <x-ui.stat-card label="Received" :value="money($totals['amount'])" icon="banknotes" color="emerald" />
            <x-ui.stat-card label="Refunded" :value="money($totals['refunded'])" icon="arrow-uturn-left"
                            :color="bccomp((string) $totals['refunded'], '0.00', 2) === 1 ? 'amber' : 'slate'" />
            <x-ui.stat-card label="Net" :value="money($totals['net'])" icon="calculator" color="brand"
                            delta-label="what the income report sums" />
        </div>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            <x-ui.form.select name="source" label="Source" placeholder="Every source you can see">
                @foreach ($sources as $key => $label)
                    <option value="{{ $key }}" @selected(request('source') === $key)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="method" label="Method" placeholder="Any method">
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(request('method') === $method->value)>{{ $method->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="refunded" value="1" @checked(request()->boolean('refunded'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Has a refund
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.payments.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$payments->total() . ' ' . \Illuminate\Support\Str::plural('receipt', $payments->total())"
               :subtitle="$range->label() . ' · dated on the value date'">
        <x-ui.table :is-empty="$payments->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Source</th>
                <th class="px-4 py-3 text-left font-semibold">Reference</th>
                <th class="px-4 py-3 text-left font-semibold">Payer</th>
                <th class="px-4 py-3 text-left font-semibold">Received</th>
                @if ($fields->seesMoney)
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    <th class="px-4 py-3 text-right font-semibold">Refunded</th>
                    <th class="px-4 py-3 text-right font-semibold">Net</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($payments as $row)
                @php $link = $sourceRoute($row); @endphp
                <tr>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$sourceColor[$row->source] ?? 'slate'" size="xs">
                            {{ $sources[$row->source] ?? $row->source }}
                        </x-ui.badge>
                    </td>

                    <td class="px-4 py-3 font-mono text-xs">
                        @if ($link)
                            <a href="{{ $link }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                {{ $row->reference }}
                            </a>
                        @else
                            <span class="text-slate-900 dark:text-white">{{ $row->reference }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row->payer ?: '—' }}</td>

                    <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                        <span class="block">{{ app_date($row->paid_on) }}</span>
                        <span class="block text-slate-500 dark:text-slate-400">
                            {{ \App\Enums\PaymentMethod::tryFrom((string) $row->payment_method)?->label() ?? $row->payment_method }}
                        </span>
                        @if ($row->recorded_at && app_date($row->recorded_at, 'Y-m-d') !== app_date($row->paid_on, 'Y-m-d'))
                            <span class="mt-0.5 inline-block rounded bg-slate-100 px-1 text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                back-dated
                            </span>
                        @endif
                    </td>

                    @if ($fields->seesMoney)
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($row->amount) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                            {{ bccomp((string) $row->refunded_amount, '0.00', 2) === 1 ? money($row->refunded_amount) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($row->net_received_amount) }}
                        </td>
                    @endif

                    <td class="px-4 py-3">
                        <x-ui.badge color="slate" size="xs">{{ \Illuminate\Support\Str::headline((string) $row->status) }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes"
                                  title="No money received in this range"
                                  :message="$sources === []
                                      ? 'No payment source is open to you, so this register has nothing to show.'
                                      : 'Receipts appear here the moment they are recorded in their own register.'" />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$payments" label="receipts" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
