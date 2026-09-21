@extends('layouts.admin')

@section('title', 'Demo classes')

@section('header')
    <x-ui.page-header title="Demo classes"
                      subtitle="A demo holds a teacher and a room, so it is booked like a class and clashes like one."
                      icon="video-camera">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="calendar-days" :href="route('admin.demo-classes.calendar')">Calendar</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Upcoming" :value="app_number($counts['upcoming'])" icon="calendar-days" color="sky" />
        <x-ui.stat-card label="Today" :value="app_number($counts['today'])" icon="clock" color="brand" />
        <x-ui.stat-card label="Attended" :value="app_number($counts['attended'])" icon="check" color="emerald" />
        <x-ui.stat-card label="Converted" :value="app_number($counts['converted'])" icon="user-plus" color="violet" />
    </div>

    @if ($unmarked->isNotEmpty())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $unmarked->count() }} {{ \Illuminate\Support\Str::plural('demo', $unmarked->count()) }} finished without being marked
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        Whether somebody turned up is a fact only the person in the room has, so nothing is
                        marked automatically. Mark them attended or missed below.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Attendee name or phone" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="from" label="From" type="date" :value="request('from')" />
            <x-ui.form.input name="to" label="To" type="date" :value="request('to')" />

            <div class="flex items-end gap-2 sm:col-span-2 xl:col-span-5">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.demo-classes.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$demos->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Attendee</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Mode</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Remarks</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($demos as $demo)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_date($demo->scheduled_on) }}</div>
                        <div class="text-xs text-slate-400">{{ app_time($demo->startsAt()) }} – {{ app_time($demo->endsAt()) }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $demo->attendee_name }}</div>
                        <div class="flex items-center gap-2">
                            <x-ui.badge :color="$demo->subject_type->color()" size="xs">{{ $demo->subject_type->label() }}</x-ui.badge>
                            @if (filled($demo->attendee_phone))
                                <a href="tel:{{ $demo->attendee_phone }}" class="text-xs text-slate-500 hover:underline">{{ $demo->attendee_phone }}</a>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $demo->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$demo->delivery_mode->color()" size="xs">{{ $demo->delivery_mode->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3"><x-ui.badge :color="$demo->status->color()">{{ $demo->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) $demo->attendance_remarks, 60) ?: '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        @can('print', $demo)
                            <x-ui.button variant="ghost" size="sm" icon="printer" :href="route('admin.demo-classes.slip', $demo)">Slip</x-ui.button>
                        @endcan
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="video-camera" title="No demo classes scheduled"
                                  description="A demo is booked against an inquiry, an applicant or a student." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$demos" label="demos" />
    </x-ui.card>
@endsection
