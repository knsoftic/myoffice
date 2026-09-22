{{--
    The fee receipt (phase-18 §6.7.2) — **one document, both panels**.

    The staff copy and the student copy are the same file built from the same `ReceiptData`; the only
    difference is the `FeeSlipOptions` the route constructs. Two templates for one receipt is two
    letterheads nobody agreed to, and the second one is where the VOID watermark eventually gets
    forgotten.

    Three things §6.7.2 asks for that are easy to get wrong:

      · **Both dates, both labelled.** `paid_on` is when the money arrived and `recorded_at` is when
        somebody typed it in. A receipt showing one quietly becomes wrong the moment a back-dated
        payment is posted — and a back-dated receipt earns the commission rate effective on its VALUE
        date, so the gap between the two is economic, not cosmetic.

      · **The balance carries its own timestamp.** It is recomputed at print time, so a receipt
        reprinted in March must not appear to state January's balance (R-6). Storing a snapshot per
        receipt was the alternative and §109 forbids duplicating financial state.

      · **A voided receipt still prints**, with the watermark and the reversal number. Refusing to
        print it would leave whoever is holding the paper copy with no way to find out what happened.
--}}
@extends('layouts.print', [
    'watermark' => $receipt->watermark(),
    'backUrl' => $receipt->options->isStudentCopy
        ? route('student.fees.show', $receipt->payment->student_fee_id)
        : route('admin.fee-payments.show', $receipt->payment),
    'backLabel' => 'Back',
    'richFooter' => $receipt->footerNote,
])

@section('title', 'Receipt '.$receipt->payment->receipt_no)

@section('document')
    <div><strong>Fee receipt</strong></div>
    <div>{{ $receipt->payment->receipt_no }}</div>
    <div>Printed {{ app_datetime($receipt->printedAt) }}</div>
    @if ($receipt->options->isReprint)
        <div>Reprint #{{ app_number($receipt->options->printCount) }}</div>
    @endif
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Received from</h3>
            <div class="strong">{{ $receipt->payment->fee?->student?->name ?? '—' }}</div>
            <div class="muted tiny">{{ $receipt->payment->fee?->student?->student_code }}</div>
            @if ($receipt->payment->fee?->student?->registration_number)
                <div class="muted tiny">Registration {{ $receipt->payment->fee->student->registration_number }}</div>
            @endif
        </div>

        <div>
            <h3 class="section">Against</h3>
            <div class="strong">{{ $receipt->payment->fee?->fee_number }}</div>
            <div class="muted tiny">{{ $receipt->payment->fee?->fee_type->label() }}</div>
            @if ($receipt->payment->installment)
                <div class="muted tiny">Installment {{ app_number($receipt->payment->installment->installment_no) }}</div>
            @endif
        </div>
    </div>

    <table class="doc">
        <tbody>
            <tr>
                <td>Paid on <span class="muted tiny">(value date)</span></td>
                <td class="num">{{ app_date($receipt->payment->paid_on) }}</td>
            </tr>
            <tr>
                <td>Recorded <span class="muted tiny">(entered in the system)</span></td>
                <td class="num">{{ $receipt->payment->recorded_at ? app_datetime($receipt->payment->recorded_at) : '—' }}</td>
            </tr>
            <tr>
                <td>Method</td>
                <td class="num">
                    {{ $receipt->payment->payment_method->label() }}
                    @if ($receipt->payment->reference_no)
                        <span class="muted tiny">· {{ $receipt->payment->reference_no }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>Received by</td>
                <td class="num">{{ $receipt->payment->receivedBy?->name ?? $receipt->payment->received_by_name ?? '—' }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <th>Amount received</th>
                <th class="num">{{ money($receipt->payment->amount) }}</th>
            </tr>
            @if ($receipt->isRefunded())
                <tr>
                    <td>Refunded</td>
                    <td class="num">{{ money($receipt->payment->refunded_amount) }}</td>
                </tr>
                <tr>
                    <th>Net received</th>
                    <th class="num">{{ money($receipt->netReceived()) }}</th>
                </tr>
            @endif
        </tfoot>
    </table>

    @if ($receipt->isBackDated())
        <p class="muted tiny">
            This receipt is dated earlier than the day it was entered. Both dates are shown above; the
            value date is the one the fee and any commission are measured against.
        </p>
    @endif

    @if ($receipt->isVoid())
        <p class="strong" style="color:#b91c1c;">
            This receipt has been voided{{ $receipt->reversalNumber ? ' under reversal '.$receipt->reversalNumber : '' }}.
            It does not count towards the fee — the money it recorded never counted.
        </p>
    @endif

    <p class="muted tiny">
        Balance on {{ $receipt->payment->fee?->fee_number }} as at {{ app_datetime($receipt->printedAt) }}:
        <span class="strong">{{ money($receipt->balanceAtPrint) }}</span>.
    </p>

    {{-- Two copies per A4 (§6.7.2). The second is a plain restatement rather than a different
         document: a cashier tearing an office copy off the bottom should be holding the same words. --}}
    <div class="parties" style="margin-top:2rem;border-top:1px dashed #cbd5e1;padding-top:1rem;">
        <div>
            <div class="muted tiny">Student copy</div>
            <div class="strong">{{ $receipt->payment->receipt_no }} · {{ money($receipt->netReceived()) }}</div>
        </div>
        <div>
            <div class="muted tiny">Office copy</div>
            <div class="strong">{{ $receipt->payment->receipt_no }} · {{ money($receipt->netReceived()) }}</div>
        </div>
    </div>
@endsection
