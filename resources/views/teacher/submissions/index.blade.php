@extends('layouts.panel')

@section('title', 'Marking — '.$assignment->title)

@section('header')
    <x-ui.page-header :title="$assignment->title"
                      subtitle="Marking. A replaced attempt is hidden by default — showing it beside its successor is how somebody marks the wrong one."
                      icon="clipboard-document-check">
        <x-slot:actions>
            @if ($canGrade)
                <form method="POST" action="{{ route('teacher.submissions.release', $assignment) }}" class="inline">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="eye">Release marks</x-ui.button>
                </form>
            @endif
            <x-ui.button variant="ghost" :href="route('teacher.assignments.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Expected" :value="app_number($stats->expected)" />
        <x-ui.stat-card label="Handed in" :value="app_number($stats->submitted)" color="sky" />
        <x-ui.stat-card label="Still to mark" :value="app_number($stats->ungraded())" color="amber" />
        <x-ui.stat-card label="Average" :value="$stats->averageMarks ? app_number($stats->averageMarks) : '—'" color="emerald" />
    </div>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$submissions->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Handed in</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-right font-semibold">Final</th>
                <th class="px-4 py-3"></th>
            </x-slot:head>

            @foreach ($submissions as $submission)
                <tr>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $submission->student?->name ?? '—' }}</div>
                        <div class="text-xs text-slate-400">attempt {{ app_number($submission->attempt_no) }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $submission->submitted_at ? app_datetime($submission->submitted_at) : '—' }}
                        @if ($submission->is_late && $submission->minutes_late)
                            <div class="text-xs text-amber-500">{{ app_number($submission->minutes_late) }} minutes late</div>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$submission->status->color()" size="xs">{{ $submission->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-700 dark:text-slate-200">
                        {{ $submission->final_marks !== null ? app_number($submission->final_marks) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button variant="ghost" size="sm" icon="pencil"
                                     :href="route('teacher.submissions.show', $submission)">Open</x-ui.button>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="clipboard-document-check" title="Nothing handed in yet"
                                  description="Submissions appear here as your class hands work in." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$submissions" label="submissions" />
    </x-ui.card>
@endsection
