@extends('layouts.admin')

@section('title', 'My attendance')

@section('header')
    <x-ui.page-header title="My attendance" :subtitle="app_date($month, 'F Y')" icon="clock">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.my.profile')">My profile</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Today">
                @if ($today === null)
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nothing recorded for today yet.</p>
                @else
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Checked in</dt>
                            <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $today->check_in_at ? app_time($today->check_in_at, 'H:i') : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Checked out</dt>
                            <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $today->check_out_at ? app_time($today->check_out_at, 'H:i') : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Worked</dt>
                            <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ intdiv($today->worked_minutes, 60) }}h {{ $today->worked_minutes % 60 }}m</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Status</dt>
                            <dd class="mt-1"><x-ui.badge :color="$today->status->color()" size="xs">{{ $today->status->label() }}</x-ui.badge></dd>
                        </div>
                    </dl>
                @endif

                @if ($selfPunchEnabled)
                    <div class="mt-4 flex gap-2">
                        <form method="POST" action="{{ route('admin.my.attendance.check-in') }}">
                            @csrf
                            <x-ui.button type="submit" icon="arrow-right-on-rectangle">Check in</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('admin.my.attendance.check-out') }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" icon="arrow-left-on-rectangle">Check out</x-ui.button>
                        </form>
                    </div>
                @else
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        Punching from this screen is switched off — your attendance is recorded by HR or by the kiosk.
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card :title="app_date($month, 'F Y')">
                <form method="GET" class="mb-4 flex items-end gap-3">
                    <div class="w-44">
                        <x-ui.form.input type="month" name="month" label="Month" :value="app_date($month, 'Y-m')" />
                    </div>
                    <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
                </form>

                <x-ui.table :is-empty="$rows->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">In / out</th>
                        <th class="px-4 py-3 text-right font-semibold">Worked</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                <span class="block">{{ app_date($row->attendance_date) }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($row->attendance_date, 'l') }}</span>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                @if ($row->check_in_at)
                                    {{ app_time($row->check_in_at, 'H:i') }} – {{ $row->check_out_at ? app_time($row->check_out_at, 'H:i') : '…' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                {{ intdiv($row->worked_minutes, 60) }}h {{ $row->worked_minutes % 60 }}m
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge>
                                @if ($row->requires_correction)
                                    <span class="block text-xs text-rose-600 dark:text-rose-400">needs a correction</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="clock" title="Nothing recorded this month" />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($summary)
                <x-ui.card title="This month so far">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Working days</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $summary->working_days, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Present</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $summary->present_days, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Late arrivals</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ $summary->late_count }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                            <dt class="text-slate-700 dark:text-slate-200">Payable days</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $summary->payable_days, 2) }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif

            <x-ui.card title="Ask for a correction" subtitle="Nothing changes until HR approves it.">
                <form method="POST" action="{{ route('admin.my.attendance.corrections.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input type="date" name="attendance_date" label="Which day" required :value="now()->toDateString()" />
                    <x-ui.form.select name="correction_type" label="What is wrong" :options="$correctionTypes" selected="missing_check_out" required />
                    <x-ui.form.input type="datetime-local" name="check_in_at" label="Check-in should be" />
                    <x-ui.form.input type="datetime-local" name="check_out_at" label="Check-out should be" />
                    <x-ui.form.textarea name="reason" label="Why" rows="3" required
                        help="At least ten characters — this is what HR reads when they decide." />
                    <x-ui.button type="submit" class="w-full">Send the request</x-ui.button>
                </form>
            </x-ui.card>

            @if ($corrections->isNotEmpty())
                <x-ui.card title="My recent requests">
                    <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($corrections as $correction)
                            <li class="flex items-start justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <span class="block text-sm text-slate-900 dark:text-white">{{ app_date($correction->attendance_date) }}</span>
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $correction->reason }}</span>
                                </div>
                                <x-ui.badge :color="$correction->status->color()" size="xs">{{ $correction->status->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
