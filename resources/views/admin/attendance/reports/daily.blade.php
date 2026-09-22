@extends('layouts.admin')

@section('title', 'Attendance — daily')

@section('header')
    <x-ui.page-header title="Attendance reports"
                      subtitle="One row per class held that day. Cancelled and rescheduled classes are in no report — they did not happen."
                      icon="chart-bar"
                      :back="route('admin.student-attendance.index')" />
@endsection

@section('content')
    <x-ui.card class="mb-4" :padded="false">
        @include('admin.attendance.reports._tabs', ['active' => 'daily'])

        <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="date" label="Date" type="date" :value="$date->toDateString()" />

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Anybody">
                @foreach ($teachers as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('teacher_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-attendance.reports.daily')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Classes" :value="app_number($report['totals']['sessions'])" icon="calendar-days" color="slate" />
        <x-ui.stat-card label="Without a register" :value="app_number($report['totals']['unmarked'])" icon="exclamation-triangle" color="amber" />
        <x-ui.stat-card label="Present" :value="app_number($report['totals']['present'])" icon="check" color="emerald" />
        <x-ui.stat-card label="Attendance" :value="$report['percentage'].'%'" icon="chart-bar" color="brand" />
    </div>

    <x-ui.card :title="app_date($date)" :padded="false">
        @include('admin.attendance.reports._daily-table', ['report' => $report, 'linkTo' => 'mark'])
    </x-ui.card>
@endsection
