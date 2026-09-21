@extends('layouts.admin')

@section('title', $teacher->name.' — workload')

@section('header')
    <x-ui.page-header :title="$teacher->name.' — workload'"
                      subtitle="Hours taught, classes lost, and whether the registers were actually filled in."
                      icon="chart-bar"
                      :back="route('admin.teachers.show', $teacher)" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="from" label="From" type="date" :value="$from->toDateString()" />
            <x-ui.form.input name="to" label="To" type="date" :value="$to->toDateString()" />
            <div class="flex items-end">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Hours" :value="app_number($report['hours'])" icon="clock" color="brand" />
        <x-ui.stat-card label="Classes held" :value="app_number($report['sessions_held'])" icon="check" color="emerald" />
        <x-ui.stat-card label="Still to come" :value="app_number($report['sessions_scheduled'])" icon="calendar-days" color="sky" />
        <x-ui.stat-card label="Cancelled" :value="app_number($report['sessions_cancelled'])" icon="x-mark" color="rose" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="Load">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-400">Batches</dt>
                    <dd class="font-medium text-slate-700 dark:text-slate-200">{{ app_number($report['batches']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Students across them</dt>
                    <dd class="font-medium text-slate-700 dark:text-slate-200">{{ app_number($report['students']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Window</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ app_date($report['from']) }} – {{ app_date($report['to']) }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Registers">
            @if ($report['marking_compliance'] === null)
                <x-ui.empty-state icon="clipboard-document-check" title="No classes held in this window"
                                  description="There is nothing to have marked yet." />
            @else
                <div class="space-y-3">
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-semibold text-slate-800 dark:text-slate-100">{{ app_number($report['marking_compliance']) }}%</span>
                        <span class="text-sm text-slate-500">
                            {{ app_number($report['registers_marked']) }} of {{ app_number($report['sessions_held']) }} marked
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, (float) $report['marking_compliance']) }}%"></div>
                    </div>
                    <p class="text-xs text-slate-400">
                        A class marked held with no register is one nobody can build an attendance report from.
                    </p>
                </div>
            @endif
        </x-ui.card>
    </div>
@endsection
