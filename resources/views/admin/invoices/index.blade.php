@extends('layouts.admin')

@section('title', 'Invoices')

@php
    $query = request()->query();
@endphp

@section('header')
    <x-ui.page-header title="Invoices"
                      subtitle="What clients have been billed. An invoice holds no cash — every rupee against it is a receipt the payments register owns."
                      icon="document-text">
        <x-slot:actions>
            @can('invoices.export')
                <x-ui.button variant="secondary" :href="route('admin.invoices.export', ['format' => 'csv'] + $query)" icon="arrow-down-tray">
                    Export
                </x-ui.button>
            @endcan
            @if ($canCreate)
                <x-ui.button variant="primary" :href="route('admin.invoices.create')" icon="plus">New invoice</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <x-ui.stat-card label="Invoices" :value="$invoices->total()" icon="document-text" color="slate"
                        :delta-label="$range->label()" />
        <x-ui.stat-card label="Overdue" :value="$overdueCount" icon="exclamation-triangle"
                        :color="$overdueCount > 0 ? 'rose' : 'slate'"
                        delta-label="past the due date and unpaid" />
        @if ($outstanding !== null)
            <x-ui.stat-card label="Outstanding" :value="money($outstanding)" icon="banknotes" color="amber"
                            delta-label="still expected on this list" />
        @endif
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input type="date" name="from" label="Issued from" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
            <x-ui.form.input type="date" name="to" label="Issued to" :value="request('to', app_date($range->end(), 'Y-m-d'))" />
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Number, title, reference or client" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="client" label="Client" placeholder="Any client">
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(request('client') == $client->id)>
                        {{ $client->company_name ?: $client->name }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="outstanding" value="1" @checked(request()->boolean('outstanding'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Outstanding only
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.invoices.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @unless ($fields->seesMoney)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            You can see which invoices exist and where they stand. The amounts need
            <code>invoices.view_financial</code>, which is a separate permission.
        </div>
    @endunless

    <x-ui.card :title="$invoices->total() . ' ' . \Illuminate\Support\Str::plural('invoice', $invoices->total())"
               :subtitle="$range->label()">
        <x-ui.table :is-empty="$invoices->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Invoice</th>
                <th class="px-4 py-3 text-left font-semibold">Client</th>
                <th class="px-4 py-3 text-left font-semibold">Dates</th>
                @if ($fields->seesMoney)
                    <th class="px-4 py-3 text-right font-semibold">Total</th>
                    <th class="px-4 py-3 text-right font-semibold">Paid</th>
                    <th class="px-4 py-3 text-right font-semibold">Balance</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($invoices as $invoice)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.invoices.show', $invoice) }}"
                           class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $invoice->draft_reference }}
                        </a>
                        @if (filled($invoice->title))
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::limit($invoice->title, 40) }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-900 dark:text-white">
                            {{ $invoice->client?->company_name ?: $invoice->client?->name }}
                        </span>
                        @if ($invoice->project)
                            <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $invoice->project->code }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                        <span class="block">issued {{ app_date($invoice->issue_date) }}</span>
                        <span @class([
                            'block',
                            'text-rose-600 dark:text-rose-400' => $invoice->status === \App\Enums\InvoiceStatus::Overdue,
                        ])>due {{ app_date($invoice->due_date) }}</span>
                    </td>

                    @if ($fields->seesMoney)
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($invoice->total_amount) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            {{ money($invoice->paid_amount) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span @class([
                                'tabular-nums',
                                'text-emerald-600 dark:text-emerald-400' => $invoice->isOverpaid(),
                                'font-semibold text-slate-900 dark:text-white' => ! $invoice->isOverpaid(),
                            ])>{{ money($invoice->balance_amount) }}</span>
                            @if ($invoice->isOverpaid())
                                <span class="block text-xs text-emerald-600 dark:text-emerald-400">credit</span>
                            @endif
                        </td>
                    @endif

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$invoice->status->color()" size="xs">{{ $invoice->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="document-text"
                                  title="No invoices in this range"
                                  message="A draft carries no number until it is issued, so starting one costs nothing." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$invoices" label="invoices" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
