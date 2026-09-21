{{--
    The public, signed invoice page — site.invoices.view (phase-13 §7.8, §8.12).

    It extends the one print layout rather than the marketing site shell, because what the recipient
    wants from this URL is the document: the same letterhead, the same totals block and the same
    arithmetic the accountant sees, with nothing around it. There is no navigation, no login prompt and
    no other invoice reachable from here.

    `internal_notes` is never rendered — here, in the panel, or on paper.
--}}

@extends('layouts.print')

@php
    $client = $invoice->client;

    $billTo = $client?->billing_same_as_address || blank($client?->billing_address)
        ? trim(implode(', ', array_filter([$client?->address, $client?->city, $client?->country])))
        : (string) $client?->billing_address;

    $hasTax = bccomp((string) $invoice->tax_amount, '0.00', 2) === 1;
    $hasDiscount = bccomp((string) $invoice->total_discount_amount, '0.00', 2) === 1;
    $hasRounding = bccomp((string) $invoice->round_off_amount, '0.00', 2) !== 0;
    $settled = bccomp((string) $invoice->balance_amount, '0.00', 2) <= 0;

    $qty = static fn ($value): string => app_quantity($value);
@endphp

@section('title', 'Invoice '.$invoice->invoice_number)

@section('document')
    <h2>Invoice</h2>
    <div class="mono strong">{{ $invoice->invoice_number }}</div>
    <div class="muted tiny" style="margin-top:6px;">
        Issued {{ app_date($invoice->issue_date) }}<br>
        Due {{ app_date($invoice->due_date) }}
        @if (filled($invoice->reference))
            <br>Your reference: {{ $invoice->reference }}
        @endif
    </div>
@endsection

@section('content')
    {{-- The one thing the recipient came for, stated before the detail. --}}
    <div class="panel" style="{{ $settled ? 'border-color:#bbf7d0;background:#f0fdf4;' : 'border-color:#fde68a;background:#fffbeb;' }}">
        <h4>{{ $settled ? 'Settled' : 'Amount due' }}</h4>
        <div style="font-size:22px;font-weight:700;font-variant-numeric:tabular-nums;">
            {{ money($settled ? $invoice->total_amount : $invoice->balance_amount) }}
        </div>
        <div class="muted tiny">
            {{ $settled
                ? 'Nothing is outstanding on this invoice. Thank you.'
                : 'Payable by '.app_date($invoice->due_date).'.' }}
        </div>
    </div>

    <div class="parties">
        <div>
            <h3 class="section">Billed to</h3>
            <div class="strong">{{ $client?->company_name ?: $client?->name }}</div>
            @if (filled($billTo))
                <div class="muted">{{ $billTo }}</div>
            @endif
            <div class="muted tiny">{{ collect([$client?->email, $client?->phone])->filter()->implode(' · ') }}</div>
            @if (filled($client?->tax_number))
                <div class="muted tiny">NTN {{ $client->tax_number }}</div>
            @endif
        </div>

        <div>
            @if ($invoice->project)
                <h3 class="section">Project</h3>
                <div class="strong">{{ $invoice->project->name }}</div>
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
                <th class="num" style="width:12%;">Qty</th>
                <th class="num" style="width:17%;">Rate</th>
                <th class="num" style="width:19%;">Amount</th>
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
                    </td>
                    <td class="num">{{ $qty($item->quantity) }}{{ filled($item->unit) ? ' '.$item->unit : '' }}</td>
                    <td class="num">{{ money($item->unit_price, false) }}</td>
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
                <td class="muted">{{ $invoice->tax_label ?: 'Tax' }}</td>
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
            <td>Balance due</td>
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

    @if (filled($invoice->bank_details))
        <div class="panel avoid-break">
            <h4>Bank details</h4>
            <div class="pre-line">{{ $invoice->bank_details }}</div>
        </div>
    @endif
@endsection

@section('footer')
    @if (filled($invoice->footer_note))
        <div>{{ $invoice->footer_note }}</div>
    @endif
    <div>
        Invoice {{ $invoice->invoice_number }} · issued {{ app_date($invoice->issue_date) }}.
        This link is private to you and stops working if we reissue it.
    </div>
@endsection
