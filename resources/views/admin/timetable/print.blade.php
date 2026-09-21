@extends('layouts.print')

@php
    $backUrl = route('admin.timetable.index', $view);
    $backLabel = 'Back to the timetable';
@endphp

@section('title', 'Timetable')

@section('document')
    <h2>Timetable</h2>
    <div class="mono strong">{{ \Illuminate\Support\Str::title($view) }}</div>
    <div class="muted tiny" style="margin-top:6px;">
        Printed {{ app_date($printedAt) }}<br>
        {{ $grid['entries']->count() }} {{ \Illuminate\Support\Str::plural('slot', $grid['entries']->count()) }}
    </div>
@endsection

@section('content')
    <table class="doc">
        <thead>
            <tr>
                <th style="width:90px;">Day</th>
                <th style="width:110px;">Hours</th>
                <th>Batch</th>
                <th>Teacher</th>
                <th style="width:80px;">Room</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($grid['entries']->sortBy([['day_of_week', 'asc'], ['start_time', 'asc']]) as $entry)
                <tr>
                    <td class="strong">{{ $entry->day_of_week->label() }}</td>
                    <td class="mono tiny">
                        {{ app_clock($entry->start_time) }}
                        – {{ app_clock($entry->end_time) }}
                    </td>
                    <td>
                        {{ $entry->batch?->code ?? '—' }}
                        <div class="muted tiny">{{ $entry->batch?->name }}</div>
                    </td>
                    <td>{{ $entry->teacher?->name ?? 'The batch teacher' }}</td>
                    <td class="tiny">{{ $entry->classroom?->code ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="muted">Nothing on the timetable for this selection.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
