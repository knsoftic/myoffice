@extends('layouts.print')

@php
    $backUrl = route('admin.batches.show', $batch);
    $backLabel = 'Back to the batch';
@endphp

@section('title', $batch->code.' roster')

@section('document')
    <h2>Class roster</h2>
    <div class="mono strong">{{ $batch->code }}</div>
    <div class="muted tiny" style="margin-top:6px;">
        Printed {{ app_date($printedAt) }}<br>
        {{ $roster->count() }} {{ \Illuminate\Support\Str::plural('student', $roster->count()) }}
    </div>
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Batch</h3>
            <div class="strong">{{ $batch->name }}</div>
            <div class="muted">{{ $batch->course?->name ?? '—' }}</div>
            <div class="muted tiny">
                {{ app_date($batch->start_date) }}{{ $batch->end_date ? ' – '.app_date($batch->end_date) : '' }}
            </div>
        </div>
        <div>
            <h3 class="section">Teacher</h3>
            <div class="strong">{{ $batch->teacher?->name ?? 'Not assigned' }}</div>
            <div class="muted">{{ $batch->classroom?->label() ?? 'No room' }}</div>
            <div class="muted tiny">{{ $batch->delivery_mode->label() }}</div>
        </div>
    </div>

    <table class="doc">
        <thead>
            <tr>
                <th style="width:60px;">Roll</th>
                <th>Student</th>
                <th style="width:120px;">Code</th>
                <th style="width:110px;">Phone</th>
                <th style="width:90px;">Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($roster as $enrollment)
                <tr>
                    <td class="mono">{{ $enrollment->roll_number ?: '—' }}</td>
                    <td class="strong">{{ $enrollment->student?->name ?? 'Unknown' }}</td>
                    <td class="mono tiny">{{ $enrollment->student?->student_code }}</td>
                    <td class="tiny">{{ $enrollment->student?->phone }}</td>
                    <td></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="muted">Nobody is enrolled in this batch yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
