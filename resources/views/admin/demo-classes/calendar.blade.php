@extends('layouts.admin')

@section('title', 'Demo calendar')

@section('header')
    <x-ui.page-header title="Demo calendar"
                      :subtitle="app_date($from) . ' – ' . app_date($to)"
                      icon="calendar-days"
                      :back="route('admin.demo-classes.index')">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="chevron-left"
                         :href="route('admin.demo-classes.calendar', ['from' => $from->copy()->subWeek()->toDateString()])">Previous</x-ui.button>
            <x-ui.button variant="ghost" icon="chevron-right"
                         :href="route('admin.demo-classes.calendar', ['from' => $from->copy()->addWeek()->toDateString()])">Next</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="overflow-x-auto">
        <div class="grid min-w-[56rem] grid-cols-7 gap-3">
            @foreach ($days as $day)
                @php($key = $day->toDateString())
                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                    <div class="mb-2 border-b border-slate-100 pb-2 dark:border-slate-800">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ app_date($day, 'D') }}</div>
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ app_date($day) }}</div>
                    </div>

                    @forelse ($demos[$key] ?? [] as $demo)
                        <div class="mb-2 rounded-lg bg-slate-50 p-2 text-xs dark:bg-slate-800/60">
                            <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_time($demo->startsAt()) }}</div>
                            <div class="truncate text-slate-600 dark:text-slate-300">{{ $demo->attendee_name }}</div>
                            <div class="truncate text-slate-400">{{ $demo->course?->name }}</div>
                            <x-ui.badge :color="$demo->status->color()" size="xs" class="mt-1">{{ $demo->status->label() }}</x-ui.badge>
                        </div>
                    @empty
                        <p class="py-4 text-center text-xs text-slate-300 dark:text-slate-600">Nothing booked</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
@endsection
