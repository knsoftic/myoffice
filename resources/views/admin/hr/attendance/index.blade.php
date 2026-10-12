@extends('layouts.admin')

@section('title', 'Attendance register')

@php
    $user = auth()->user();
    // Which employees payroll has locked for this month — their days cannot change, so nothing offers to.
    $monthLocks = $canMark ? app(\App\Services\Hr\AttendanceRegisterService::class)->monthLocks($employees->modelKeys(), $date) : [];
@endphp

@section('header')
    <x-ui.page-header title="Attendance" :subtitle="app_date($date, 'l, j F Y')" icon="calendar-days">
        <x-slot:actions>
            @if ($canMark)
                <x-ui.button icon="clipboard-document-check" :href="route('admin.attendance.register', array_filter(['date' => $date->isFuture() ? null : $date->toDateString(), 'department_id' => request('department_id')]))">Mark attendance</x-ui.button>
                <x-ui.button variant="secondary" icon="plus" x-on:click="$dispatch('open-modal', 'record-employee-attendance')">Record one</x-ui.button>
            @endif
            <x-ui.button variant="secondary" :href="route('admin.attendance.monthly', ['month' => app_date($date, 'Y-m')])" icon="table-cells">Monthly grid</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.attendance-corrections.index')" icon="wrench-screwdriver">Corrections</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-end gap-3">
            <form method="GET" class="flex flex-1 flex-wrap items-end gap-3">
                <div class="w-44">
                    <x-ui.form.input type="date" name="date" label="Date" :value="$date->toDateString()" />
                </div>
                <div class="w-56">
                    <x-ui.form.select name="department_id" label="Department" placeholder="Every department">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((int) request('department_id') === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </x-ui.form.select>
                </div>
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
            </form>

            @if ($canMark)
                <form method="POST" action="{{ route('admin.attendance.close') }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <x-ui.button type="submit" variant="ghost" icon="check-circle">Close this day</x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>

    @if ($monthLocks !== [])
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Payroll has already locked {{ app_date($date, 'F Y') }} for {{ count($monthLocks) }} of these employees
                    ({{ implode(', ', array_unique($monthLocks)) }}), so their attendance for this month cannot change.
                    A paid month is corrected with a payroll correction run.
                </p>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :title="$employees->count() . ' employee(s)'"
               subtitle="Everybody who could work this day, including the ones with no row yet.">
        <x-ui.table :is-empty="$employees->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Day</th>
                <th class="px-4 py-3 text-left font-semibold">In / out</th>
                <th class="px-4 py-3 text-right font-semibold">Worked</th>
                <th class="px-4 py-3 text-right font-semibold">Late</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-right font-semibold">Payable</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($employees as $employee)
                @php $row = $rows->get($employee->id); @endphp
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.employees.show', $employee) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $employee->name }}</a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $employee->employee_code }}
                            @if ($employee->is_attendance_exempt) · exempt @endif
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                        {{ ($row?->day_type ?? $dayTypes[$employee->id])->label() }}
                    </td>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        @if ($row?->check_in_at)
                            {{ app_time($row->check_in_at, 'H:i') }} – {{ $row->check_out_at ? app_time($row->check_out_at, 'H:i') : '…' }}
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $row ? intdiv($row->worked_minutes, 60).'h '.($row->worked_minutes % 60).'m' : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums {{ ($row?->late_minutes ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-600 dark:text-slate-300' }}">
                        {{ $row?->late_minutes ? $row->late_minutes.'m' : '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if ($row)
                            <x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge>
                            @if ($row->requires_correction)
                                <span class="ml-1 text-xs text-rose-600 dark:text-rose-400">needs a correction</span>
                            @endif
                            @if ($row->is_manual)
                                <span class="ml-1 text-xs text-slate-500 dark:text-slate-400">manual</span>
                            @endif
                        @else
                            <span class="text-xs text-slate-400">no row yet</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $row ? app_number((float) $row->payable_factor, 2) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                        @if ($row)
                            <x-ui.button variant="ghost" size="sm" :href="route('admin.attendance.show', $row)">Open</x-ui.button>
                        @elseif ($canMark)
                            <form method="POST" action="{{ route('admin.attendance.punch') }}">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                <input type="hidden" name="direction" value="in">
                                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                <x-ui.button type="submit" variant="ghost" size="sm">Check in</x-ui.button>
                            </form>
                        @endif
                        @if ($canMark && ! $date->isFuture() && ! ($row?->isLocked() ?? false) && ! isset($monthLocks[$employee->id]))
                            <x-ui.button variant="ghost" size="sm" icon="plus"
                                x-on:click="$dispatch('record-attendance-for', { id: {{ $employee->id }} }); $dispatch('open-modal', 'record-employee-attendance')">Record</x-ui.button>
                        @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="calendar-days" title="Nobody to show"
                    description="Either no employees had joined by this date, or the filters exclude them all." />
            </x-slot:empty>
        </x-ui.table>
    </x-ui.card>

    {{--
        Manual entry (AttendanceManualEntryController): HR records an employee's day by hand. It is an HR-direct
        correction like `mark`, so the reason stays on the trail. Fields carry an `entry_` prefix so their old
        input and errors never mix with the filter and punch forms above, which use `date` and `employee_id`.
    --}}
    @if ($canMark)
        @php
            $entryDate = $date->isFuture() ? today()->toDateString() : $date->toDateString();
        @endphp

        <x-ui.modal name="record-employee-attendance" title="Record attendance" icon="calendar-days" size="lg"
                    subtitle="Enter an employee's day by hand. It is saved as a correction, with your reason."
                    :show="$errors->hasAny(['entry_employee_id', 'entry_date', 'entry_status', 'entry_payable_factor', 'entry_check_in', 'entry_check_out', 'entry_reason', 'entry_remarks'])">
            <form id="record-employee-attendance-form" method="POST" action="{{ route('admin.attendance.record') }}" class="space-y-4"
                  x-data="{
                      employee: @js((string) old('entry_employee_id', '')),
                      payable: @js((string) old('entry_payable_factor', '1')),
                  }"
                  x-on:record-attendance-for.window="employee = String($event.detail.id)">
                @csrf

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.form.select name="entry_employee_id" label="Employee" placeholder="Choose an employee" required x-model="employee">
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_code }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input name="entry_date" label="Date" type="date" :value="$entryDate"
                                     :max="today()->toDateString()" required />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.form.select name="entry_status" label="Status" required :selected="old('entry_status', 'present')"
                                      x-on:change="payable = ({ absent: '0', half_day: '0.5' })[$event.target.value] ?? '1'">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('entry_status', 'present') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input name="entry_payable_factor" label="Payable share of the day" type="number"
                                     step="0.25" min="0" max="1" required x-model="payable"
                                     help="1 is a full day's pay, 0.5 half, 0 none." />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.form.input name="entry_check_in" label="Check-in" type="time" optional />
                    <x-ui.form.input name="entry_check_out" label="Check-out" type="time" optional />
                </div>

                <x-ui.form.textarea name="entry_reason" label="Reason" rows="2" required
                                    placeholder="e.g. Biometric device was down; confirmed by the team lead."
                                    help="At least 10 characters. Kept on the attendance trail." />

                <x-ui.form.input name="entry_remarks" label="Remarks" optional />
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="hide(true)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="record-employee-attendance-form" icon="check">Save attendance</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@endsection
