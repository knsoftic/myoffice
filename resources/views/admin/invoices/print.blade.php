{{--
    The printed invoice — admin.invoices.print (phase-13 §8.4).

    It reads **stored columns and the invoice's own snapshots only** (`tax_label`, `tax_rate`,
    `footer_note`, `bank_details`), never a live setting. Re-printing a year-old invoice therefore
    reproduces the document the client is holding rather than re-rendering it under this year's tax
    rate — which would be a different document wearing the same number.

    `internal_notes` is never rendered here, in the PDF, in the client panel or in the public view.
--}}

@extends('layouts.print')

@php
    $number = $invoice->invoice_number ?? $invoice->draft_reference;
    $isDraft = $invoice->status === \App\Enums\InvoiceStatus::Draft;
    $isCancelled = $invoice->status === \App\Enums\InvoiceStatus::Cancelled;
    $client = $invoice->client;

    $billTo = $client?->billing_same_as_address || blank($client?->billing_address)
        ? trim(implode(', ', array_filter([$client?->address, $client?->city, $client?->state, $client?->country])))
        : (string) $client?->billing_address;

    $hasTax = bccomp((string) $invoice->tax_amount, '0.00', 2) === 1;
    $hasDiscount = bccomp((string) $invoice->total_discount_amount, '0.00', 2) === 1;
    $hasRounding = bccomp((string) $invoice->round_off_amount, '0.00', 2) !== 0;
    $anyLineDiscount = $invoice->items->contains(
        fn ($item): bool => bccomp((string) $item->discount_amount, '0.00', 2) === 1
            || bccomp((string) $item->allocated_discount_amount, '0.00', 2) === 1,
    );

    $qty = static fn ($value): string => app_quantity($value);
@endphp

@section('title', 'Invoice '.$number)

@php
    // A draft has no number to print: watermarking it is what stops somebody treating a preview as a
    // document the business has issued.
    $watermark = $isCancelled ? 'CANCELLED' : ($isDraft ? 'DRAFT' : null);
    $backUrl = route('admin.invoices.show', $invoice);
    $backLabel = 'Back to the invoice';
@endphp

