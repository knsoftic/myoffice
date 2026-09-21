@extends('layouts.print')

@php
    $backUrl = route('admin.admissions.show', $admission);
    $backLabel = 'Back to the admission';
@endphp

@section('title', 'Admission '.$admission->admission_number)

@section('document')
    <h2>Admission</h2>
    <div class="mono strong">{{ $admission->admission_number }}</div>
    <div class="muted tiny" style="margin-top:6px;">
        Admitted {{ app_date($admission->admission_date) }}
        @if ($admission->registration_date)
            <br>Registered {{ app_date($admission->registration_date) }}
        @endif
        <br>Stage: {{ $admission->stage->label() }}
    </div>
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Student</h3>
            <div class="strong">{{ $admission->student?->name }}</div>
            @if (filled($admission->student?->father_name))
                <div class="muted">s/o {{ $admission->student->father_name }}</div>
            @endif
            <div class="muted tiny">
                {{ $admission->student?->student_code }}
                @if ($admission->student?->registration_number)
                    · {{ $admission->student->registration_number }}
                @endif
            </div>
            <div class="muted tiny">{{ $admission->student?->phone }}</div>
        </div>
        <div>
            <h3 class="section">Course</h3>
            <div class="strong">{{ $admission->course?->name }}</div>
            <div class="muted">{{ $admission->course?->code }}</div>
            <div class="muted tiny">{{ $admission->delivery_mode?->label() ?? '' }}</div>
            <div class="muted tiny">{{ $admission->branch?->name ?? 'Main branch' }}</div>
        </div>
    </div>

    @if ($canSeeMoney)
        <table class="lines">
            <thead>
                <tr>
                    <th>What was agreed</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Course fee</td><td class="num">{{ money($admission->course_fee) }}</td></tr>
                <tr><td>Admission fee</td><td class="num">{{ money($admission->admission_fee) }}</td></tr>
                <tr><td>Registration fee</td><td class="num">{{ money($admission->registration_fee) }}</td></tr>
                <tr><td class="strong">Total</td><td class="num strong">{{ money($admission->total_amount) }}</td></tr>
                <tr><td>Discount</td><td class="num">− {{ money($admission->discount_amount) }}</td></tr>
                <tr><td>Scholarship</td><td class="num">− {{ money($admission->scholarship_amount) }}</td></tr>
                <tr><td class="strong">Net payable</td><td class="num strong">{{ money($admission->net_payable) }}</td></tr>
            </tbody>
        </table>

        @if (filled($admission->discount_reason))
            <div class="panel avoid-break">
                <h4>Discount reason</h4>
                <div class="pre-line">{{ $admission->discount_reason }}</div>
            </div>
        @endif
    @endif

    @if (filled($admission->notes))
        <div class="panel avoid-break">
            <h4>Notes</h4>
            <div class="pre-line">{{ $admission->notes }}</div>
        </div>
    @endif
@endsection
