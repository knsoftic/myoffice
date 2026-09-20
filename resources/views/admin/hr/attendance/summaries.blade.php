@extends('layouts.admin')

@section('title', 'Monthly summaries')

@section('header')
    <x-ui.page-header title="Monthly summaries" :subtitle="$month->format('F Y') . ' — the only figures payroll reads'" icon="chart-bar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.attendance.monthly', ['month' => $month->format('Y-m')])" icon="table-cells">Monthly grid</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            <strong class="font-semibold text-slate-900 dark:text-white">Payable days</strong> sum every row in the
            month — working days, weekends and paid holidays all count, so a full month equals the calendar days.
            <strong class="font-semibold text-slate-900 dark:text-white">Lost-pay days</strong> sum working days only:
            a weekend is never a deduction.
        </p>
    </x-ui.card>

    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-end gap-3">
            <form method="GET" class="flex flex-1 flex-wrap items-end gap-3">
                <div class="w-44">
                    <x-ui.form.input type="month" name="month" label="Month" :value="$month->format('Y-m')" />
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

            @if ($canRebuild)
                <form method="POST" action="{{ route('admin.attendance-summaries.rebuild') }}">
                    @csrf
                    <input type="hidden" name="month" value="{{ $month->toDateString() }}">
                    <x-ui.button type="submit" variant="ghost" icon="arrow-path">Rebuild this month</x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card :title="$summaries->total() . ' summary(ies)'">
        <x-ui.table :is-empty="$summaries->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-right font-semibold">Working</th>
                <th class="px-4 py-3 text-right font-semibold">Present</th>
                <th class="px-4 py-3 text-right font-semibold">Absent</th>
                <th class="px-4 py-3 text-right font-semibold">Leave</th>
                <th class="px-4 py-3 text-right font-semibold">Payable</th>
                <th class="px-4 py-3 text-right font-semibold">Lost pay</th>
                <th class="px-4 py-3 text-right font-semibold">Late</th>
                <th class="px-4 py-3 text-left font-semibold"></th>
            </x-slot:head>

            @foreach ($summaries as $summary)
                <tr>
                    <td class="px-4 py-3">
                        <span class="block font-medium text-slate-900 dark:text-white">{{ $summary->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $summary->employee?->employee_code }} · {{ $summary->employee?->department?->name }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $summary->working_days, 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $summary->present_days, 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ app_number((float) $summary->absent_days, 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number((float) $summary->paid_leave_days, 2) }}
                        @if ((float) $summary->unpaid_leave_days > 0)
                            <span class="text-xs text-rose-600 dark:text-rose-400">+{{ app_number((float) $summary->unpaid_leave_days, 2) }} unpaid</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ app_number((float) $summary->payable_days, 4) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums {{ (float) $summary->lop_days > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">
                        {{ app_number((float) $summary->lop_days, 4) }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $summary->late_count }}</td>
                    <td class="px-4 py-3 text-xs">
                        @if ($summary->locked_at)
                            <span class="text-rose-600 dark:text-rose-400">locked by {{ $summary->lockedByRun?->run_number }}</span>
                        @elseif ($summary->is_final)
                            <span class="text-emerald-600 dark:text-emerald-400">final</span>
                        @else
                            <span class="text-slate-400">open</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="chart-bar" title="No summaries for this month"
                    description="Rebuild the month to produce them — payroll refuses to guess without one." />
            </x-slot:empty>
        </x-ui.table>

        @if ($summaries->hasPages())
            <div class="mt-4">{{ $summaries->links() }}</div>
        @endif
    </x-ui.card>
@endsection
