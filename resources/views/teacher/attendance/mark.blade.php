@extends('layouts.panel')

@section('title', 'Register — '.($session->batch?->code ?? 'class'))

@section('header')
    <x-ui.page-header :title="'Register · '.($session->batch?->code ?? 'class')"
                      :subtitle="app_date($session->session_date).' · '.app_clock($session->start_time).'–'.app_clock($session->end_time)"
                      icon="clipboard-document-check"
                      :badge="$session->status->label()"
                      :badge-color="$session->status->color()"
                      :back="route('teacher.attendance.index')" />
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Expected" :value="app_number($roster->count())" icon="users" color="slate" />
        <x-ui.stat-card label="Room" :value="$session->classroom?->code ?? '—'" icon="building-office-2" color="sky" />
        <x-ui.stat-card label="Register"
                        :value="$session->isAttendanceMarked() ? app_time($session->attendance_marked_at) : 'Not taken'"
                        icon="clock" :color="$session->isAttendanceMarked() ? 'emerald' : 'amber'" />
    </div>

    @include('admin.attendance._marking-form', [
        'action' => route('teacher.attendance.store', $session),
    ])
@endsection
