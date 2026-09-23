@extends('layouts.admin')

@section('title', 'Results')

@section('header')
    <x-ui.page-header title="Results"
                      subtitle="Every paper that has marks, needs them, or has had them published — and where each one has got to."
                      icon="trophy" />
@endsection

@section('content')
    {{--
        Listed by exam, not by result. A page of thirty thousand individual marks answers no question
        anybody has; "which sheets are still waiting to be checked" is the question, and its unit is
        the paper.
    --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Waiting for marks" :value="app_number($awaitingEntry)" color="amber" />
        <x-ui.stat-card label="Waiting to be checked" :value="app_number($awaitingCheck)" color="sky" />
        <x-ui.stat-card label="Checked, not yet out" :value="app_number($awaitingPublish)" color="indigo" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
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

            <x-ui.form.select name="status" label="Stage" placeholder="Any stage">
                <option value="conducted" @selected(request('status') === 'conducted')>Waiting for marks</option>
                <option value="marking" @selected(request('status') === 'marking')>Being marked</option>
                <option value="results_published" @selected(request('status') === 'results_published')>Published</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.results.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$exams->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Exam</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-right font-semibold">Entered</th>
                <th class="px-4 py-3 text-left font-semibold">Checked</th>
                <th class="px-4 py-3 text-left font-semibold">Published</th>
                <th class="px-4 py-3 text-right font-semibold"></th>
            </x-slot:head>

            @foreach ($exams as $exam)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.exam-results.sheet', $exam) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $exam->name }}</a>
                        <div class="text-xs text-slate-400">
                            {{ app_date($exam->scheduled_date) }} · out of {{ app_number($exam->total_marks, 2) }}
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $exam->batch?->code ?? '—' }}
                        <div class="text-xs text-slate-400">{{ $exam->course?->name }}</div>
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($exam->results_entered_count) }} / {{ app_number($exam->expected_count) }}
                        @if ($exam->results_entered_count > 0 && $exam->results_entered_count < $exam->expected_count)
                            <div class="text-xs text-amber-500">incomplete</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        @if ($exam->results_verified_at)
                            {{ app_date($exam->results_verified_at) }}
                            <div class="text-xs text-slate-400">{{ $exam->verifier?->name ?? 'somebody since removed' }}</div>
                        @else
                            <span class="text-xs text-slate-400">not yet</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        @if ($exam->results_published_at)
                            {{ app_date($exam->results_published_at) }}
                            <div class="text-xs text-slate-400">{{ $exam->publisher?->name ?? 'somebody since removed' }}</div>
                        @else
                            <x-ui.badge :color="$exam->status->color()" size="xs">{{ $exam->status->label() }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button variant="ghost" size="sm" :href="route('admin.exam-results.sheet', $exam)">
                            {{ $exam->acceptsResultEntry() ? 'Mark' : 'Open' }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="trophy" title="Nothing to mark"
                                  description="An exam appears here once it has been marked as conducted. Until then it lives on the exam calendar." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$exams" label="exams" />
    </x-ui.card>
@endsection
