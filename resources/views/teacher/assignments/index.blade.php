@extends('layouts.panel')

@section('title', 'Assignments')

@section('header')
    <x-ui.page-header title="Assignments"
                      subtitle="Work you have set for your own batches."
                      icon="clipboard-document">
        <x-slot:actions>
            <x-ui.button icon="plus" :href="route('teacher.assignments.create')">Set work</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('teacher.assignments.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$assignments->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Assignment</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Deadline</th>
                <th class="px-4 py-3 text-right font-semibold">Handed in</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"></th>
            </x-slot:head>

            @foreach ($assignments as $assignment)
                <tr>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $assignment->title }}</div>
                        <div class="text-xs text-slate-400">out of {{ app_number($assignment->total_marks) }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $assignment->batch?->code }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_datetime($assignment->deadline_at) }}</td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($assignment->submitted_count) }} / {{ app_number($assignment->expected_count) }}
                        @if ($assignment->graded_count < $assignment->submitted_count)
                            <div class="text-xs text-amber-500">
                                {{ app_number($assignment->submitted_count - $assignment->graded_count) }} to mark
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$assignment->status->color()" size="xs">{{ $assignment->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button variant="ghost" size="sm" icon="clipboard-document-check"
                                     :href="route('teacher.submissions.index', $assignment)">Marking</x-ui.button>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="clipboard-document" title="No assignments yet"
                                  description="Set work for one of your batches. It stays a draft until you publish it." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$assignments" label="assignments" />
    </x-ui.card>
@endsection
