@extends('layouts.panel')

@section('title', 'My attendance')

@section('header')
    <x-ui.page-header title="My attendance"
                      subtitle="Your own record. A class that was cancelled is not counted against you."
                      icon="clipboard-document-check" />
@endsection

@section('content')
    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="clipboard-document-check" title="Nothing to show yet"
                              description="Once you are seated in a batch and classes begin, your attendance appears here." />
        </x-ui.card>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($enrollments as $enrollment)
                <x-ui.card>
                    <div class="flex items-baseline justify-between gap-2">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->batch?->code }}</div>
                            <div class="truncate text-xs text-slate-400">{{ $enrollment->course?->name }}</div>
                        </div>
                        <span class="text-2xl font-semibold {{ $enrollment->below_minimum ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-100' }}">
                            {{ app_number($enrollment->attendance_percentage) }}<span class="text-sm">%</span>
                        </span>
                    </div>

                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full {{ $enrollment->below_minimum ? 'bg-rose-500' : 'bg-emerald-500' }}"
                             style="width: {{ min(100, (float) $enrollment->attendance_percentage) }}%"></div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span>{{ app_number($enrollment->sessions_expected_count) }} classes</span>
                        <span class="text-emerald-600 dark:text-emerald-400">{{ app_number($enrollment->present_count) }} present</span>
                        <span class="text-rose-600 dark:text-rose-400">{{ app_number($enrollment->absent_count) }} absent</span>
                        @if ((int) $enrollment->late_count > 0)
                            <span class="text-amber-600 dark:text-amber-400">{{ app_number($enrollment->late_count) }} late</span>
                        @endif
                    </div>

                    @if ($enrollment->below_minimum)
                        <p class="mt-3 text-xs text-rose-600 dark:text-rose-400">
                            Below the institute's minimum of {{ $minimum }}%. Speak to your teacher about
                            catching up.
                        </p>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card title="Class by class" :padded="false">
            <x-ui.table :is-empty="$rows->isEmpty()">
                <x-slot:head>
                    <th class="px-4 py-3 text-left font-semibold">When</th>
                    <th class="px-4 py-3 text-left font-semibold">Batch</th>
                    <th class="px-4 py-3 text-left font-semibold">You were</th>
                    <th class="px-4 py-3 text-left font-semibold">Note</th>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_date($row->session?->session_date) }}</div>
                            <div class="text-xs text-slate-400">{{ app_clock($row->session?->start_time) }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $row->batch?->code }}</td>
                        <td class="px-4 py-3">
                            <x-ui.badge :color="$row->status->color()">{{ $row->status->label() }}</x-ui.badge>
                            @if ($row->minutes_late)
                                <span class="ml-1 text-xs text-slate-400">{{ app_number($row->minutes_late) }} min</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ $row->remarks ?: '—' }}</td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="calendar-days" title="No classes recorded yet"
                                      description="Your attendance appears here once a register has been taken." />
                </x-slot:empty>
            </x-ui.table>

            <x-ui.pagination-summary :paginator="$rows" label="classes" />
        </x-ui.card>
    @endif
@endsection
