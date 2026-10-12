@extends('layouts.admin')

@section('title', 'Mark attendance')

{{--
    The employee register for one day (AttendanceRegisterController) — the HR counterpart of the student
    register. One row per employee, a one-click mark (P / Lt / HD / A / L) with the letter as well as the
    colour, bulk buttons at the top, and one Save. "—" leaves an employee as they are. Every changed day is
    written as an HR-direct correction with the one reason given below. Rows in a month payroll has locked
    are shown, read-only, with the run that locked them.
--}}

@php
    $glyphs = ['present' => 'P', 'late' => 'Lt', 'half_day' => 'HD', 'absent' => 'A', 'on_leave' => 'L'];
    $colours = [
        'present' => 'bg-emerald-500 text-white',
        'late' => 'bg-amber-500 text-white',
        'half_day' => 'bg-violet-500 text-white',
        'absent' => 'bg-rose-500 text-white',
        'on_leave' => 'bg-sky-500 text-white',
    ];
    $editable = $sheet->filter(fn (array $entry): bool => $entry['locked'] === null);
    $locked = $sheet->filter(fn (array $entry): bool => $entry['locked'] !== null);

    $initial = $editable->mapWithKeys(function (array $entry): array {
        $id = $entry['employee']->getKey();
        $current = $entry['row']?->status?->value;

        return [$id => (string) old('marks.'.$id.'.status', array_key_exists((string) $current, \App\Services\Hr\AttendanceRegisterService::MARKS) ? $current : '')];
    });
@endphp

