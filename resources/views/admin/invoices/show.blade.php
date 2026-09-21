@extends('layouts.admin')

@section('title', $invoice->draft_reference)

@php
    $isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft;
    $isCancelled = $invoice->status === \App\Enums\InvoiceStatus::Cancelled;
@endphp

@section('header')
    <x-ui.page-header :title="$invoice->draft_reference"
                      :subtitle="($invoice->title ?: 'Invoice') . ' · ' . ($invoice->client?->company_name ?: $invoice->client?->name)"
                      icon="document-text"
                      :badge="$invoice->status->label()"
                      :badge-color="$invoice->status->color()"
                      :back="route('admin.invoices.index')">
        <x-slot:actions>
            @can('invoices.print')
                <x-ui.button variant="secondary" target="_blank" :href="route('admin.invoices.print', $invoice)" icon="printer">
                    Print
                </x-ui.button>
            @endcan

            @if ($canEdit && $invoice->status->isEditable() && ! $invoice->hasReceipts())
                <x-ui.button variant="secondary" :href="route('admin.invoices.edit', $invoice)" icon="pencil">Edit</x-ui.button>
            @endif

            @if ($canChangeStatus && $isDraft)
                <form method="POST" action="{{ route('admin.invoices.issue', $invoice) }}">
                    @csrf
                    <x-ui.button type="submit" variant="primary" icon="check">Issue</x-ui.button>
                </form>
            @endif

            @if ($canChangeStatus && ! $isCancelled)
                <x-ui.button variant="primary" icon="paper-airplane" x-on:click.prevent="$dispatch('open-modal', 'send-invoice')">
                    {{ $invoice->sent_at === null ? 'Mark sent' : 'Send again' }}
                </x-ui.button>
            @endif

            @can('invoices.create')
                <form method="POST" action="{{ route('admin.invoices.duplicate', $invoice) }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" icon="document-duplicate">Duplicate</x-ui.button>
                </form>
            @endcan

            @if ($canChangeStatus && ! $isCancelled)
                <x-ui.button variant="danger" icon="x-mark" x-on:click.prevent="$dispatch('open-modal', 'cancel-invoice')">
                    Cancel
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($isDraft)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            This is still a draft. It has <strong>no invoice number</strong> and no client has seen it — which is
            what keeps the series gap-free: deleting it costs nothing.
        </div>
    @endif

    @if ($isCancelled)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
            <p class="font-semibold">Cancelled on {{ app_date($invoice->cancelled_at) }}{{ $invoice->canceller ? ' by '.$invoice->canceller->name : '' }}.</p>
            <p class="mt-1">{{ $invoice->cancellation_reason }}</p>
            <p class="mt-1">It keeps its number for ever: a reused number makes two documents answer to one reference.</p>
        </div>
    @endif

    @if ($invoice->replaces)
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
            This replaces <a href="{{ route('admin.invoices.show', $invoice->replaces) }}" class="font-semibold underline">{{ $invoice->replaces->invoice_number }}</a>.
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="What is being charged for">
                <x-ui.table>
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Description</th>
                        <th class="px-4 py-3 text-right font-semibold">Qty</th>
                        @if ($fields->seesMoney)
                            <th class="px-4 py-3 text-right font-semibold">Rate</th>
                            <th class="px-4 py-3 text-right font-semibold">Discount</th>
                            <th class="px-4 py-3 text-right font-semibold">Line total</th>
                        @endif
                    </x-slot:head>

                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block text-slate-900 dark:text-white">{{ $item->description }}</span>
                                @if (filled($item->details))
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $item->details }}</span>
                                @endif
                                @unless ($item->is_taxable)
                                    <span class="mt-1 inline-block text-xs text-slate-500 dark:text-slate-400">exempt</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}{{ filled($item->unit) ? ' '.$item->unit : '' }}
                            </td>
                            @if ($fields->seesMoney)
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($item->unit_price) }}</td>
                                <td class="px-4 py-3 text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                                    {{ bccomp((string) $item->discount_amount, '0.00', 2) === 0 && bccomp((string) $item->allocated_discount_amount, '0.00', 2) === 0
                                        ? '—'
                                        : money(bcadd((string) $item->discount_amount, (string) $item->allocated_discount_amount, 2)) }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($item->line_total) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </x-ui.table>

                @if ($fields->seesMoney)
                    <x-slot:footer>
                        <dl class="ml-auto max-w-xs space-y-1.5 text-sm">
                            <div class="flex justify-between gap-6">
                                <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                                <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($invoice->subtotal_amount) }}</dd>
                            </div>
                            @if (bccomp((string) $invoice->total_discount_amount, '0.00', 2) === 1)
                                <div class="flex justify-between gap-6">
                                    <dt class="text-slate-500 dark:text-slate-400">Discount</dt>
                                    <dd class="tabular-nums text-slate-600 dark:text-slate-300">−{{ money($invoice->total_discount_amount) }}</dd>
                                </div>
                            @endif
                            @if (bccomp((string) $invoice->tax_amount, '0.00', 2) === 1)
                                <div class="flex justify-between gap-6">
                                    <dt class="text-slate-500 dark:text-slate-400">{{ $invoice->tax_label ?: 'Tax' }}</dt>
                                    <dd class="tabular-nums text-slate-600 dark:text-slate-300">{{ money($invoice->tax_amount) }}</dd>
                                </div>
                            @endif
                            @if (bccomp((string) $invoice->round_off_amount, '0.00', 2) !== 0)
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
                                <dd @class([
                                    'font-semibold tabular-nums',
                                    'text-emerald-600 dark:text-emerald-400' => $invoice->isOverpaid(),
                                    'text-slate-900 dark:text-white' => ! $invoice->isOverpaid(),
                                ])>{{ money($invoice->balance_amount) }}</dd>
                            </div>
                        </dl>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            <x-ui.card title="Receipts against this invoice"
                       subtitle="Owned by the payments register. This screen only says which of them settle this document.">
                <x-ui.table :is-empty="$invoice->payments->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Receipt</th>
                        <th class="px-4 py-3 text-left font-semibold">Paid on</th>
                        @if ($fields->seesMoney)
                            <th class="px-4 py-3 text-right font-semibold">Amount</th>
                            <th class="px-4 py-3 text-right font-semibold">Net</th>
                        @endif
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($invoice->payments as $payment)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">{{ $payment->payment_no }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($payment->paid_on) }}</td>
                            @if ($fields->seesMoney)
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money($payment->amount) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($payment->net_received_amount) }}</td>
                            @endif
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$payment->status->color()" size="xs">{{ $payment->status->label() }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($canLink && ! $isCancelled)
                                    <form method="POST" action="{{ route('admin.invoices.payments.unapply', [$invoice, $payment]) }}"
                                          class="inline-flex items-center gap-2">
                                        @csrf
                                        @method('DELETE')
                                        <input type="text" name="reason" required minlength="5" maxlength="255"
                                               placeholder="Why detach it"
                                               class="w-44 rounded-lg border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                                        <x-ui.button type="submit" size="sm" variant="ghost">Detach</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="banknotes"
                                          title="Nothing received yet"
                                          message="Receipts are recorded in the payments register and attached here." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            @if ($canLink && $unapplied->isNotEmpty() && ! $isCancelled)
                <x-ui.card title="This client has paid in advance"
                           subtitle="Money received with no invoice attached. Attaching it moves no commission — the engine reads the payment, never the invoice.">
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Receipt</th>
                            <th class="px-4 py-3 text-left font-semibold">Paid on</th>
                            @if ($fields->seesMoney)
                                <th class="px-4 py-3 text-right font-semibold">Available</th>
                            @endif
                            <th class="px-4 py-3"><span class="sr-only">Attach</span></th>
                        </x-slot:head>

                        @foreach ($unapplied as $credit)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-slate-900 dark:text-white">{{ $credit->payment_no }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ app_date($credit->paid_on) }}</td>
                                @if ($fields->seesMoney)
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                        {{ money($credit->net_received_amount) }}
                                    </td>
                                @endif
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.invoices.payments.apply', [$invoice, $credit]) }}"
                                          class="inline-flex items-center gap-2">
                                        @csrf
                                        <input type="text" name="reason" required minlength="5" maxlength="255"
                                               placeholder="Why attach it"
                                               class="w-44 rounded-lg border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                                        <x-ui.button type="submit" size="sm" variant="secondary">Attach</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="The document">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Issued</dt>
                        <dd class="text-right text-slate-900 dark:text-white">
                            {{ $invoice->issued_at ? app_date($invoice->issued_at) : 'Not yet' }}
                            @if ($invoice->issuedBy)
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $invoice->issuedBy->name }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Sent</dt>
                        <dd class="text-right text-slate-900 dark:text-white">
                            {{ $invoice->sent_at ? app_date($invoice->sent_at) : 'Never' }}
                            @if ($invoice->sent_count > 0)
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $invoice->sent_count }} {{ \Illuminate\Support\Str::plural('time', $invoice->sent_count) }}
                                    @if (filled($invoice->last_sent_to)) · {{ $invoice->last_sent_to }} @endif
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Viewed by the client</dt>
                        <dd class="text-right text-slate-900 dark:text-white">
                            {{ $invoice->viewed_at ? app_date($invoice->viewed_at) : 'Not yet' }}
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Terms</dt>
                        <dd class="text-right text-slate-600 dark:text-slate-300">{{ $invoice->payment_terms_days }} days</dd>
                    </div>
                </dl>

                @if ($canChangeStatus && ! $isDraft)
                    <x-slot:footer>
                        <form method="POST" action="{{ route('admin.invoices.public-link.rotate', $invoice) }}"
                              class="flex items-center gap-2">
                            @csrf
                            <input type="text" name="reason" required minlength="5" maxlength="255"
                                   placeholder="Why regenerate the link"
                                   class="flex-1 rounded-lg border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                            <x-ui.button type="submit" size="sm" variant="ghost">New link</x-ui.button>
                        </form>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            A new link stops every copy already emailed from working.
                        </p>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            @if (filled($invoice->internal_notes))
                <x-ui.card title="Internal notes" subtitle="Never rendered to a client and never in the PDF.">
                    <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $invoice->internal_notes }}</p>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($canChangeStatus && ! $isCancelled)
        <x-ui.modal name="send-invoice" title="Record it as sent" icon="paper-airplane">
            <form method="POST" action="{{ route('admin.invoices.send', $invoice) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    @if ($isDraft)
                        This will issue the invoice first, assigning its number, and then record who it went to.
                    @else
                        Records another send against {{ $invoice->invoice_number }}. The original send date is kept.
                    @endif
                </p>
                <x-ui.form.input name="recipients[]" type="email" label="Recipient" required
                                 :value="$invoice->client?->email" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'send-invoice')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Record it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="cancel-invoice" title="Cancel this invoice" icon="x-mark">
            <form method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    It keeps its number and stays visible to the client, who may already be holding a copy.
                    Refund any receipts first — cancelling over the top of one would leave money belonging to a
                    document the business says never happened.
                </p>
                <x-ui.form.textarea name="reason" label="Reason" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'cancel-invoice')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Cancel the invoice</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
