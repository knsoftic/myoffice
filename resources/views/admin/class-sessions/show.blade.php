@extends('layouts.admin')

@section('title', $session->displayTitle())

@section('header')
    <x-ui.page-header :title="$session->displayTitle()"
                      :subtitle="($session->batch?->code ?? '—').' · '.app_date($session->session_date).' · '.app_time($session->startsAt()).'–'.app_time($session->endsAt())"
                      icon="calendar-days"
                      :badge="$session->status->label()"
                      :badge-color="$session->status->color()"
                      :back="route('admin.class-sessions.index')">
        <x-slot:actions>
            @can('changeStatus', $session)
                @if ($session->status->value === 'scheduled')
                    <form method="POST" action="{{ route('admin.class-sessions.held', $session) }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" icon="check">Mark held</x-ui.button>
                    </form>
                    <x-ui.button variant="ghost" icon="user" x-on:click="$dispatch('open-modal', 'substitute')">Substitute</x-ui.button>
                    <x-ui.button variant="ghost" icon="arrows-right-left" x-on:click="$dispatch('open-modal', 'reschedule')">Move</x-ui.button>
                @endif
                <x-ui.button variant="ghost" icon="x-mark" x-on:click="$dispatch('open-modal', 'cancel-class')">Cancel</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($session->status->value === 'cancelled')
        <x-ui.card class="mb-4 border-rose-200 dark:border-rose-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="x-mark" class="mt-0.5 h-5 w-5 shrink-0 text-rose-500" />
                <div>
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        Cancelled{{ $session->cancellation_reason ? ' — '.$session->cancellation_reason->label() : '' }}
                    </p>
                    <p class="mt-1 text-sm text-slate-500">{{ $session->cancellation_detail }}</p>
                </div>
            </div>
        </x-ui.card>
    @endif

    @if ($session->rescheduledTo)
        <x-ui.card class="mb-4 border-sky-200 dark:border-sky-500/30">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                This class was moved.
                <a href="{{ route('admin.class-sessions.show', $session->rescheduledTo) }}" class="font-medium hover:underline">
                    It now runs on {{ app_date($session->rescheduledTo->session_date) }}.
                </a>
            </p>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Who was expected" :subtitle="$roster->count().' on the roster that day'">
                <x-ui.table :is-empty="$roster->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Roll</th>
                        <th class="px-4 py-3 text-left font-semibold">Student</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($roster as $enrollment)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ $enrollment->roll_number ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $enrollment->student?->name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$enrollment->status->color()" size="xs">{{ $enrollment->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="users" title="Nobody was enrolled on that date"
                                          description="The roster is read as it stood on the day, so a student who joined later is not expected here." />
                    </x-slot:empty>
                </x-ui.table>

                <x-ui.form.help class="px-4 pb-3">
                    Taking the register is Phase 17's screen. This is who would be on it.
                </x-ui.form.help>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="This class">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-400">Batch</dt>
                        <dd>
                            <a href="{{ route('admin.batches.show', $session->batch_id) }}"
                               class="text-slate-700 hover:underline dark:text-slate-200">{{ $session->batch?->code ?? '—' }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Teacher</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $session->teacher?->name ?? '—' }}</dd>
                    </div>
                    @if ($session->wasTaughtBySubstitute())
                        <div>
                            <dt class="text-slate-400">Was supposed to be</dt>
                            <dd class="text-slate-700 dark:text-slate-200">
                                {{ $session->originalTeacher?->name ?? '—' }}
                                <div class="text-xs text-slate-400">
                                    Kept so "how many classes did this teacher miss" stays answerable.
                                </div>
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-slate-400">Room</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $session->classroom?->label() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Mode</dt>
                        <dd><x-ui.badge :color="$session->delivery_mode->color()" size="xs">{{ $session->delivery_mode->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">From the weekly slot</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            @if ($session->entry)
                                {{ $session->entry->day_of_week->label() }}
                                {{ \Illuminate\Support\Carbon::parse($session->entry->start_time)->format('H:i') }}
                            @else
                                <span class="text-slate-400">A one-off — no weekly rule behind it</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Register</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $session->isAttendanceMarked() ? app_datetime($session->attendance_marked_at) : 'Not taken' }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>
    </div>

    @can('changeStatus', $session)
        <x-ui.modal name="cancel-class" title="Cancel this class" icon="x-mark">
            <form method="POST" action="{{ route('admin.class-sessions.cancel', $session) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The class stays on the calendar, marked cancelled, and the roster is told. A class that
                    already has a register cannot be cancelled — it happened, and the register proves it.
                </p>

                <x-ui.form.select name="cancellation_reason" label="Why?" required>
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.textarea name="cancellation_detail" label="What should the students be told?" required rows="2" />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'cancel-class')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Cancel the class</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @if ($session->status->value === 'scheduled')
            <x-ui.modal name="reschedule" title="Move this class" icon="arrows-right-left">
                <form method="POST" action="{{ route('admin.class-sessions.reschedule', $session) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        A new class is created at the new time and the two are linked both ways, so the
                        original stays visible pointing at where it went.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.form.input name="session_date" label="New date" type="date" required
                                         :value="$session->session_date->toDateString()" />
                        <x-ui.form.input name="start_time" label="From" type="time" required
                                         :value="\Illuminate\Support\Carbon::parse($session->start_time)->format('H:i')" />
                        <x-ui.form.input name="end_time" label="To" type="time" required
                                         :value="\Illuminate\Support\Carbon::parse($session->end_time)->format('H:i')" />
                    </div>

                    <x-ui.form.select name="classroom_id" label="Room" placeholder="Keep the same room">
                        @foreach ($classrooms as $room)
                            <option value="{{ $room->id }}" @selected((int) $session->classroom_id === (int) $room->id)>{{ $room->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
                    <x-ui.form.input name="clash_override_reason" label="If the new slot is busy, why go ahead?" />

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reschedule')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Move it</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>

            <x-ui.modal name="substitute" title="Hand this class to somebody else" icon="user">
                <form method="POST" action="{{ route('admin.class-sessions.substitute', $session) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        The original teacher is kept on the record, so the missed-class report still has an
                        answer. The substitute's own hour is checked first.
                    </p>

                    <x-ui.form.select name="teacher_id" label="Who is taking it?" required placeholder="Pick a teacher">
                        @foreach ($teachers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.textarea name="reason" label="Why?" required rows="2" />

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'substitute')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Hand it over</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    @endcan
@endsection
