@extends('layouts.panel')

@section('title', $record->invoice_number ?? 'Invoice')

{{--
    Client panel invoice detail — client.invoices.show (phase-13 §8.12).

    $record is whatever InvoicesSection::find() returned for *this* client; another client's id never
    gets here, because find() answers null and the controller turns that into a 404 rather than a 403
    that would confirm the invoice exists.

    Read-only by construction: the panel registers no write route for this section, so there is nothing
    on this page that changes anything.
--}}

@php
    $invoice = $record;
    $items = $invoice->relationLoaded('items') ? $invoice->items : $invoice->items()->orderBy('sort_order')->get();
    $payments = $payments ?? collect();

    $hasTax = bccomp((string) $invoice->tax_amount, '0.00', 2) === 1;
    $hasDiscount = bccomp((string) $invoice->total_discount_amount, '0.00', 2) === 1;
    $hasRounding = bccomp((string) $invoice->round_off_amount, '0.00', 2) !== 0;
    $settled = bccomp((string) $invoice->balance_amount, '0.00', 2) <= 0;
@endphp

@section('header')
    @include('client.partials.header', [
        'client' => $client,
        'title' => $invoice->invoice_number,
        'subtitle' => $invoice->title ?: 'Invoice',
        'icon' => 'document-text',
    ])
@endsection

@section('content')
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card label="Total" :value="money($invoice->total_amount)" icon="banknotes" />
            <x-ui.stat-card label="Paid" :value="money($invoice->paid_amount)" icon="check-circle" color="emerald" />
            <x-ui.stat-card label="Balance" :value="money($invoice->balance_amount)" icon="scale"
                            :color="$settled ? 'emerald' : 'amber'" />
        </div>

        @if (! $settled)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                <p class="font-semibold">{{ money($invoice->balance_amount) }} is still open on this invoice.</p>
                <p class="mt-1">
                    It was due on {{ app_date($invoice->due_date) }}{{ $invoice->payment_terms_days > 0 ? ' ('.$invoice->payment_terms_days.'-day terms)' : '' }}.
                </p>
            </div>
        @endif

        <x-ui.card title="What this covers">
            <x-ui.table>
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">Description</th>
                    <th class="px-4 py-3 text-right font-semibold">Qty</th>
                    <th class="px-4 py-3 text-right font-semibold">Rate</th>
                    <th class="px-4 py-3 text-right font-semibold">Amount</th>
                </x-slot:head>

                @foreach ($items as $item)
                    <tr>
                        <td class="px-4 py-3">
                            <span class="block text-slate-900 dark:text-white">{{ $item->description }}</span>
                            @if (filled($item->details))
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $item->details }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}{{ filled($item->unit) ? ' '.$item->unit : '' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($item->unit_price) }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($item->line_total) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>

            <x-slot:footer>
                <dl class="ml-auto max-w-xs space-y-1.5 text-sm">
                    <div class="flex justify-between gap-6">
                        <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                        <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($invoice->subtotal_amount) }}</dd>
                    </div>
                    @if ($hasDiscount)
                        <div class="flex justify-between gap-6">
                            <dt class="text-slate-500 dark:text-slate-400">Discount</dt>
                            <dd class="tabular-nums text-slate-600 dark:text-slate-300">−{{ money($invoice->total_discount_amount) }}</dd>
                        </div>
                    @endif
                    @if ($hasTax)
                        <div class="flex justify-between gap-6">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $invoice->tax_label ?: 'Tax' }}</dt>
                            <dd class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($invoice->tax_amount) }}</dd>
                        </div>
                    @endif
                    @if ($hasRounding)
                        <div class="flex justify-between gap-6">
                            <dt class="text-slate-500 dark:text-slate-400">Rounding</dt>
                            <dd class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($invoice->round_off_amount) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-6 border-t border-slate-200 pt-1.5 dark:border-slate-800">
                        <dt class="font-medium text-slate-900 dark:text-white">Total</dt>
                        <dd class="text-base font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($invoice->total_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-6">
                        <dt class="text-slate-500 dark:text-slate-400">Paid</dt>
                        <dd class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($invoice->paid_amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-6">
                        <dt class="font-medium text-slate-900 dark:text-white">Balance</dt>
                        <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($invoice->balance_amount) }}</dd>
                    </div>
                </dl>
            </x-slot:footer>
        </x-ui.card>

        @if ($payments->isNotEmpty())
            <x-ui.card title="What you have paid">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Receipt</th>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">Method</th>
                        <th class="px-4 py-3 text-right font-semibold">Amount</th>
                    </x-slot:head>

                    @foreach ($payments as $payment)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">{{ $payment->payment_no }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($payment->paid_on) }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $payment->payment_method->label() }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($payment->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        @if (filled($invoice->notes))
            <x-ui.card title="Notes">
                <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $invoice->notes }}</p>
            </x-ui.card>
        @endif

        @if (filled($invoice->bank_details))
            <x-ui.card title="How to pay">
                <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $invoice->bank_details }}</p>
            </x-ui.card>
        @endif

        @if (filled($invoice->footer_note))
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $invoice->footer_note }}</p>
        @endif
    </div>
@endsection
