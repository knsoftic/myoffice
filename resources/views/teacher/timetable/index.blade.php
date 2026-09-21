@extends('layouts.panel')

@section('title', 'My timetable')

@section('header')
    <x-ui.page-header title="My timetable"
                      subtitle="Built from the actual classes, not the weekly pattern — a cancelled class says so rather than quietly disappearing."
                      icon="table-cells" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.input name="from" label="Week beginning" type="date" :value="$from->toDateString()" />
            <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
            <x-ui.button variant="ghost" :href="route('teacher.timetable.index', ['from' => $from->copy()->subWeek()->toDateString()])">Previous</x-ui.button>
            <x-ui.button variant="ghost" :href="route('teacher.timetable.index', ['from' => $from->copy()->addWeek()->toDateString()])">Next</x-ui.button>
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
                                <a href="{{ route('teacher.sessions.show', $session) }}"
                                   class="block rounded-lg border border-slate-200/70 bg-white p-2.5 text-sm transition hover:border-brand-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-600">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ app_time($session->startsAt()) }}</span>
                                        <x-ui.badge :color="$session->status->color()" size="xs">{{ $session->status->label() }}</x-ui.badge>
                                    </div>
                                    <div class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ $session->batch?->code }}</div>
                                    @if ($session->classroom)
                                        <div class="truncate text-xs text-slate-400">{{ $session->classroom->code }}</div>
                                    @endif
                                </a>
                            @empty
                                <p class="px-1 py-6 text-center text-xs text-slate-300 dark:text-slate-600">—</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </x-ui.card>

    <x-ui.card title="Your weekly pattern" class="mt-4"
               subtitle="The rules behind those classes — what runs every week, whatever happens to one particular Tuesday.">
        @php($anySlots = collect($entries)->flatten()->isNotEmpty())

        @if (! $anySlots)
            <x-ui.empty-state icon="table-cells" title="No weekly slots"
                              description="You are not on a timetable yet. Classes you cover for somebody else still appear on the week above." />
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($weekdays as $day)
                    @continue(! isset($entries[$day->value]))
                    <div>
                        <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $day->label() }}</h4>
                        <div class="space-y-2">
                            @foreach ($entries[$day->value] as $entry)
                                <div class="rounded-lg border border-slate-200/70 p-2.5 text-sm dark:border-slate-800">
                                    <div class="font-medium text-slate-700 dark:text-slate-200">
                                        {{ \Illuminate\Support\Carbon::parse($entry->start_time)->format('H:i') }}
                                        – {{ \Illuminate\Support\Carbon::parse($entry->end_time)->format('H:i') }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ $entry->batch?->code }}</div>
                                    @if ($entry->classroom)
                                        <div class="text-xs text-slate-400">{{ $entry->classroom->code }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>
@endsection
