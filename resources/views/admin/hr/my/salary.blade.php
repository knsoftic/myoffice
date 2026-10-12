@extends('layouts.admin')

@section('title', 'My salary & deductions')

{{--
    "My Salary & Deductions" (SalaryController, MySalaryService) — the employee's own finalized payroll by
    day, month or year. Figures appear only once HR has finalized (locked) the month's payroll; before that
    the month shows its status alone. Every deduction row opens to its calculation.
--}}

@php
    $tabs = ['day' => 'Daily', 'month' => 'Monthly', 'year' => 'Yearly'];
    $kindColours = ['late' => 'amber', 'absence' => 'rose', 'unpaid_leave' => 'violet', 'other' => 'slate', 'refund' => 'emerald'];
@endphp

@section('header')
    <x-ui.page-header title="My salary & deductions"
                      :subtitle="$employee->name.' · '.$employee->employee_code"
                      icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="document-text" :href="route('admin.my.payslips.index')">Salary slips</x-ui.button>
            <x-ui.button variant="ghost" icon="receipt-percent" :href="route('admin.my.deductions.index')">Deduction notices</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{-- View switcher and its filter --}}
    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <nav class="inline-flex overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700" aria-label="View">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('admin.my.salary.index', ['view' => $key]) }}"
                       @if ($view === $key) aria-current="page" @endif
                       @class([
                           'px-4 py-2 text-sm font-semibold transition',
                           'bg-brand-600 text-white' => $view === $key,
                           'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800' => $view !== $key,
                       ])>{{ $label }}</a>
                @endforeach
            </nav>

            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="view" value="{{ $view }}">
                @if ($view === 'day')
                    <div class="w-44">
                        <x-ui.form.input type="date" name="date" label="Date" :value="$date->toDateString()" :max="today()->toDateString()" />
                    </div>
                @elseif ($view === 'year')
                    <div class="w-36">
                        <x-ui.form.select name="year" label="Year">
                            @foreach ($years as $option)
                                <option value="{{ $option }}" @selected($option === $year['year'])>{{ $option }}</option>
                            @endforeach
                        </x-ui.form.select>
                    </div>
                @else
                    <div class="w-44">
                        <x-ui.form.input type="month" name="month" label="Month" :value="$monthDate->format('Y-m')" />
                    </div>
                @endif
                <x-ui.button type="submit" variant="secondary" icon="funnel">Show</x-ui.button>
            </form>
        </div>
    </x-ui.card>

    {{-- ===================================================================== MONTHLY --}}
    @if ($view === 'month')
        @php($m = $month)

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $monthDate->format('F Y') }} payroll</h2>
            <x-ui.badge :color="$m['status']['color']">{{ $m['status']['label'] }}</x-ui.badge>
            @if ($m['slip'])
                <a href="{{ route('admin.my.payslips.show', $m['slip']) }}" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">
                    Slip {{ $m['slip']->slip_number }}
                </a>
                @if ($m['slip']->paid_at)
                    <span class="text-sm text-slate-500 dark:text-slate-400">· paid {{ app_date($m['slip']->paid_at) }}</span>
                @endif
            @endif
        </div>

        @if (! $m['slip'])
            <x-ui.card>
                <x-ui.empty-state icon="banknotes"
                    :title="$m['status']['key'] === 'generated' ? 'Payroll is being prepared' : 'No finalized payroll for this month'"
                    :message="$m['status']['key'] === 'generated'
                        ? 'HR has generated '.$monthDate->format('F Y').' payroll. Your salary and deductions appear here once it is finalized.'
                        : 'Payroll for '.$monthDate->format('F Y').' has not been finalized yet. Late arrivals and absences already noted for the month are under Deduction notices.'" />
            </x-ui.card>
        @else
            <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.stat-card label="Basic salary" :value="money($m['summary']['basic'])" icon="banknotes" color="slate" />
                <x-ui.stat-card label="Gross salary" :value="money($m['summary']['gross'])" icon="arrow-trending-up" color="brand" />
                <x-ui.stat-card label="Total deductions" :value="money($m['summary']['deductions'])" icon="receipt-percent" color="rose" />
                <x-ui.stat-card label="Net payable" :value="money($m['summary']['net'])" icon="check-circle" color="emerald" />
            </div>

            @if ($m['corrections']->isNotEmpty())
                <x-ui.card class="mb-4 border-sky-200 dark:border-sky-500/30">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $m['corrections']->count() }} correction {{ \Illuminate\Support\Str::plural('slip', $m['corrections']->count()) }}
                        ({{ $m['corrections']->pluck('slip_number')->implode(', ') }}) adjusted this month by
                        <strong class="tabular-nums">{{ money($m['summary']['adjustments']) }}</strong>, so the net after corrections is
                        <strong class="tabular-nums">{{ money($m['summary']['net_after_corrections']) }}</strong>.
                    </p>
                </x-ui.card>
            @endif

            <div class="mb-4 grid gap-4 lg:grid-cols-3">
                <x-ui.card title="Attendance summary" class="lg:col-span-2">
                    @php($a = $m['attendance'])
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                        @foreach ([
                            'Working days' => $a['working_days'],
                            'Present days' => $a['present_days'],
                            'Absent days' => $a['absent_days'],
                            'Late arrivals' => $a['late_count'].($a['late_minutes'] ? ' · '.$a['late_minutes'].' min' : ''),
                            'Half days' => $a['half_days'],
                            'Paid leave days' => $a['paid_leave_days'],
                            'Unpaid leave days' => $a['unpaid_leave_days'],
                            'Unpaid days in all' => $a['lop_days'],
                        ] as $label => $value)
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        Per-day salary {{ money($m['summary']['per_day']) }} (the salary basis ÷ {{ rtrim(rtrim($m['summary']['day_divisor'], '0'), '.') }} days), as frozen on the slip.
                    </p>
                </x-ui.card>

                <x-ui.card title="Deductions by reason">
                    <ul class="space-y-2 text-sm">
                        @foreach ($m['categories'] as $kind => $category)
                            @continue($category['count'] === 0 && \App\Support\Money::compare($category['amount'], '0') === 0)
                            <li class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <x-ui.badge :color="$kindColours[$kind]" size="xs">{{ $category['count'] }}</x-ui.badge>
                                    <span class="text-slate-700 dark:text-slate-200">{{ $category['label'] }}</span>
                                </span>
                                <span class="tabular-nums font-medium text-slate-900 dark:text-white">{{ money($category['amount']) }}</span>
                            </li>
                        @endforeach
                        @if (collect($m['categories'])->every(fn ($c) => $c['count'] === 0))
                            <li class="text-slate-500 dark:text-slate-400">Nothing was deducted this month.</li>
                        @endif
                    </ul>
                </x-ui.card>
            </div>

            @include('admin.hr.my._salary-history', ['rows' => $m['history'], 'title' => 'Deduction history', 'empty' => 'Nothing was deducted from this month\'s salary.'])
        @endif
    @endif

    {{-- ===================================================================== DAILY --}}
    @if ($view === 'day')
        @php($m = $day['month'])
        @php($row = $day['attendance'])

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ app_date($date, 'l, j F Y') }}</h2>
            <x-ui.badge :color="$m['status']['color']">{{ $date->format('F') }} payroll: {{ $m['status']['label'] }}</x-ui.badge>
        </div>

        <x-ui.card class="mb-4" title="Attendance that day">
            @if ($row)
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Status</dt><dd><x-ui.badge :color="$row->status->color()" size="xs">{{ $row->status->label() }}</x-ui.badge></dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">In / out</dt><dd class="tabular-nums text-slate-900 dark:text-white">{{ $row->check_in_at ? app_time($row->check_in_at, 'H:i') : '—' }} – {{ $row->check_out_at ? app_time($row->check_out_at, 'H:i') : '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Late by</dt><dd class="tabular-nums text-slate-900 dark:text-white">{{ $row->late_minutes ? $row->late_minutes.' min' : '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Paid share of the day</dt><dd class="tabular-nums text-slate-900 dark:text-white">{{ rtrim(rtrim((string) $row->payable_factor, '0'), '.') }}</dd></div>
                </dl>
                @if ($row->leaveType)
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Leave: {{ $row->leaveType->name }}{{ $row->leaveType->is_paid ? ' (paid)' : ' (unpaid)' }}</p>
                @endif
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">No attendance is recorded for this day.</p>
            @endif
        </x-ui.card>

        @if (! $m['slip'])
            <x-ui.card>
                <x-ui.empty-state icon="banknotes" title="Not finalized yet"
                    :message="'Deductions for a day are shown once '.$date->format('F Y').' payroll is finalized. Anything already noted for this day is under Deduction notices.'" />
            </x-ui.card>
        @else
            @include('admin.hr.my._salary-history', ['rows' => $day['rows'], 'title' => 'Deductions on this day', 'empty' => 'Nothing was deducted for this day.'])
        @endif
    @endif

    {{-- ===================================================================== YEARLY --}}
    @if ($view === 'year')
        <x-ui.card :title="$year['year'].' — salary by month'" :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-semibold">Month</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Gross</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Deductions</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Net salary</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Corrections</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($year['months'] as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.my.salary.index', ['view' => 'month', 'month' => sprintf('%04d-%02d', $year['year'], $row['month'])]) }}"
                                       class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $row['label'] }}</a>
                                </td>
                                <td class="px-4 py-3"><x-ui.badge :color="$row['status']['color']" size="xs">{{ $row['status']['label'] }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ $row['gross'] !== null ? money($row['gross']) : '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-rose-600 dark:text-rose-400">{{ $row['deductions'] !== null ? money($row['deductions']) : '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ $row['net'] !== null ? money($row['net']) : '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $row['adjustments'] !== null ? money($row['adjustments']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-semibold dark:bg-slate-900/60">
                        <tr>
                            <td class="px-4 py-3 text-slate-900 dark:text-white" colspan="2">Finalized total</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-900 dark:text-white">{{ money($year['totals']['gross']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-600 dark:text-rose-400">{{ money($year['totals']['deductions']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-900 dark:text-white">{{ money($year['totals']['net']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ money($year['totals']['adjustments']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                Only finalized months carry figures. Open a month for its deduction history.
            </p>
        </x-ui.card>
    @endif
@endsection
