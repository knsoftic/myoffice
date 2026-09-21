@extends('layouts.admin')

@section('title', $batch->code)

@section('header')
    <x-ui.page-header :title="$batch->code"
                      :subtitle="$batch->name.' · '.($batch->course?->name ?? 'no course')"
                      icon="squares-2x2"
                      :badge="$batch->status->label()"
                      :badge-color="$batch->status->color()"
                      :back="route('admin.batches.index')">
        <x-slot:actions>
            @can('print', $batch)
                <x-ui.button variant="ghost" icon="printer" :href="route('admin.batches.print-roster', $batch)">Print roster</x-ui.button>
            @endcan
            @can('update', $batch)
                <x-ui.button variant="ghost" icon="pencil" :href="route('admin.batches.edit', $batch)">Edit</x-ui.button>
            @endcan
            @can('assign', $batch)
                <x-ui.button icon="user-plus" x-on:click="$dispatch('open-modal', 'enroll-student')">Enrol a student</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Seats taken" :value="app_number($capacity['active']).' / '.app_number($capacity['effective_capacity'])"
                        icon="users" :color="$capacity['is_full'] ? 'rose' : ($capacity['is_near'] ? 'amber' : 'emerald')" />
        <x-ui.stat-card label="Free" :value="app_number($capacity['free'])" icon="user-plus" color="sky" />
        <x-ui.stat-card label="Classes held" :value="app_number($batch->sessions_held_count)" icon="check" color="brand" />
        <x-ui.stat-card label="Syllabus" :value="app_number($batch->syllabus_completion_percentage).'%'" icon="chart-bar" color="violet" />
    </div>

    @if ($capacity['room_capacity'] !== null && $capacity['room_capacity'] < $capacity['capacity'])
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    This batch is set to {{ app_number($capacity['capacity']) }} seats but
                    {{ $batch->classroom?->label() }} seats {{ app_number($capacity['room_capacity']) }}.
                    The smaller number is the one that binds — otherwise somebody would be standing.
                </p>
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Roster">
                @include('admin.batches._roster-table')
            </x-ui.card>

            <x-ui.card title="Timetable">
                <x-slot:actions>
                    @can('timetable.create')
                        @if ($entries->isEmpty() && filled($batch->days))
                            <form method="POST" action="{{ route('admin.timetable.seed', $batch) }}">
                                @csrf
                                <x-ui.button type="submit" variant="ghost" size="sm" icon="sparkles">Build from the batch days</x-ui.button>
                            </form>
                        @endif
                    @endcan
                </x-slot:actions>

                <x-ui.table :is-empty="$entries->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Day</th>
                        <th class="px-4 py-3 text-left font-semibold">Hours</th>
                        <th class="px-4 py-3 text-left font-semibold">Teacher</th>
                        <th class="px-4 py-3 text-left font-semibold">Room</th>
                        <th class="px-4 py-3 text-left font-semibold">From</th>
                    </x-slot:head>

                    @foreach ($entries as $entry)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $entry->day_of_week->label() }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ \Illuminate\Support\Carbon::parse($entry->start_time)->format('H:i') }}
                                – {{ \Illuminate\Support\Carbon::parse($entry->end_time)->format('H:i') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $entry->teacher?->name ?? 'The batch teacher' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $entry->classroom?->code ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                {{ app_date($entry->effective_from) }}
                                <div class="text-xs text-slate-400">{{ $entry->effective_to ? 'to '.app_date($entry->effective_to) : 'until the batch ends' }}</div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="table-cells" title="No timetable yet"
                                          description="A batch opens for admission once it has a teacher and a timetable — students are otherwise being asked to commit to hours nobody has set." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Next classes">
                <x-ui.table :is-empty="$upcoming->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">When</th>
                        <th class="px-4 py-3 text-left font-semibold">Class</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($upcoming as $session)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.class-sessions.show', $session) }}"
                                   class="font-medium text-slate-700 hover:underline dark:text-slate-200">{{ app_date($session->session_date) }}</a>
                                <div class="text-xs text-slate-400">{{ app_time($session->startsAt()) }} – {{ app_time($session->endsAt()) }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $session->displayTitle() }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$session->status->color()" size="xs">{{ $session->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calendar-days" title="No classes scheduled"
                                          description="Classes are generated from the timetable, out to the horizon set in Settings." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Details">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-400">Teacher</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $batch->teacher?->name ?? 'Not assigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Room</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $batch->classroom?->label() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Mode</dt>
                        <dd><x-ui.badge :color="$batch->delivery_mode->color()" size="xs">{{ $batch->delivery_mode->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Runs</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ app_date($batch->start_date) }}
                            {{ $batch->end_date ? '– '.app_date($batch->end_date) : '(open-ended)' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Days</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ collect($batch->weekdays())->map(fn ($d) => $d->short())->implode(', ') ?: '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Branch</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $batch->branch?->name ?? 'Every branch' }}</dd>
                    </div>
                </dl>

                @can('update', $batch)
                    <form method="POST" action="{{ route('admin.batches.recount', $batch) }}" class="mt-4">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" size="sm" icon="arrow-path">Recount</x-ui.button>
                        <x-ui.form.help>
                            The student and class counts are caches. This recomputes them from the rows that
                            own the truth, which is the only way either is ever written.
                        </x-ui.form.help>
                    </form>
                @endcan
            </x-ui.card>

            @can('changeStatus', $batch)
                <x-ui.card title="Status">
                    <form method="POST" action="{{ route('admin.batches.status', $batch) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="status" label="Move to" required>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($batch->status->value === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.textarea name="reason" label="Reason" rows="2"
                                            help="Required for on hold and cancelled — the roster is told, so somebody has to say what to tell them." />

                        <x-ui.button type="submit" variant="secondary" size="sm" icon="arrow-path">Change status</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan
        </div>
    </div>

    @can('assign', $batch)
        <x-ui.modal name="enroll-student" title="Enrol a student" icon="user-plus">
            <form method="POST" action="{{ route('admin.batches.enrollments.store', $batch) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The seat is counted under a lock and the batch is recounted, so the last place cannot
                    be given to two people at once.
                </p>

                <x-ui.form.input name="student_id" label="Student id" type="number" required
                                 help="From the student record. The enrolment is refused unless they are registered or active." />

                <x-ui.form.input name="enrolled_on" label="Enrolled on" type="date" :value="now()->toDateString()" />

                <div class="rounded-lg border border-amber-200 p-3 dark:border-amber-500/30">
                    <x-ui.form.checkbox name="overbook" label="Go over capacity"
                                        description="Needs overbooking to be switched on for the institute, and a reason." />
                    <div class="mt-3">
                        <x-ui.form.input name="overbook_reason" label="Reason" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'enroll-student')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Enrol</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
@endsection
