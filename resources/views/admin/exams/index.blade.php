@extends('layouts.admin')

@section('title', 'Exams')

@section('header')
    <x-ui.page-header title="Exams"
                      subtitle="One paper for one batch. An exam keeps its date, its room and its marks together, and is cancelled rather than deleted once anybody has sat it."
                      icon="document-chart-bar">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-down-tray"
                         :href="route('admin.exams.export', ['format' => 'csv'] + request()->query())">Export</x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.exams.create')">Set an exam</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($statuses as $status)
            <x-ui.stat-card :label="$status->label()"
                            :value="app_number($counts[$status->value] ?? 0)"
                            :color="$status->color()" />
        @endforeach
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Exam name" />

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected((int) request('course_id') === (int) $course->id)>{{ $course->name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}" @selected((int) request('batch_id') === (int) $batch->id)>{{ $batch->code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="exam_type" label="Kind" placeholder="Any kind">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(request('exam_type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.exams.index')">Clear</x-ui.button>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
                <input type="checkbox" name="upcoming" value="1" @checked(request()->boolean('upcoming'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Only exams still to come
            </label>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$exams->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Exam</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-right font-semibold">Out of</th>
                <th class="px-4 py-3 text-right font-semibold">Sat / passed</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($exams as $exam)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.exams.show', $exam) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $exam->name }}</a>
                        <div class="text-xs text-slate-400">
                            {{ $exam->exam_type->label() }}
                            @if ($exam->course)
                                · {{ $exam->course->name }}
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $exam->batch?->code ?? '—' }}
                        @if ($exam->classroom)
                            <div class="text-xs text-slate-400">{{ $exam->classroom->code }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_date($exam->scheduled_date) }}
                        @if ($exam->start_time)
                            <div class="text-xs text-slate-400">
                                {{ app_time($exam->start_time) }}@if ($exam->end_time) – {{ app_time($exam->end_time) }}@endif
                            </div>
                        @else
                            <div class="text-xs text-slate-400">no time set</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($exam->total_marks) }}
                        <div class="text-xs text-slate-400">pass at {{ app_number($exam->passing_marks) }}</div>
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($exam->results_entered_count > 0)
                            {{ app_number($exam->appeared_count) }} / {{ app_number($exam->passed_count) }}
                            @if ($exam->average_percentage !== null)
                                <div class="text-xs text-slate-400">avg {{ app_number($exam->average_percentage, 2) }}%</div>
                            @endif
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$exam->status->color()" size="xs">{{ $exam->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="document-chart-bar" title="No exams yet"
                                  description="An exam is set for one batch, saved as a draft, and scheduled when the class should be told." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$exams" label="exams" />
    </x-ui.card>
@endsection
