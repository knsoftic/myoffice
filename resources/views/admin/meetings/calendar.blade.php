@extends('layouts.admin')

@section('title', 'Meeting calendar')

{{--
    The calendar — admin.meetings.calendar (phase-19-23 §7.6, §6.17).

    The month grid is padded to whole weeks by `CalendarQuery`, not here: a grid whose first row
    started on a Thursday with three empty cells is a grid nobody can scan. The view renders whatever
    window it is given and groups by day.

    Four views, one query object. The day and week views are the same markup with a shorter window,
    which is why there is one file rather than four.
--}}

@php
    $byDay = $meetings->groupBy(fn ($meeting) => $meeting->scheduled_at?->toDateString());
    $cursor = $query->from->copy();
    $days = [];

    while ($cursor->lessThanOrEqualTo($query->to)) {
        $days[] = $cursor;
        $cursor = $cursor->addDay();
    }
@endphp

@section('header')
    <x-ui.page-header title="Meeting calendar"
                      :subtitle="app_date($query->from).' — '.app_date($query->to)"
                      icon="calendar-days">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="list-bullet" :href="route('admin.meetings.index')">List</x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.meetings.create')">New meeting</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-4">
            <x-ui.form.select name="view" label="View">
                <option value="month" @selected($query->view === 'month')>Month</option>
                <option value="week" @selected($query->view === 'week')>Week</option>
                <option value="day" @selected($query->view === 'day')>Day</option>
                <option value="agenda" @selected($query->view === 'agenda')>Next 30 days</option>
            </x-ui.form.select>

            <x-ui.form.input type="date" name="date" label="Around" :value="request('date', $query->from->toDateString())" />

            <label class="flex items-end gap-2 pb-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="mine" value="1" @checked($query->mineOnly)
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Only mine
            </label>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.meetings.calendar')">Today</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if ($meetings->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="calendar-days" title="Nothing in this window"
                              description="Move the date, widen the view, or book something." />
        </x-ui.card>
    @else
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-7">
            @foreach ($days as $day)
                @php($dayMeetings = $byDay[$day->toDateString()] ?? collect())

                @continue($query->view === 'agenda' && $dayMeetings->isEmpty())

                <div @class([
                    'rounded-lg border p-2 min-h-24',
                    'border-slate-200 dark:border-slate-700' => ! $day->isToday(),
                    'border-brand-400 bg-brand-50/40 dark:border-brand-600 dark:bg-brand-950/20' => $day->isToday(),
                ])>
                    <div class="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ $day->format('D j M') }}
                    </div>

                    <div class="space-y-1">
                        @foreach ($dayMeetings as $meeting)
                            <a href="{{ route('admin.meetings.show', $meeting) }}"
                               class="block rounded border-l-2 bg-slate-50 px-2 py-1 text-xs hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-700"
                               style="border-left-color: currentColor"
                               @class([
                                   'text-sky-600 dark:text-sky-400' => $meeting->status->value === 'scheduled',
                                   'text-emerald-600 dark:text-emerald-400' => $meeting->status->value === 'completed',
                                   'text-rose-500 line-through' => $meeting->status->value === 'cancelled',
                                   'text-amber-600 dark:text-amber-400' => in_array($meeting->status->value, ['postponed', 'missed'], true),
                               ])>
                                <span class="tabular-nums">{{ app_time($meeting->scheduled_at) }}</span>
                                <span class="text-slate-700 dark:text-slate-200">{{ $meeting->title }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
