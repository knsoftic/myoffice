@extends('layouts.admin')

@section('title', 'Project payments')

@php
    $query = request()->query();
    $byValueDate = $dateColumn === 'paid_on';
@endphp

@section('header')
    <x-ui.page-header title="Project payments"
                      subtitle="Client money against a project. A payment is never edited — it is voided and re-entered."
                      icon="banknotes">
        <x-slot:actions>
            @can('project_payments.export')
                <x-ui.button variant="secondary" :href="route('admin.project-payments.export', ['format' => 'csv'] + $query)" icon="arrow-down-tray">
                    Export
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />

            {{-- Both dates are stored, and which one a report means is a real question: "money received
                 in March" and "money entered in March" differ the moment anybody back-dates. --}}
            <x-ui.form.select name="date_column" label="Date filtered">
                <option value="paid_on" @selected($byValueDate)>Value date (paid on)</option>
                <option value="recorded_at" @selected(! $byValueDate)>System date (recorded)</option>
            </x-ui.form.select>

            <x-ui.form.select name="client" label="Client" placeholder="Every client">
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(request('client') == $client->id)>
                        {{ $client->name }} ({{ $client->client_code }})
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="method" label="Method" placeholder="Any method">
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(request('method') === $method->value)>{{ $method->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="commission_state" label="Commission" placeholder="Any state">
                @foreach ($states as $state)
                    <option value="{{ $state->value }}" @selected(request('commission_state') === $state->value)>{{ $state->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="collaborator" label="Partner" placeholder="Everybody">
                @foreach ($collaborators as $collaborator)
                    <option value="{{ $collaborator->id }}" @selected(request('collaborator') == $collaborator->id)>
                        {{ $collaborator->company_name ?: $collaborator->name }} ({{ $collaborator->collaborator_code }})
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Payment, reference or project" />

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="advances_only" value="1" @checked(request()->boolean('advances_only'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Advances only
            </label>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="has_refund" value="1" @checked(request()->boolean('has_refund'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Has a refund
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.project-payments.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$payments->total() . ' ' . \Illuminate\Support\Str::plural('payment', $payments->total())"
               :subtitle="$range->label() . ' · by ' . ($byValueDate ? 'value date' : 'system date')">
        <x-ui.table :is-empty="$payments->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Payment</th>
                <th class="px-4 py-3 text-left font-semibold">Project</th>
                <th class="px-4 py-3 text-left font-semibold">Method</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-right font-semibold">Refunded</th>
                <th class="px-4 py-3 text-right font-semibold">Net received</th>
                <th class="px-4 py-3 text-left font-semibold">Commission</th>
            </x-slot:head>

            @foreach ($payments as $payment)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.project-payments.show', $payment) }}"
                           class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $payment->payment_no }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($payment->paid_on) }}</span>

                        @if ($payment->recorded_at !== null && $payment->recorded_at->toDateString() !== $payment->paid_on->toDateString())
                            <x-ui.badge color="amber" size="xs" :title="'Entered ' . app_datetime($payment->recorded_at)">
                                back-dated
                            </x-ui.badge>
                        @endif

                        @if ($payment->is_advance)
                            <x-ui.badge color="sky" size="xs" title="No invoice behind it yet">advance</x-ui.badge>
                        @endif

                        @if ($payment->status !== \App\Enums\ReceivedPaymentStatus::Cleared)
                            <x-ui.badge :color="$payment->status->color()" size="xs">{{ $payment->status->label() }}</x-ui.badge>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block font-mono text-xs">{{ $payment->project?->code }}</span>
                        <span class="block text-xs text-slate-500">{{ $payment->client?->name }}</span>
                        @if ($payment->milestone)
                            <span class="block text-xs text-slate-500">{{ $payment->milestone->title }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        {{ $payment->payment_method->label() }}
                        @if ($payment->reference_no)
                            <span class="block font-mono text-xs text-slate-500">{{ $payment->reference_no }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ money($payment->amount) }}
                    </td>

                    <td class="px-4 py-3 text-right tabular-nums">
                        @if (bccomp((string) $payment->refunded_amount, '0.00', 2) === 1)
                            <span class="text-rose-600 dark:text-rose-400">{{ money($payment->refunded_amount) }}</span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right tabular-nums text-slate-900 dark:text-white">
                        {{ money($payment->net_received_amount) }}
                    </td>

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$payment->commission_state->color()" size="xs"
                                    :title="$payment->commissionSkipSentence()">
                            {{ $payment->commission_state->label() }}
                        </x-ui.badge>

                        @if ($payment->collaborator)
                            <span class="mt-1 block font-mono text-xs text-slate-500 dark:text-slate-400">
                                {{ $payment->collaborator->collaborator_code }}
                            </span>
                        @endif

                        @if ($payment->commission_skip_reason !== null)
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                                {{ $payment->commission_skip_reason->label() }}
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="banknotes" title="No project payments in this range"
                    description="A payment appears the moment client money is recorded against a project." />
            </x-slot:empty>
        </x-ui.table>

        @if ($payments->isNotEmpty())
            <div class="mt-4 flex flex-wrap justify-end gap-6 border-t border-slate-200 pt-4 text-sm dark:border-slate-700">
                <div class="text-right">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Received</dt>
                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($totals['amount']) }}</dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Refunded</dt>
                    <dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ money($totals['refunded']) }}</dd>
                </div>
                <div class="text-right">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Net received</dt>
                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($totals['net']) }}</dd>
                </div>
            </div>
            <p class="mt-1 text-right text-xs text-slate-500 dark:text-slate-400">
                Over the {{ $payments->total() }} {{ \Illuminate\Support\Str::plural('payment', $payments->total()) }}
                matching this filter, not the page shown.
            </p>
        @endif

        @if ($payments->hasPages())
            <div class="mt-4">{{ $payments->links() }}</div>
        @endif
    </x-ui.card>
@endsection
