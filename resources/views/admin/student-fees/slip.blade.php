@extends('layouts.print', [
    'watermark' => $slip->options->watermark(),
    'backUrl' => route('admin.student-fees.show', $slip->charge),
    'backLabel' => 'Back to the charge',
    'richFooter' => $slip->footerNote,
])

@section('title', 'Fee slip '.$slip->charge->fee_number)

@section('document')
    <div><strong>Fee slip</strong></div>
    <div>{{ $slip->charge->fee_number }}</div>
    <div>Printed {{ app_datetime($slip->printedAt) }}</div>
    @if ($slip->options->isReprint)
        <div>Reprint #{{ app_number($slip->options->printCount) }}</div>
    @endif
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Student</h3>
            <div class="strong">{{ $slip->charge->student?->name }}</div>
            <div class="muted tiny">{{ $slip->charge->student?->student_code }}</div>
            @if ($slip->charge->student?->registration_number)
                <div class="muted tiny">Registration {{ $slip->charge->student->registration_number }}</div>
            @endif
        </div>

        <div>
            <h3 class="section">Course</h3>
            <div class="strong">{{ $slip->charge->course?->name ?? '—' }}</div>
            @if ($slip->charge->batch?->code)
                <div class="muted tiny">{{ $slip->charge->batch->code }}</div>
            @endif

            <h3 class="section">Head</h3>
            <div>{{ $slip->charge->fee_type->label() }}</div>
            <div class="muted tiny">
                Due {{ $slip->charge->due_date ? app_date($slip->charge->due_date) : '—' }}
            </div>
        </div>
    </div>

    <table class="doc">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $slip->charge->title ?? $slip->charge->fee_type->label() }}</td>
                <td class="num">{{ money($slip->charge->gross_amount) }}</td>
            </tr>

            {{-- Every reduction by name, with its reason. A student whose fee changed is entitled to
                 know why — it is the partner's earnings that stay private, not the student's own bill. --}}
            @foreach ($slip->discounts as $discount)
                <tr>
                    <td>{{ $discount->type->label() }} — {{ $discount->reason }}</td>
                    <td class="num">{{ $discount->isReduction() ? '−' : '+' }}{{ money($discount->magnitude()) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th>Net payable</th>
                <th class="num">{{ money($slip->charge->net_amount) }}</th>
            </tr>
            @if ((float) $slip->charge->paid_amount > 0)
                <tr>
                    <td>Received</td>
                    <td class="num">{{ money($slip->charge->paid_amount) }}</td>
                </tr>
            @endif
            @if ((float) $slip->charge->refunded_amount > 0)
                <tr>
                    <td>Refunded</td>
                    <td class="num">{{ money($slip->charge->refunded_amount) }}</td>
                </tr>
            @endif
            <tr>
                <th>{{ $slip->isInAdvance() ? 'In advance' : 'Balance' }}</th>
                <th class="num">{{ $slip->balanceCaption() }}</th>
            </tr>
        </tfoot>
    </table>

    {{-- **The balance carries its own timestamp** (R-6). It is recomputed at print time, so a slip
         reprinted months later must not appear to state the balance of the day it was issued. --}}
    <p class="muted tiny">Balance as at {{ app_datetime($slip->printedAt) }}.</p>

    @if ($slip->installments->isNotEmpty())
        <h3 class="section">Installment schedule</h3>
        <table class="doc">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Due</th>
                    <th class="num">Amount</th>
                    <th class="num">Paid</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slip->installments as $line)
                    <tr>
                        <td>{{ app_number($line->installment_no) }}</td>
                        <td>{{ app_date($line->due_date) }}</td>
                        <td class="num">{{ money($line->amount) }}</td>
                        <td class="num">{{ money($line->paid_amount) }}</td>
                        <td>{{ $line->status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($slip->payments->isNotEmpty())
        <h3 class="section">Payments received</h3>
        <table class="doc">
            <thead>
                <tr>
                    <th>Receipt</th>
                    <th>Paid on</th>
                    <th>Method</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slip->payments as $payment)
                    <tr>
                        <td>{{ $payment->receipt_no }}</td>
                        <td>{{ app_date($payment->paid_on) }}</td>
                        <td>{{ $payment->payment_method->label() }}</td>
                        <td class="num">{{ money($payment->net_received_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- §41 lists the collaborator among the slip's fields, and Q9 confirms the student's copy names
         them. The NAME is not the money: who referred somebody is not confidential, what they earned is. --}}
    @if ($slip->showsCollaborator())
        <p class="muted tiny">Referred by {{ $slip->collaboratorName }}.</p>
    @endif

    {{-- §6.7.1's three gates all held. When they do not, this block is ABSENT rather than zeroed: a row
         of zeroes is a claim that the referral earned nothing, which is a different statement from
         "you are not being shown this" and is usually false. --}}
    @if ($slip->showsCommission())
        <h3 class="section">Commission</h3>
        <table class="doc">
            <tbody>
                <tr>
                    <td>Collaborator</td>
                    <td class="num">{{ $slip->commission['collaborator'] }}</td>
                </tr>
                <tr>
                    <td>Commissionable amount ({{ $slip->commission['base'] }})</td>
                    <td class="num">{{ money($slip->commission['base_amount']) }}</td>
                </tr>
                <tr>
                    <td>Rate</td>
                    <td class="num">{{ app_number($slip->commission['rate'], 2) }}%</td>
                </tr>
                <tr>
                    <th>Commission</th>
                    <th class="num">{{ money($slip->commission['amount']) }}</th>
                </tr>
            </tbody>
        </table>
    @endif
@endsection
