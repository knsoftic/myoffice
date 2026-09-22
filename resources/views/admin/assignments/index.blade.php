@extends('layouts.admin')

@section('title', 'Assignments')

@section('header')
    <x-ui.page-header title="Assignments"
                      subtitle="One assignment is for one batch. The same work for three cohorts is three rows, which is what gives each its own deadline and its own numbers."
                      icon="clipboard-document">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.assignments.create')">Set work</x-ui.button>
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
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Title" />

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

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.assignments.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$assignments->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Assignment</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Deadline</th>
                <th class="px-4 py-3 text-right font-semibold">Handed in</th>
                <th class="px-4 py-3 text-right font-semibold">Marked</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($assignments as $assignment)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.assignments.show', $assignment) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $assignment->title }}</a>
                        <div class="text-xs text-slate-400">
                            out of {{ app_number($assignment->total_marks) }}
                            @if ($assignment->passing_marks)
                                · pass at {{ app_number($assignment->passing_marks) }}
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $assignment->batch?->code ?? '—' }}
                        <div class="text-xs text-slate-400">{{ $assignment->course?->name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_datetime($assignment->deadline_at) }}
                        @if ($assignment->late_submission_allowed)
                            <div class="text-xs text-slate-400">late work accepted</div>
                        @else
                            <div class="text-xs text-slate-400">no late work</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($assignment->submitted_count) }} / {{ app_number($assignment->expected_count) }}
                        @if ($assignment->late_count > 0)
                            <div class="text-xs text-amber-500">{{ app_number($assignment->late_count) }} late</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($assignment->graded_count) }}
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$assignment->status->color()" size="xs">{{ $assignment->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="clipboard-document" title="No assignments yet"
                                  description="Work is set for one batch at a time, saved as a draft, and published when the class should see it." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$assignments" label="assignments" />
    </x-ui.card>
@endsection
