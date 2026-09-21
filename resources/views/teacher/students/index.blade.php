@extends('layouts.panel')

@section('title', 'My students')

@section('header')
    <x-ui.page-header title="My students"
                      subtitle="Everybody currently in one of your batches, and nobody else."
                      icon="users" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or code" />

            <x-ui.form.select name="batch_id" label="Batch" placeholder="All your batches">
                @foreach ($batches as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('teacher.students.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$enrollments->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Roll</th>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Enrolled</th>
            </x-slot:head>

            @foreach ($enrollments as $enrollment)
                <tr>
                    <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->roll_number ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$enrollment->student?->name" size="sm" />
                            <div>
                                <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->student?->name ?? 'Unknown' }}</div>
                                <div class="text-xs text-slate-400">{{ $enrollment->student?->student_code }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        <a href="{{ route('teacher.batches.show', $enrollment->batch_id) }}" class="hover:underline">{{ $enrollment->batch?->code }}</a>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($enrollment->enrolled_on) }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="users" title="No students yet"
                                  description="Students appear here once they are seated in a batch you teach." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$enrollments" label="students" />
    </x-ui.card>
@endsection
