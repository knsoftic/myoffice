@extends('layouts.admin')

@section('title', 'My salary deductions')

@section('header')
    <x-ui.page-header title="My salary deductions" subtitle="Late arrivals and absences beyond your monthly allowance, and what each one costs." icon="receipt-percent" />
@endsection

@section('content')
    <x-ui.card :title="$deductions->total() . ' deduction(s)'">
        <x-ui.table :is-empty="$deductions->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Date</th>
                <th class="px-4 py-3 text-left font-semibold">Reason</th>
                <th class="px-4 py-3 text-right font-semibold">Daily salary</th>
                <th class="px-4 py-3 text-right font-semibold">Rate</th>
                <th class="px-4 py-3 text-right font-semibold">Amount</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($deductions as $deduction)
                <tr>
                    <td class="px-4 py-3 align-top tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_date($deduction->deduction_date) }}
                        <span class="block text-xs text-slate-500 dark:text-slate-400">recorded {{ app_date($deduction->created_at) }}</span>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <x-ui.badge :color="$deduction->deduction_type->color()" size="xs">{{ $deduction->deduction_type->label() }}</x-ui.badge>
                        <p class="mt-1 max-w-xl text-sm text-slate-600 dark:text-slate-300">{{ $deduction->explanation() }}</p>
                    </td>
                    <td class="px-4 py-3 align-top text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $deduction->daily_salary) }}</td>
                    <td class="px-4 py-3 align-top text-right tabular-nums text-slate-600 dark:text-slate-300">
                        {{ rtrim(rtrim((string) $deduction->rate_percentage, '0'), '.') }}%
                        @if ($deduction->absence_sequence !== null)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">absence {{ $deduction->absence_sequence }} of the month</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 align-top text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $deduction->amount) }}</td>
                    <td class="px-4 py-3 align-top">
                        <x-ui.badge :color="$deduction->status->color()" size="xs">{{ $deduction->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="receipt-percent" title="No deductions"
                    message="Nothing has been deducted from your salary for attendance." />
            </x-slot:empty>
        </x-ui.table>

        @if ($deductions->hasPages())
            <div class="mt-4">{{ $deductions->links() }}</div>
        @endif
    </x-ui.card>
@endsection
