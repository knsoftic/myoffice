@extends('layouts.admin')

@section('title', 'Meetings')

{{--
    The diary as a list — admin.meetings.index (phase-19-23 §7.6, §9.4).

    Upcoming by default and ascending, because a diary is read forwards. Switching to "past" flips
    both the filter and the order: a list of finished meetings read oldest-first would start in
    whichever month the institute opened.

    The scope is `MeetingService::visibleTo()`, the same one the calendar uses, so the two screens
    cannot disagree about which meetings exist.
--}}

@section('header')
    <x-ui.page-header title="Meetings"
                      subtitle="Everything in the diary. A meeting that will not happen is cancelled with a reason; one that moves leaves a successor pointing back at it."
                      icon="video-camera">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="calendar-days" :href="route('admin.meetings.calendar')">Calendar</x-ui.button>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.meetings.create')">New meeting</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Title or place" />

            <x-ui.form.select name="status" label="Status" placeholder="Any">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="when" label="When">
                <option value="upcoming" @selected(request('when') !== 'past')>Upcoming</option>
                <option value="past" @selected(request('when') === 'past')>Past</option>
            </x-ui.form.select>

            <label class="flex items-end gap-2 pb-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="mine" value="1" @checked(request()->boolean('mine'))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Only mine
            </label>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.meetings.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$meetings->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Meeting</th>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Where</th>
                <th class="px-4 py-3 text-left font-semibold">Organiser</th>
                <th class="px-4 py-3 text-right font-semibold">Accepted</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($meetings as $meeting)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.meetings.show', $meeting) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $meeting->title }}</a>
                        @if ($meeting->rescheduled_from_id)
                            <div class="text-xs text-amber-500">moved from an earlier date</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_datetime($meeting->scheduled_at) }}
                        <div class="text-xs text-slate-400">{{ app_number($meeting->duration_minutes) }} minutes</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $meeting->classroom?->name ?? $meeting->location ?? $meeting->delivery_mode->label() }}
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $meeting->organizer?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($meeting->accepted_count) }} / {{ app_number($meeting->participants_count) }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$meeting->status->color()" size="sm">{{ $meeting->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="video-camera" title="Nothing in the diary"
                                  description="Either there is nothing booked, or the filters above are narrower than you meant." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$meetings" label="meetings" />
    </x-ui.card>
@endsection
