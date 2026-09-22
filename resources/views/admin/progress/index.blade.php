@extends('layouts.admin')

@section('title', 'Progress')

@section('header')
    <x-ui.page-header title="Syllabus progress"
                      subtitle="How far each batch has got through its course. Dropping a topic raises the number — its weight leaves the denominator."
                      icon="chart-bar">
        <x-slot:actions>
            @can('student_progress.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray" :href="route('admin.student-progress.export', 'csv')">Export</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Batch code or name" />

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.student-progress.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$batches->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Teacher</th>
                <th class="px-4 py-3 text-left font-semibold">Students</th>
                <th class="px-4 py-3 text-left font-semibold">Syllabus</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($batches as $batch)
                @php($done = (float) $batch->syllabus_completion_percentage)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.student-progress.batch', $batch) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $batch->code }}</a>
                        <div class="text-xs text-slate-400">{{ $batch->name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $batch->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $batch->teacher?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($batch->current_students) }}</td>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ app_number($done) }}%</div>
                        <div class="mt-1 h-1.5 w-28 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full {{ $done >= 100 ? 'bg-emerald-500' : 'bg-violet-500' }}"
                                 style="width: {{ min(100, $done) }}%"></div>
                        </div>
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$batch->status->color()" size="xs">{{ $batch->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="chart-bar" title="No batches to track"
                                  description="Progress is tracked per batch. Open one, give it a timetable, and mark topics as the class covers them." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$batches" label="batches" />
    </x-ui.card>
@endsection
