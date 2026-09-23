@extends('layouts.print')

@section('title', 'Result card — '.$student->name)

@section('document')
    <div class="text-sm">
        <div class="font-semibold">{{ $student->student_code }}</div>
        <div>{{ $enrollment->batch?->code }}</div>
    </div>
@endsection

@section('content')
    {{-- The office's template, unchanged. A student holding a card that differs from the office's copy
         is a support ticket at best, so both callers render the same partial. --}}
    @include('partials.result-card', [
        'student' => $student,
        'enrollment' => $enrollment,
        'results' => $results,
        'summary' => $summary,
        'consolidated' => true,
        'showPosition' => $showPosition,
        'showAttendance' => $showAttendance,
    ])
@endsection
