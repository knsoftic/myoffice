@extends('layouts.admin')

@section('title', 'Attendance')

@section('header')
    <x-ui.page-header title="Attendance"
                      subtitle="Today's classes and the registers nobody has filled in. Nothing is ever marked automatically — who was in the room is a fact only a person has."
                      icon="clipboard-document-check">
        <x-slot:actions>
            @can('student_attendance.view_reports')
                <x-ui.button variant="ghost" icon="chart-bar" :href="route('admin.student-attendance.reports.daily')">Reports</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Classes today" :value="app_number($report['totals']['sessions'])" icon="calendar-days" color="slate" />
        <x-ui.stat-card label="Present" :value="app_number($report['totals']['present'])" icon="check" color="emerald" />
        <x-ui.stat-card label="Absent" :value="app_number($report['totals']['absent'])" icon="x-mark" color="rose" />
        <x-ui.stat-card label="Today's attendance" :value="$report['percentage'].'%'" icon="chart-bar" color="brand" />
    </div>

    @if ($unmarked->isNotEmpty())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $unmarked->count() }} {{ \Illuminate\Support\Str::plural('class', $unmarked->count()) }} finished without a register
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        An unmarked class cannot be counted in any attendance percentage, so it is missing
                        from every report until somebody takes it.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($unmarked->take(8) as $session)
                            <a href="{{ route('admin.student-attendance.mark', $session->id) }}"
                               class="rounded-lg bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200 transition hover:ring-brand-300 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                                {{ $session->batch_code }} · {{ app_date($session->session_date, 'd M') }}
                            </a>
                        @endforeach
                        @if ($unmarked->count() > 8)
                            <span class="px-2 py-1.5 text-xs text-slate-400">and {{ $unmarked->count() - 8 }} more</span>
                        @endif
                    </div>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="date" label="Date" type="date" :value="$date->toDateString()" />

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Anybody">
                @foreach ($teachers as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('teacher_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end">
                <x-ui.form.checkbox name="unmarked_only" label="Not marked only" :checked="request()->boolean('unmarked_only')" />
            </div>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-attendance.index')">Today</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="app_date($date)">
        @include('admin.attendance.reports._daily-table', ['report' => $report, 'linkTo' => 'mark'])
    </x-ui.card>
@endsection