@section('document')
    <h2>Invoice</h2>
    <div class="mono strong">{{ $number }}</div>
    <div class="muted tiny" style="margin-top:6px;">
        Issued {{ app_date($invoice->issue_date) }}<br>
        Due {{ app_date($invoice->due_date) }}
        @if ($invoice->payment_terms_days > 0)
            ({{ $invoice->payment_terms_days }} days)
        @endif
        @if (filled($invoice->reference))
            <br>Your reference: {{ $invoice->reference }}
        @endif
    </div>
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Billed to</h3>
            <div class="strong">{{ $client?->company_name ?: $client?->name }}</div>
            @if ($client?->company_name && $client?->name)
                <div class="muted">{{ $client->name }}</div>
            @endif
            @if (filled($billTo))
                <div class="muted">{{ $billTo }}</div>
            @endif
            <div class="muted tiny">
                {{ collect([$client?->email, $client?->phone])->filter()->implode(' · ') }}
            </div>
            @if (filled($client?->tax_number))
                <div class="muted tiny">NTN {{ $client->tax_number }}</div>
            @endif
        </div>

        <div>
            @if ($invoice->project)
                <h3 class="section">Project</h3>
                <div class="strong">{{ $invoice->project->name }}</div>
                <div class="muted mono tiny">{{ $invoice->project->code }}</div>
            @endif
            @if (filled($invoice->title))
                <h3 class="section">For</h3>
                <div>{{ $invoice->title }}</div>
            @endif
        </div>
    </div>

    <table class="doc">
        <thead>
            <tr>
                <th style="width:6%;">#</th>
                <th>Description</th>
                <th class="num" style="width:11%;">Qty</th>
                <th class="num" style="width:15%;">Rate</th>
                @if ($anyLineDiscount)
                    <th class="num" style="width:13%;">Discount</th>
                @endif
                <th class="num" style="width:17%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $index => $item)
                <tr>
                    <td class="muted">{{ $index + 1 }}</td>
                    <td>
                        <span class="strong">{{ $item->description }}</span>
                        @if (filled($item->details))
                            <div class="muted tiny">{{ $item->details }}</div>
                        @endif
                        @if ($hasTax && ! $item->is_taxable)
                            <div class="muted tiny">exempt</div>
                        @endif
                    </td>
                    <td class="num">{{ $qty($item->quantity) }}{{ filled($item->unit) ? ' '.$item->unit : '' }}</td>
                    <td class="num">{{ money($item->unit_price, false) }}</td>
                    @if ($anyLineDiscount)
                        <td class="num">
                            {{ money(bcadd((string) $item->discount_amount, (string) $item->allocated_discount_amount, 2), false) }}
                        </td>
                    @endif
                    <td class="num strong">{{ money($item->line_total, false) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals avoid-break">
        <tr>
            <td class="muted">Subtotal</td>
            <td>{{ money($invoice->subtotal_amount) }}</td>
        </tr>

        @if ($hasDiscount)
            <tr>
                <td class="muted">Discount</td>
                <td>&minus;{{ money($invoice->total_discount_amount) }}</td>
            </tr>
        @endif

        @if ($hasTax)
            <tr>
                <td class="muted">Taxable</td>
                <td>{{ money($invoice->taxable_amount) }}</td>
            </tr>
            <tr>
                <td class="muted">
                    {{ $invoice->tax_label ?: 'Tax' }}
                    @ {{ app_quantity($invoice->tax_rate) }}%
                </td>
                <td>{{ money($invoice->tax_amount) }}</td>
            </tr>
        @endif

        @if ($hasRounding)
            <tr>
                <td class="muted">Rounding</td>
                <td>{{ money($invoice->round_off_amount) }}</td>
            </tr>
        @endif

        <tr class="grand">
            <td>Total</td>
            <td>{{ money($invoice->total_amount) }}</td>
        </tr>

        <tr>
            <td class="muted">Paid</td>
            <td>{{ money($invoice->paid_amount) }}</td>
        </tr>

        <tr class="balance">
            <td>{{ $invoice->isOverpaid() ? 'Credit' : 'Balance due' }}</td>
            <td>{{ money($invoice->balance_amount) }}</td>
        </tr>
    </table>

    <div style="clear:both;"></div>

    @if (filled($invoice->notes))
        <div class="panel avoid-break">
            <h4>Notes</h4>
            <div class="pre-line">{{ $invoice->notes }}</div>
        </div>
    @endif

    @if (filled($invoice->paymentMethod?->instructions ?? null))
        <div class="panel avoid-break">
            <h4>How to pay</h4>
            <div class="pre-line">{{ $invoice->paymentMethod->instructions }}</div>
        </div>
    @endif

    {{-- The invoice's own snapshot, taken when it was raised. The live setting may have moved since. --}}
    @if (filled($invoice->bank_details))
        <div class="panel avoid-break">
            <h4>Bank details</h4>
            <div class="pre-line">{{ $invoice->bank_details }}</div>
        </div>
    @endif

    @if ($isCancelled)
        <div class="panel avoid-break" style="border-color:#fecdd3;background:#fff1f2;">
            <h4 style="color:#9f1239;">Cancelled</h4>
            <div>
                {{ app_date($invoice->cancelled_at) }} — {{ $invoice->cancellation_reason }}.
                This document is void; it keeps its number so the series stays explainable.
            </div>
        </div>
    @endif
@endsection

@section('footer')
    @if (filled($invoice->footer_note))
        <div>{{ $invoice->footer_note }}</div>
    @endif
    <div>
        {{ $isDraft
            ? 'Draft preview — not yet issued and carrying no invoice number.'
            : 'Invoice '.$number.' · issued '.app_date($invoice->issue_date) }}
    </div>
@endsection
