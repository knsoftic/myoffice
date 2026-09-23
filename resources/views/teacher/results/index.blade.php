@extends('layouts.panel')

@section('title', 'Marking')

@section('header')
    <x-ui.page-header title="Marking"
                      subtitle="Papers your batches have sat. A published one stays on the list — marks you entered should not need asking the office for."
                      icon="trophy" />
@endsection

@section('content')
    @if ($awaitingMarks > 0)
        <div class="mb-4">
            <x-ui.stat-card label="Waiting for your marks" :value="app_number($awaitingMarks)" color="amber" />
        </div>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.select name="status" label="Stage" placeholder="Any stage">
                <option value="conducted" @selected(request('status') === 'conducted')>Waiting for marks</option>
                <option value="marking" @selected(request('status') === 'marking')>Being marked</option>
                <option value="results_published" @selected(request('status') === 'results_published')>Published</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2 sm:col-span-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('teacher.results.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="grid gap-3">
        @forelse ($exams as $exam)
            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-base font-medium text-slate-800 dark:text-slate-100">{{ $exam->name }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                            <span>{{ $exam->batch?->code }}</span>
                            <span>· {{ app_date($exam->scheduled_date) }}</span>
                            <span>· {{ app_number($exam->results_entered_count) }} of {{ app_number($exam->expected_count) }} entered</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-ui.badge :color="$exam->status->color()" size="xs">{{ $exam->status->label() }}</x-ui.badge>
                        <x-ui.button size="sm" :variant="$exam->acceptsResultEntry() ? 'primary' : 'ghost'"
                                     :href="route('teacher.results.sheet', $exam)">
                            {{ $exam->acceptsResultEntry() ? 'Mark' : 'Open' }}
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.empty-state icon="trophy" title="Nothing to mark"
                              description="A paper appears here once it has been marked as conducted." />
        @endforelse
    </div>

    <x-ui.pagination-summary :paginator="$exams" label="exams" />
@endsection
