@extends('layouts.panel')

@section('title', 'My batches')

@section('header')
    <x-ui.page-header title="My batches"
                      subtitle="Batches you teach, hold a timetable slot in, or have covered a class for."
                      icon="squares-2x2" />
@endsection

@section('content')
    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$batches->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Runs</th>
                <th class="px-4 py-3 text-left font-semibold">Students</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($batches as $batch)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('teacher.batches.show', $batch) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $batch->code }}</a>
                        <div class="text-xs text-slate-400">{{ $batch->name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $batch->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_date($batch->start_date) }}
                        <div class="text-xs text-slate-400">{{ $batch->end_date ? 'to '.app_date($batch->end_date) : 'open-ended' }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_number($batch->current_students) }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$batch->status->color()" size="xs">{{ $batch->status->label() }}</x-ui.badge></td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="squares-2x2" title="No batches yet"
                                  description="Once you are put on a batch or a timetable slot, it appears here." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$batches" label="batches" />
    </x-ui.card>
@endsection
