@extends('layouts.print')

@php
    $backUrl = route('admin.student-attendance.reports.'.$report, request()->query());
    $backLabel = 'Back to the report';

    $titles = [
        'daily' => 'Daily attendance',
        'monthly' => 'Monthly attendance',
        'percentage' => 'Attendance by student',
        'batch' => 'Attendance by batch',
    ];
@endphp

@section('title', $titles[$report] ?? 'Attendance')

@section('document')
    <h2>{{ $titles[$report] ?? 'Attendance' }}</h2>
    <div class="muted tiny" style="margin-top:6px;">
        Printed {{ app_date($printedAt) }}<br>
        {{-- A printed sheet with no filter line is one nobody can reproduce six months later. --}}
        @if ($payload === null)
            no data
        @elseif ($report === 'monthly')
            {{ $payload['batch']->code }} · {{ app_date($payload['from'], 'F Y') }}
        @elseif ($report === 'daily')
            {{ app_date($payload['date']) }}
        @else
            {{ app_date($payload['from'] ?? $printedAt) }} – {{ app_date($payload['to'] ?? $printedAt) }}
        @endif
    </div>
@endsection

@section('content')
    @if ($payload === null)
        <p class="muted">Pick a batch first — a student-by-day matrix across every batch is not a report.</p>
    @elseif ($report === 'daily')
        <table class="doc">
            <thead>
                <tr>
                    <th style="width:70px;">Time</th>
                    <th>Batch</th>
                    <th>Teacher</th>
                    <th style="width:90px;">P/A/L/Lt</th>
                    <th style="width:50px;">%</th>
                    <th style="width:70px;">Register</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payload['rows'] as $row)
                    <tr>
                        <td class="mono tiny">{{ app_clock($row->start_time, 'H:i') }}</td>
                        <td>
                            <span class="strong">{{ $row->batch_code }}</span>
                            <div class="muted tiny">{{ $row->course_name }}</div>
                        </td>
                        <td class="tiny">{{ $row->teacher_name }}</td>
                        <td class="mono tiny">
                            {{ $row->present_count }}/{{ $row->absent_count }}/{{ $row->leave_count }}/{{ $row->late_count }}
                        </td>
                        <td class="mono tiny">{{ $row->percentage }}</td>
                        <td class="tiny">{{ $row->is_marked ? 'taken' : 'NOT TAKEN' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No classes on this date.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif ($report === 'monthly')
        <table class="doc">
            <thead>
                <tr>
                    <th style="width:40px;">Roll</th>
                    <th>Student</th>
                    @foreach ($payload['sessions'] as $session)
                        <th style="width:18px;">{{ app_date($session->session_date, 'd') }}</th>
                    @endforeach
                    <th style="width:45px;">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['rows'] as $row)
                    <tr>
                        <td class="mono tiny">{{ $row->roll_number }}</td>
                        <td class="tiny">{{ $row->student_name }}</td>
                        @foreach ($payload['sessions'] as $session)
                            @php($cell = $row->cells[(int) $session->id] ?? null)
                            <td class="mono tiny" style="text-align:center;">
                                {{ $cell === null || ! $cell['on_roster'] ? '—' : ($cell['status']?->glyph() ?? '·') }}
                            </td>
                        @endforeach
                        <td class="mono tiny">{{ $row->percentage }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="muted tiny" style="margin-top:8px;">
            P present · A absent · L leave · Lt late · — not enrolled that day · · not marked.
            Batch average {{ $payload['average'] }}%.
        </p>
    @elseif ($report === 'percentage')
        <table class="doc">
            <thead>
                <tr>
                    <th>Student</th>
                    <th style="width:80px;">Batch</th>
                    <th style="width:50px;">Classes</th>
                    <th style="width:90px;">P/A/L/Lt</th>
                    <th style="width:50px;">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['rows'] as $row)
                    <tr>
                        <td>
                            <span class="strong">{{ $row->student_name }}</span>
                            <div class="muted tiny">{{ $row->student_code }}</div>
                        </td>
                        <td class="tiny">{{ $row->batch_code }}</td>
                        <td class="mono tiny">{{ $row->sessions_expected_count }}</td>
                        <td class="mono tiny">
                            {{ $row->present_count }}/{{ $row->absent_count }}/{{ $row->leave_count }}/{{ $row->late_count }}
                        </td>
                        <td class="mono tiny">{{ $row->attendance_percentage }}{{ $row->below_minimum ? ' *' : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="muted tiny" style="margin-top:8px;">
            * below the institute's minimum of {{ $payload['minimum'] }}%. Average {{ $payload['average'] }}%.
        </p>
    @else
        <table class="doc">
            <thead>
                <tr>
                    <th>Batch</th>
                    <th style="width:90px;">Teacher</th>
                    <th style="width:45px;">Students</th>
                    <th style="width:80px;">Held/off</th>
                    <th style="width:50px;">Avg %</th>
                    <th style="width:45px;">Below</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['rows'] as $row)
                    <tr>
                        <td>
                            <span class="strong">{{ $row->code }}</span>
                            <div class="muted tiny">{{ $row->course_name }}</div>
                        </td>
                        <td class="tiny">{{ $row->teacher_name }}</td>
                        <td class="mono tiny">{{ $row->students }}</td>
                        <td class="mono tiny">{{ $row->sessions_held }}/{{ $row->sessions_cancelled }}</td>
                        <td class="mono tiny">{{ $row->average }}</td>
                        <td class="mono tiny">{{ $row->below_minimum }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
