@extends('layouts.admin')

@section('title', 'Marking — '.$assignment->title)

@section('header')
    <x-ui.page-header :title="$assignment->title"
                      subtitle="Marking. A replaced attempt is hidden by default — showing it beside its successor is how somebody marks the wrong one."
                      icon="clipboard-document-check">
        <x-slot:actions>
            @if ($canRelease)
                <form method="POST" action="{{ route('admin.assignment-submissions.release', $assignment) }}" class="inline">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="eye">Release marks</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.assignment-submissions.mark-missed', $assignment) }}" class="inline">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" icon="x-circle">Record misses</x-ui.button>
                </form>
            @endif
            <x-ui.button variant="ghost" :href="route('admin.assignments.show', $assignment)">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card label="Expected" :value="app_number($stats->expected)" />
        <x-ui.stat-card label="Handed in" :value="app_number($stats->submitted)" color="sky" />
        <x-ui.stat-card label="Still to mark" :value="app_number($stats->ungraded())" color="amber" />
        <x-ui.stat-card label="Average" :value="$stats->averageMarks ? app_number($stats->averageMarks) : '—'" color="emerald" />
        <x-ui.stat-card label="Out of" :value="app_number($stats->totalMarks)" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.select name="status" label="Status" placeholder="Live attempts">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.assignment-submissions.index', $assignment)">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$submissions->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Student</th>
                <th class="px-4 py-3 text-left font-semibold">Handed in</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-right font-semibold">Mark</th>
                <th class="px-4 py-3 text-right font-semibold">Final</th>
                <th class="px-4 py-3 text-left font-semibold">Marked by</th>
            </x-slot:head>

            @foreach ($submissions as $submission)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.assignment-submissions.show', $submission) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">
                            {{ $submission->student?->name ?? '—' }}
                        </a>
                        <div class="text-xs text-slate-400">
                            {{ $submission->student?->student_code }} · attempt {{ app_number($submission->attempt_no) }}
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $submission->submitted_at ? app_datetime($submission->submitted_at) : '—' }}
                        @if ($submission->is_late && $submission->minutes_late)
                            <div class="text-xs text-amber-500">{{ app_number($submission->minutes_late) }} minutes late</div>
                        @endif
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$submission->status->color()" size="xs">{{ $submission->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right tabular-nums text-sm text-slate-600 dark:text-slate-300">
                        {{ $submission->obtained_marks !== null ? app_number($submission->obtained_marks) : '—' }}
                        @if (bccomp((string) $submission->penalty_marks, '0.00', 2) > 0)
                            <div class="text-xs text-amber-500">−{{ app_number($submission->penalty_marks) }} late</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-sm font-medium text-slate-700 dark:text-slate-200">
                        {{ $submission->final_marks !== null ? app_number($submission->final_marks) : '—' }}
                        @if ($submission->percentage !== null)
                            <div class="text-xs text-slate-400">{{ app_number($submission->percentage) }}%</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $submission->grader?->name ?? '—' }}
                        @if ($submission->marks_released_at)
                            <div class="text-xs text-emerald-500">released</div>
                        @elseif ($submission->status->isGraded())
                            <div class="text-xs text-slate-400">not released</div>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="clipboard-document-check" title="Nothing handed in yet"
                                  description="Submissions appear here as the class hands work in." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$submissions" label="submissions" />
    </x-ui.card>
@endsection
