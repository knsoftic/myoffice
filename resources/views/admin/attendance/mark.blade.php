@extends('layouts.admin')

@section('title', 'Attendance — '.($session->batch?->code ?? 'class'))

@section('header')
    <x-ui.page-header :title="'Register · '.($session->batch?->code ?? 'class')"
                      :subtitle="app_date($session->session_date).' · '.app_clock($session->start_time).'–'.app_clock($session->end_time).' · '.($session->course?->name ?? '')"
                      icon="clipboard-document-check"
                      :badge="$session->status->label()"
                      :badge-color="$session->status->color()"
                      :back="route('admin.class-sessions.show', $session)">
        <x-slot:actions>
            @can('student_attendance.view_reports')
                <x-ui.button variant="ghost" icon="chart-bar"
                             :href="route('admin.student-attendance.reports.monthly', ['batch_id' => $session->batch_id])">Monthly</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Expected" :value="app_number($roster->count())" icon="users" color="slate" />
        <x-ui.stat-card label="Teacher" :value="$session->teacher?->name ?? '—'" icon="presentation-chart-bar" color="brand" />
        <x-ui.stat-card label="Room" :value="$session->classroom?->code ?? '—'" icon="building-office-2" color="sky" />
        <x-ui.stat-card label="Register"
                        :value="$session->isAttendanceMarked() ? app_time($session->attendance_marked_at) : 'Not taken'"
                        icon="clock" :color="$session->isAttendanceMarked() ? 'emerald' : 'amber'" />
    </div>

    @include('admin.attendance._marking-form', [
        'action' => route('admin.student-attendance.store', $session),
    ])
@endsection
