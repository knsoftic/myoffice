@extends('layouts.print', [
    'watermark' => $structure['options']->watermark(),
    'backUrl' => route('admin.student-fees.index'),
    'backLabel' => 'Back to fees',
    'richFooter' => $structure['footer'],
])

@section('title', 'Fee structure '.$admission->admission_number)

@section('document')
    <div><strong>Fee structure</strong></div>
    <div>{{ $admission->admission_number }}</div>
    <div>Printed {{ app_datetime($structure['printed_at']) }}</div>
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Student</h3>
            <div class="strong">{{ $admission->student?->name }}</div>
            <div class="muted tiny">{{ $admission->student?->student_code }}</div>
            @if ($admission->student?->registration_number)
                <div class="muted tiny">Registration {{ $admission->student->registration_number }}</div>
            @endif
        </div>

        <div>
            <h3 class="section">Course</h3>
            <div class="strong">{{ $admission->course?->name ?? '—' }}</div>
            <div class="muted tiny">Admitted {{ $admission->admission_date ? app_date($admission->admission_date) : '—' }}</div>
        </div>
    </div>

    <table class="doc">
        <thead>
            <tr>
                <th>Fee #</th>
                <th>Head</th>
                <th>Due</th>
                <th class="num">Gross</th>
                <th class="num">Reduced</th>
                <th class="num">Net</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($structure['charges'] as $charge)
                <tr>
                    <td>{{ $charge->fee_number }}</td>
                    <td>{{ $charge->title ?? $charge->fee_type->label() }}</td>
                    <td>{{ $charge->due_date ? app_date($charge->due_date) : '—' }}</td>
                    <td class="num">{{ money($charge->gross_amount) }}</td>
                    <td class="num">
                        {{ money(\App\Support\Money::add($charge->discount_amount, $charge->scholarship_amount)) }}
                    </td>
                    <td class="num">{{ money($charge->net_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5">Total</th>
                <th class="num">{{ money($structure['total']) }}</th>
            </tr>
        </tfoot>
    </table>

    {{-- **The proof line, printed as a statement rather than assumed** (§6.7).

         `net_payable` is the spine's collectible denominator — a partner's commission is released in
         proportion to it — so a structure that does not add up to the figure the student agreed is not
         a rounding annoyance. The generator asserts this before it writes anything, so a slip that
         disagrees means somebody changed a figure afterwards, which is exactly what is worth seeing. --}}
    @if ($structure['balances'])
        <p class="muted tiny">
            The charges total {{ money($structure['total']) }}, which is the net payable agreed on this
            admission. They agree.
        </p>
    @else
        <p class="strong" style="color:#b91c1c;">
            The charges total {{ money($structure['total']) }} but this admission agreed
            {{ money($structure['net_payable']) }} — a difference of
            {{ money(\App\Support\Money::abs(\App\Support\Money::sub($structure['total'], $structure['net_payable']))) }}.
            One of the two has been changed since the structure was generated.
        </p>
    @endif

    @if ($structure['charges']->isEmpty())
        <p class="muted tiny">No charges have been raised against this admission yet.</p>
    @endif
@endsection
