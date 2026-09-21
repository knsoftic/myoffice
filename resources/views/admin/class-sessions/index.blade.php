@extends('layouts.admin')

@section('title', 'Classes')

@section('header')
    <x-ui.page-header title="Classes"
                      subtitle="The dated classes the timetable produced. Cancelling one keeps it on the calendar, so the students are told rather than left guessing."
                      icon="calendar-days">
        <x-slot:actions>
            @can('timetable.create')
                <form method="POST" action="{{ route('admin.class-sessions.generate') }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" icon="sparkles">Generate</x-ui.button>
                </form>
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'add-class')">Extra class</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($unmarked > 0)
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ app_number($unmarked) }} {{ \Illuminate\Support\Str::plural('class', $unmarked) }} held without a register
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        Who was in the room is a fact only the person in the room has, so nothing is filled
                        in automatically. An unmarked class cannot be counted in any attendance report.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.form.input name="from" label="From" type="date" :value="$from->toDateString()" />
            <x-ui.form.input name="to" label="To" type="date" :value="$to->toDateString()" />

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $id => $code)
                    <option value="{{ $id }}" @selected((int) request('batch_id') === (int) $id)>{{ $code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Anybody">
                @foreach ($teachers as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('teacher_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.class-sessions.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$sessions->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Batch</th>
                <th class="px-4 py-3 text-left font-semibold">Teacher</th>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-left font-semibold">Register</th>
            </x-slot:head>

            @foreach ($sessions as $session)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.class-sessions.show', $session) }}"
                           class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ app_date($session->session_date) }}</a>
                        <div class="text-xs text-slate-400">{{ app_time($session->startsAt()) }} – {{ app_time($session->endsAt()) }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $session->batch?->code ?? '—' }}
                        <div class="text-xs text-slate-400">{{ $session->displayTitle() }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $session->teacher?->name ?? '—' }}
                        @if ($session->wasTaughtBySubstitute())
                            <x-ui.badge color="amber" size="xs" class="ml-1">Substitute</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->classroom?->code ?? '—' }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$session->status->color()">{{ $session->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-sm">
                        @if ($session->isAttendanceMarked())
                            <span class="text-emerald-600 dark:text-emerald-400">{{ app_date($session->attendance_marked_at) }}</span>
                        @elseif ($session->status->countsInAttendance())
                            <span class="text-amber-600 dark:text-amber-400">Not taken</span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="calendar-days" title="No classes in this window"
                                  description="Classes are generated from the timetable. Press Generate, or widen the dates." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$sessions" label="classes" />
    </x-ui.card>

    @can('timetable.create')
        <x-ui.modal name="add-class" title="Add an extra class" icon="plus" size="lg">
            <form method="POST" action="{{ route('admin.class-sessions.store') }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    A one-off — a make-up, a revision, a guest lecture. It belongs to no weekly rule, and
                    it is clash-checked like any other class.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.select name="batch_id" label="Batch" required placeholder="Pick a batch">
                        @foreach ($batches as $id => $code)
                            <option value="{{ $id }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.select name="teacher_id" label="Teacher" placeholder="The batch teacher">
                        @foreach ($teachers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input name="session_date" label="Date" type="date" required :value="now()->toDateString()" />
                    <x-ui.form.input name="title" label="What is it?" placeholder="Revision before the exam" />
                    <x-ui.form.input name="start_time" label="From" type="time" required />
                    <x-ui.form.input name="end_time" label="To" type="time" required />
                </div>

                <x-ui.form.input name="clash_override_reason" label="If the teacher or room is busy, why go ahead?" />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-class')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Schedule it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
@endsection