@section('header')
    <x-ui.page-header title="Mark attendance" :subtitle="app_date($date, 'l, j F Y')" icon="clipboard-document-check"
                      :back="route('admin.attendance.index', ['date' => $date->toDateString()])">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="calendar-days" :href="route('admin.attendance.index', array_filter(['date' => $date->toDateString(), 'department_id' => $departmentId]))">Register</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-ui.form.input type="date" name="date" label="Date" :value="$date->toDateString()" :max="today()->toDateString()" />
            </div>
            <div class="w-56">
                <x-ui.form.select name="department_id" label="Department" placeholder="Every department">
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </x-ui.form.select>
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.attendance.register')">Today</x-ui.button>
        </form>
    </x-ui.card>

    @if ($locked->isNotEmpty())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $locked->count() }} {{ \Illuminate\Support\Str::plural('employee', $locked->count()) }} cannot be changed on this day
                    </p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Payroll has already locked {{ app_date($date, 'F Y') }} for
                        {{ $locked->count() === 1 ? 'this employee' : 'these employees' }}
                        ({{ $locked->pluck('locked')->unique()->implode('; ') }}), so their attendance for the month
                        stays as it was paid. A paid month is corrected with a payroll correction run.
                    </p>
                </div>
            </div>
        </x-ui.card>
    @endif

    @if ($sheet->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="users" title="Nobody to mark"
                              message="Either no employees were employed on this date, or the filters exclude them all." />
        </x-ui.card>
    @else
        <form method="POST" action="{{ route('admin.attendance.register.store') }}" x-data="employeeRegister()">
            @csrf
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            @if ($departmentId)
                <input type="hidden" name="department_id" value="{{ $departmentId }}">
            @endif

            <x-ui.card class="mb-4" :padded="false">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 px-4 py-3 dark:border-slate-800">
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="secondary" size="sm" x-on:click="markAll('present')" :disabled="$editable->isEmpty()">All present</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="markAll('absent')" :disabled="$editable->isEmpty()">All absent</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="markBlank('present')" :disabled="$editable->isEmpty()">Unmarked → present</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" x-on:click="reset()">Reset</x-ui.button>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        @foreach ($glyphs as $value => $glyph)
                            <span class="flex items-center gap-1.5" title="{{ \App\Enums\AttendanceStatus::from($value)->label() }}">
                                <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $colours[$value] }}">{{ $glyph }}</span>
                                <span class="font-medium text-slate-700 dark:text-slate-200" x-text="counts['{{ $value }}'] ?? 0">0</span>
                            </span>
                        @endforeach
                        <span class="border-l border-slate-200 pl-3 text-slate-500 dark:border-slate-700 dark:text-slate-400">
                            <span class="font-semibold text-slate-800 dark:text-slate-100" x-text="counts[''] ?? 0">0</span> unmarked
                        </span>
                    </div>
                </div>

                <div class="divide-y divide-slate-200/70 dark:divide-slate-800">
                    @foreach ($sheet as $entry)
                        @php
                            $employee = $entry['employee'];
                            $row = $entry['row'];
                            $id = $employee->getKey();
                        @endphp

                        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <x-ui.avatar :name="$employee->name" size="sm" />

                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium text-slate-700 dark:text-slate-200">{{ $employee->name }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $employee->employee_code }}
                                    @if ($employee->department) · {{ $employee->department->name }} @endif
                                    · {{ $entry['day_type']->label() }}
                                    @if ($employee->is_attendance_exempt) · exempt @endif
                                </div>
                                @if ($row)
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                                        Now: <x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge>
                                        @if ($row->check_in_at)
                                            {{ app_time($row->check_in_at, 'H:i') }} – {{ $row->check_out_at ? app_time($row->check_out_at, 'H:i') : '…' }}
                                        @endif
                                        @if ($row->is_manual) · manual @endif
                                    </div>
                                @endif
                            </div>

                            @if ($entry['locked'] !== null)
                                <x-ui.badge color="slate" size="xs">Locked · {{ $entry['locked'] }}</x-ui.badge>
                            @else
                                <div class="flex overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700" role="radiogroup" aria-label="Mark for {{ $employee->name }}">
                                    <label class="cursor-pointer px-2.5 py-1.5 text-sm font-semibold transition"
                                           title="Leave as it is"
                                           :class="(marks[{{ $id }}] ?? '') === '' ? 'bg-slate-400 text-white' : 'bg-white text-slate-400 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-500 dark:hover:bg-slate-800'">
                                        <input type="radio" class="sr-only" name="marks[{{ $id }}][status]" value="" x-model="marks[{{ $id }}]">
                                        —
                                    </label>
                                    @foreach ($glyphs as $value => $glyph)
                                        <label class="cursor-pointer px-2.5 py-1.5 text-sm font-semibold transition"
                                               title="{{ \App\Enums\AttendanceStatus::from($value)->label() }}"
                                               :class="marks[{{ $id }}] === '{{ $value }}' ? '{{ $colours[$value] }}' : 'bg-white text-slate-500 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800'">
                                            <input type="radio" class="sr-only" name="marks[{{ $id }}][status]" value="{{ $value }}" x-model="marks[{{ $id }}]">
                                            {{ $glyph }}
                                        </label>
                                    @endforeach
                                </div>

                                <input type="time" name="marks[{{ $id }}][check_in]" aria-label="Check-in for {{ $employee->name }}"
                                       value="{{ old('marks.'.$id.'.check_in', $row?->check_in_at ? app_time($row->check_in_at, 'H:i') : '') }}"
                                       class="w-28 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-950/40 dark:text-white" title="Check-in (optional)">
                                <input type="time" name="marks[{{ $id }}][check_out]" aria-label="Check-out for {{ $employee->name }}"
                                       value="{{ old('marks.'.$id.'.check_out', $row?->check_out_at ? app_time($row->check_out_at, 'H:i') : '') }}"
                                       class="w-28 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-950/40 dark:text-white" title="Check-out (optional)">
                                <input type="text" name="marks[{{ $id }}][remarks]" aria-label="Note for {{ $employee->name }}"
                                       value="{{ old('marks.'.$id.'.remarks', $row?->remarks) }}" placeholder="Note" maxlength="255"
                                       class="w-36 rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-950/40 dark:text-white">
                            @endif
                        </div>

                        @foreach (['status', 'check_in', 'check_out', 'remarks'] as $field)
                            @error('marks.'.$id.'.'.$field)
                                <p class="px-4 pb-2 text-xs text-rose-600 dark:text-rose-400">{{ $employee->name }}: {{ $message }}</p>
                            @enderror
                        @endforeach
                    @endforeach
                </div>

                <div class="space-y-3 border-t border-slate-200/80 px-4 py-4 dark:border-slate-800">
                    <x-ui.form.textarea name="reason" label="Reason" rows="2" required
                                        :value="old('reason', 'Daily attendance register marked by HR.')"
                                        help="At least 10 characters. Saved on every changed day's correction trail." />

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            P present · Lt late · HD half day (half pay) · A absent (no pay) · L paid leave — record unpaid leave as A.
                            Only rows you change are saved.
                        </p>
                        <x-ui.button type="submit" variant="primary" icon="check" :disabled="$editable->isEmpty()">Save attendance</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </form>

        @push('scripts')
            <script nonce="{{ csp_nonce() }}">
                function employeeRegister() {
                    const initial = @json($initial);
                    const ids = Object.keys(initial);

                    return {
                        marks: { ...initial },
                        original: { ...initial },
                        counts: {},

                        init() {
                            this.recount();
                            this.$watch('marks', () => this.recount());
                        },

                        recount() {
                            const counts = { '': 0 };
                            Object.values(this.marks).forEach((value) => { counts[value] = (counts[value] || 0) + 1; });
                            this.counts = counts;
                        },

                        markAll(status) {
                            ids.forEach((id) => { this.marks[id] = status; });
                        },

                        markBlank(status) {
                            ids.forEach((id) => { if ((this.marks[id] ?? '') === '') { this.marks[id] = status; } });
                        },

                        reset() {
                            this.marks = { ...this.original };
                        },
                    };
                }
            </script>
        @endpush
    @endif
@endsection
