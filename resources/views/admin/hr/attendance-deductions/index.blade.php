@extends('layouts.admin')

@section('title', 'Attendance deductions')

@php
    $canReverse = (bool) auth()->user()?->can('payroll.change_status');
@endphp

@section('header')
    <x-ui.page-header title="Attendance deductions"
        subtitle="Late arrivals and absences beyond the monthly allowance — taken by the next payroll run, applied when it is locked."
        icon="receipt-percent">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payroll-runs.index')" icon="banknotes">Payroll runs</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if (! $lateEnabled || ! $absenceEnabled)
        <x-ui.card class="mb-4">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Late arrival deduction is <strong>{{ $lateEnabled ? 'on' : 'off' }}</strong>;
                excess absence deduction is <strong>{{ $absenceEnabled ? 'on' : 'off' }}</strong>.
                Both are switched in Settings → HR. Records below stay whatever their state.
            </p>
        </x-ui.card>
    @endif

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Pending (next payroll)" :value="money($totals['pending']) . ' · ' . $totals['pending_count']" icon="clock" color="amber" />
        <x-ui.stat-card label="Applied (locked slips)" :value="money($totals['applied']) . ' · ' . $totals['applied_count']" icon="check-circle" color="rose" />
        <x-ui.stat-card label="Reversed" :value="(string) $totals['reversed_count']" icon="arrow-path" color="slate" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="w-64">
                <x-ui.form.select name="employee_id" label="Employee" :options="$employees" :selected="$filters['employee_id'] ?? null" placeholder="Everybody" />
            </div>
            <div class="w-44">
                <x-ui.form.input type="month" name="month" label="Month" :value="$filters['month'] ?? null" />
            </div>
            <div class="w-48">
                <x-ui.form.select name="type" label="Reason" :options="$types" :selected="$filters['type'] ?? null" placeholder="Every reason" />
            </div>
            <div class="w-40">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="$filters['status'] ?? null" placeholder="Every status" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.attendance-deductions.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$deductions->total() . ' deduction(s)'">
        <x-ui.table :is-empty="$deductions->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Employee</th>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">Reason</th>
                <th class="px-4 py-3 text-right font-semibold">Daily salary</th>
                <th class="px-4 py-3 text-right font-semibold">Rate</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($deductions as $deduction)
                <tr>
                    <td class="px-4 py-3 align-top">
                        <span class="block font-medium text-slate-900 dark:text-white">{{ $deduction->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $deduction->employee?->employee_code }}</span>
                    </td>
                    <td class="px-4 py-3 align-top tabular-nums text-slate-600 dark:text-slate-300">{{ app_date($deduction->deduction_date) }}</td>
                    <td class="px-4 py-3 align-top">
                        <x-ui.badge :color="$deduction->deduction_type->color()" size="xs">{{ $deduction->deduction_type->label() }}</x-ui.badge>
                        <span class="mt-1 block max-w-sm text-xs text-slate-500 dark:text-slate-400">{{ $deduction->displayReason() }}</span>
                    </td>
                    <td class="px-4 py-3 align-top text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $deduction->daily_salary) }}</td>
                    <td class="px-4 py-3 align-top text-right tabular-nums text-slate-600 dark:text-slate-300">{{ rtrim(rtrim((string) $deduction->rate_percentage, '0'), '.') }}%</td>
                    <td class="px-4 py-3 align-top text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $deduction->amount) }}</td>
                    <td class="px-4 py-3 align-top">
                        <x-ui.badge :color="$deduction->status->color()" size="xs">{{ $deduction->status->label() }}</x-ui.badge>
                        @if ($deduction->payrollRunItem)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">slip {{ $deduction->payrollRunItem->slip_number }}</span>
                        @endif
                        @if ($deduction->status->value === 'reversed')
                            <span class="block max-w-xs text-xs text-slate-500 dark:text-slate-400">
                                {{ $deduction->reversal_reason }}{{ $deduction->reverser ? ' — '.$deduction->reverser->name : '' }}
                                @if ($deduction->correctionItem) (refund slip {{ $deduction->correctionItem->slip_number }}) @endif
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 align-top">
                        @if ($canReverse && $deduction->status->value !== 'reversed')
                            <div class="flex justify-end">
                                <x-ui.button variant="ghost" size="sm" icon="arrow-path"
                                    x-on:click="$dispatch('open-modal', 'reverse-deduction-{{ $deduction->id }}')">Reverse</x-ui.button>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="receipt-percent" title="No attendance deductions"
                    message="Deductions appear here when a rule is switched on in Settings → HR and attendance calls for one." />
            </x-slot:empty>
        </x-ui.table>

        @if ($deductions->hasPages())
            <div class="mt-4">{{ $deductions->links() }}</div>
        @endif
    </x-ui.card>

    @if ($canReverse)
        @foreach ($deductions as $deduction)
            @continue($deduction->status->value === 'reversed')
            <x-ui.modal :name="'reverse-deduction-' . $deduction->id" title="Reverse this deduction" icon="arrow-path">
                <form method="POST" action="{{ route('admin.attendance-deductions.reverse', $deduction) }}" class="space-y-3">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $deduction->employee?->name }} — {{ $deduction->deduction_type->label() }} on
                        {{ app_date($deduction->deduction_date) }}, {{ money((string) $deduction->amount) }}.
                        @if ($deduction->status->value === 'applied')
                            A locked slip already took this amount, so it will be refunded through a draft correction slip.
                        @else
                            It will not be taken by payroll, and attendance changes will not bring it back.
                        @endif
                    </p>
                    <x-ui.form.textarea name="reason" label="Why" rows="3" required
                        help="The employee sees this reason." />
                    <x-ui.button type="submit" variant="danger" class="w-full">Reverse deduction</x-ui.button>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
