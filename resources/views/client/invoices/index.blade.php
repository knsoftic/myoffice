@extends('layouts.panel')

@section('title', 'My Invoices')

{{--
    Client panel invoices — client.invoices.index (phase-13 §8.12, §9's Client row).

    There is no Draft tab, and not because the template omits it: `InvoicesSection::query()` never
    selects a draft, and `COLUMNS` never selects `internal_notes`, `created_by`, `project_id` cost data
    or any commission column. What is not fetched cannot leak.

    Money is always visible here — these are the client's own figures — and it is the one place in the
    system where that is true without a `view_financial` check.

    Variables (Client\InvoiceController@index → ServesClientPortal::sectionList('invoices')):
      $client, $section, $items (LengthAwarePaginator<Invoice>), $filters, $clientName, $portalSections
--}}

@php
    $invoices = $items ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);

    $outstanding = collect($invoices->items())
        ->filter(fn ($invoice) => $invoice->status !== \App\Enums\InvoiceStatus::Paid
            && $invoice->status !== \App\Enums\InvoiceStatus::Cancelled)
        ->reduce(fn (string $carry, $invoice): string => bcadd($carry, (string) $invoice->balance_amount, 2), '0.00');

    $overdue = collect($invoices->items())
        ->filter(fn ($invoice) => $invoice->status === \App\Enums\InvoiceStatus::Overdue)
        ->count();
@endphp

@section('header')
    @include('client.partials.header', [
        'client' => $client,
        'title' => 'My Invoices',
        'subtitle' => 'Everything we have billed you, and what is still open.',
        'icon' => 'document-text',
    ])
@endsection

@section('content')
    <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.stat-card label="Outstanding on this page" :value="money($outstanding)" icon="document-text"
                            :color="bccomp($outstanding, '0.00', 2) === 1 ? 'amber' : 'emerald'" />
            <x-ui.stat-card label="Overdue" :value="$overdue" icon="exclamation-triangle"
                            :color="$overdue > 0 ? 'rose' : 'slate'" />
            <x-ui.stat-card label="Invoices" :value="$invoices->total()" icon="rectangle-stack" color="slate" />
        </div>

        <x-ui.tabs :tabs="[
            ['label' => 'Outstanding', 'url' => route('client.invoices.index', ['status' => 'outstanding']), 'active' => request('status') === 'outstanding'],
            ['label' => 'Paid', 'url' => route('client.invoices.index', ['status' => 'paid']), 'active' => request('status') === 'paid'],
            ['label' => 'All', 'url' => route('client.invoices.index'), 'active' => ! request()->filled('status')],
        ]" />

        <x-ui.filter-bar placeholder="Search by invoice number…" :reset="route('client.invoices.index')" />

        @if ($invoices->isEmpty())
            <x-ui.empty-state icon="document-text"
                              title="No invoices yet"
                              description="When we bill you, the invoice appears here with what is still open on it." />
        @else
            <x-ui.card :padded="false">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Invoice</th>
                        <th class="px-4 py-3 text-left font-semibold">Dates</th>
                        <th class="px-4 py-3 text-right font-semibold">Total</th>
                        <th class="px-4 py-3 text-right font-semibold">Paid</th>
                        <th class="px-4 py-3 text-right font-semibold">Balance</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($invoices as $invoice)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('client.invoices.show', $invoice->getKey()) }}"
                                   class="block font-mono text-xs font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $invoice->invoice_number }}
                                </a>
                                @if (filled($invoice->title))
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        {{ \Illuminate\Support\Str::limit($invoice->title, 40) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                <span class="block">issued {{ app_date($invoice->issue_date) }}</span>
                                <span @class([
                                    'block',
                                    'text-rose-600 dark:text-rose-400' => $invoice->status === \App\Enums\InvoiceStatus::Overdue,
                                ])>due {{ app_date($invoice->due_date) }}</span>
                            </td>

                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($invoice->total_amount) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ money($invoice->paid_amount) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($invoice->balance_amount) }}
                            </td>

                            <td class="px-4 py-3">
                                <x-ui.badge :color="$invoice->status->color()" size="xs">{{ $invoice->status->label() }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:footer>
                        <x-ui.pagination-summary :paginator="$invoices" label="invoices" />
                    </x-slot:footer>
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>
@endsection
