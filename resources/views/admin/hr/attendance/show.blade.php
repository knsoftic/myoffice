@extends('layouts.admin')

@section('title', 'Attendance — ' . $attendance->attendance_date->toDateString())

@php
    $user = auth()->user();
    $canCorrect = $user?->can('update', $attendance) === true;
@endphp

@section('header')
    <x-ui.page-header
        :title="$attendance->employee?->name . ' — ' . $attendance->attendance_date->format('j F Y')"
        :subtitle="$attendance->attendance_date->format('l') . ' · ' . $attendance->day_type->label()"
        icon="clock">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.attendance.index', ['date' => $attendance->attendance_date->toDateString()])">Back to the register</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($attendance->isLocked())
        <x-ui.card class="mb-4">
            <p class="text-sm text-rose-700 dark:text-rose-300">
                <strong class="font-semibold">Locked by payroll run {{ $attendance->lockedByRun?->run_number }}.</strong>
                Attendance behind a paid month does not change. If this day is wrong, the remedy is a
                correction run against the salary slip.
            </p>
        </x-ui.card>
    @elseif ($attendance->is_manual)
        <x-ui.card class="mb-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <strong class="font-semibold text-slate-900 dark:text-white">A correction decided this day.</strong>
                The nightly recomputation leaves it exactly as it is — otherwise somebody's decision would
                quietly evaporate overnight.
            </p>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="What happened">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="mt-1"><x-ui.badge :color="$attendance->status->color()" size="xs">{{ $attendance->status->label() }}</x-ui.badge></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Checked in</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->check_in_at?->format('H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Checked out</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->check_out_at?->format('H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Worked</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ intdiv($attendance->worked_minutes, 60) }}h {{ $attendance->worked_minutes % 60 }}m</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Late</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->late_minutes }}m</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Early leave</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->early_leave_minutes }}m</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Overtime</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->overtime_minutes }}m</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Payable share</dt>
                        <dd class="mt-1 text-sm tabular-nums font-semibold text-slate-900 dark:text-white">{{ app_number((float) $attendance->payable_factor, 4) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Lost pay</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->lossOfPayDays() }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Measured against" subtitle="The window this row snapshotted when it was created.">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Shift</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $attendance->workShift?->name ?? 'No shift' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Expected in</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->expected_in_at?->format('H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Expected out</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->expected_out_at?->format('H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Grace in / out</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->grace_in_minutes }}m / {{ $attendance->grace_out_minutes }}m</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Break</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->break_minutes }}m</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Expected minutes</dt>
                        <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $attendance->expected_minutes }}</dd>
                    </div>
                    @if ($attendance->holiday)
                        <div class="sm:col-span-3">
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Holiday</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                                {{ $attendance->holiday->title }} ({{ $attendance->holiday->is_paid ? 'paid' : 'unpaid' }})
                            </dd>
                        </div>
                    @endif
                    @if ($attendance->leave_request_id)
                        <div class="sm:col-span-3">
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Leave</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                                <a href="{{ route('admin.leaves.show', $attendance->leave_request_id) }}" class="hover:text-brand-700 dark:hover:text-brand-300">
                                    {{ $attendance->leaveType?->name ?? 'Leave' }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="Corrections" subtitle="Every manual change to this day, and why.">
                @if ($attendance->corrections->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nobody has changed this day.</p>
                @else
                    <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($attendance->corrections->sortByDesc('requested_at') as $correction)
                            <li class="py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $correction->correction_type->label() }}</span>
                                    <x-ui.badge :color="$correction->status->color()" size="xs">{{ $correction->status->label() }}</x-ui.badge>
                                </div>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $correction->reason }}</p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $correction->requester?->name ?? 'System' }} · {{ app_datetime($correction->requested_at) }}
                                    @if ($correction->review_comment) · {{ $correction->review_comment }} @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($canCorrect && ! $attendance->isLocked())
                <x-ui.card title="Correct this day" subtitle="The change and the reason are recorded together.">
                    <form method="POST" action="{{ route('admin.attendance.corrections.store', $attendance) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.select name="correction_type" label="What kind of correction" :options="$correctionTypes" selected="wrong_time" required />
                        <x-ui.form.input type="datetime-local" name="check_in_at" label="Check-in"
                            :value="$attendance->check_in_at?->format('Y-m-d\TH:i')" />
                        <x-ui.form.input type="datetime-local" name="check_out_at" label="Check-out"
                            :value="$attendance->check_out_at?->format('Y-m-d\TH:i')" />
                        <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="$attendance->status->value" />
                        <x-ui.form.input type="number" step="0.0001" min="0" max="1" name="payable_factor" label="Payable share"
                            :value="(string) $attendance->payable_factor" />
                        <x-ui.form.textarea name="reason" label="Reason" rows="3" required
                            help="At least ten characters — this is the only explanation anybody will have later." />
                        <x-ui.button type="submit" class="w-full">Apply correction</x-ui.button>
                    </form>
                </x-ui.card>

                @unless ($attendance->is_manual)
                    <x-ui.card title="Recompute">
                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                            Re-runs the resolution from this row's punches and the calendar.
                        </p>
                        <form method="POST" action="{{ route('admin.attendance.recompute', $attendance) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" class="w-full" icon="arrow-path">Recompute this day</x-ui.button>
                        </form>
                    </x-ui.card>
                @endunless
            @endif
        </div>
    </div>
@endsection
