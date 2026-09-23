@extends('layouts.print')

@section('title', 'Result card — '.$student->name)

@section('document')
    <div class="text-sm">
        <div class="font-semibold">{{ $student->student_code }}</div>
        <div>{{ $enrollment->batch?->code }}</div>
        <div>{{ $consolidated ? 'Course record' : 'Single exam' }}</div>
    </div>
@endsection

@section('content')
    @include('partials.result-card', [
        'student' => $student,
        'enrollment' => $enrollment,
        'results' => $results,
        'summary' => $summary,
        'consolidated' => $consolidated,
        'showPosition' => $showPosition,
        'showAttendance' => $showAttendance,
    ])
@endsection
