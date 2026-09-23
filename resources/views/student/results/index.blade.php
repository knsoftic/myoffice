@extends('layouts.panel')

@section('title', 'Results')

@section('header')
    <x-ui.page-header title="Results"
                      subtitle="Every mark that has been published. A result stays off this page until a second person has checked the sheet."
                      icon="trophy" />
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Exams counted" :value="app_number($summary['counted'])" color="slate" />
        <x-ui.stat-card label="Passed" :value="app_number($summary['passed'])" color="emerald" />
        <x-ui.stat-card label="Overall"
                        :value="$summary['percentage'] === null ? '—' : app_number($summary['percentage'], 2).'%'"
                        color="indigo" />
        <x-ui.stat-card label="Grade points"
                        :value="$summary['gradePoints'] === null ? '—' : app_number($summary['gradePoints'], 2)"
                        color="sky" />
    </div>

    @if ($enrollments->isNotEmpty())
        <x-ui.card class="mb-4">
            <x-ui.section-heading title="Printable cards"
                                  description="The same card the office prints, covering every published result for that course." />

            <div class="flex flex-wrap gap-2">
                @foreach ($enrollments as $enrollment)
                    <x-ui.button variant="secondary" size="sm" icon="printer"
                                 :href="route('student.results.card', $enrollment)">
                        {{ $enrollment->batch?->course?->name ?? $enrollment->batch?->code }}
                    </x-ui.button>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$results->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Exam</th>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-right font-semibold">Marks</th>
                <th class="px-4 py-3 text-left font-semibold">Grade</th>
                @if ($showPosition)
                    <th class="px-4 py-3 text-right font-semibold">Position</th>
                @endif
            </x-slot:head>

            @foreach ($results as $result)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('student.results.show', $result) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $result->exam?->name ?? '—' }}</a>
                        <div class="text-xs text-slate-400">{{ $result->exam?->course?->name }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ app_date($result->exam?->scheduled_date) }}</td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($result->obtained_marks === null)
                            <span class="text-slate-400">{{ $result->attendance_status->label() }}</span>
                        @else
                            {{ app_number($result->obtained_marks, 2) }} / {{ app_number($result->total_marks, 2) }}
                            <div class="text-xs text-slate-400">{{ app_number($result->percentage, 2) }}%</div>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if ($result->grade)
                            <x-ui.badge :color="$result->band?->color ?? 'slate'" size="xs">{{ $result->grade }}</x-ui.badge>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                    @if ($showPosition)
                        <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500 dark:text-slate-400">
                            {{ app_ordinal($result->position_in_batch) }}
                        </td>
                    @endif
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="trophy" title="Nothing published yet"
                                  description="A mark appears here once the sheet has been entered, checked by somebody other than the marker, and published." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$results" label="results" />
    </x-ui.card>
@endsection
