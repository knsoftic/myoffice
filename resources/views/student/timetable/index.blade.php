@extends('layouts.panel')

@section('title', 'My timetable')

@section('header')
    <x-ui.page-header title="My timetable"
                      subtitle="Your classes, week by week. A cancelled class says so here — so you know not to come in."
                      icon="table-cells" />
@endsection

@section('content')
    @if ($enrollments->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="table-cells" title="You are not in a batch yet"
                              description="Once you are seated in a batch, its classes appear here." />
        </x-ui.card>
    @else
        <x-ui.card class="mb-4">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <x-ui.form.input name="from" label="Week beginning" type="date" :value="$from->toDateString()" />
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('student.timetable.index', ['from' => $from->copy()->subWeek()->toDateString()])">Previous</x-ui.button>
                <x-ui.button variant="ghost" :href="route('student.timetable.index', ['from' => $from->copy()->addWeek()->toDateString()])">Next</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card :title="app_date($from).' – '.app_date($to)" :padded="false">
            <div class="overflow-x-auto">
                <div class="grid min-w-[900px] grid-cols-7">
                    @foreach ($days as $day)
                        @php($key = $day->toDateString())
                        <div class="border-r border-slate-200/70 last:border-r-0 dark:border-slate-800">
                            <div class="border-b border-slate-200/70 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900/60">
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $day->format('D') }}</div>
                                <div class="text-xs text-slate-400">{{ $day->format('d M') }}</div>
                            </div>
                            <div class="space-y-2 p-2">
                                @forelse ($sessions[$key] ?? [] as $session)
                                    <div class="rounded-lg border border-slate-200/70 bg-white p-2.5 text-sm dark:border-slate-800 dark:bg-slate-900
                                                {{ $session->status->value === 'cancelled' ? 'opacity-60' : '' }}">
                                        <div class="flex items-start justify-between gap-2">
                                            <span class="font-medium text-slate-700 dark:text-slate-200">{{ app_time($session->startsAt()) }}</span>
                                            <x-ui.badge :color="$session->status->color()" size="xs">{{ $session->status->label() }}</x-ui.badge>
                                        </div>
                                        <div class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ $session->course?->name ?? $session->batch?->code }}</div>
                                        <div class="truncate text-xs text-slate-400">{{ $session->teacher?->name }}</div>
                                        @if ($session->classroom)
                                            <div class="truncate text-xs text-slate-400">{{ $session->classroom->name }}</div>
                                        @endif
                                        @if ($session->status->value === 'cancelled' && filled($session->cancellation_detail))
                                            <p class="mt-1 text-xs text-rose-500">{{ $session->cancellation_detail }}</p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="px-1 py-6 text-center text-xs text-slate-300 dark:text-slate-600">—</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Your batches" class="mt-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($enrollments as $enrollment)
                    <a href="{{ route('student.batches.show', $enrollment) }}"
                       class="rounded-lg border border-slate-200/70 p-3 transition hover:border-brand-300 dark:border-slate-800 dark:hover:border-brand-600">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->batch?->code }}</div>
                        <div class="text-xs text-slate-400">{{ $enrollment->batch?->name }}</div>
                        <div class="mt-1 text-xs text-slate-500">Roll {{ $enrollment->roll_number ?: '—' }}</div>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    @endif
@endsection
