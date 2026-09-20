@extends('layouts.admin')

@section('title', 'Payroll register — ' . $run->run_number)

@section('header')
    <x-ui.page-header :title="'Payroll register — ' . $run->run_number" :subtitle="$run->periodLabel()" icon="printer">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payroll-runs.show', $run)">Back to the run</x-ui.button>
            <x-ui.button onclick="window.print()" icon="printer">Print</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card :title="$run->items->count() . ' salary slip(s)'" :subtitle="$run->branch?->name ?? 'All branches'">
        <x-ui.table>
            <x-slot:head>
                <th class="px-3 py-2 text-left font-semibold">Slip</th>
                <th class="px-3 py-2 text-left font-semibold">Employee</th>
                <th class="px-3 py-2 text-right font-semibold">Basic</th>
                <th class="px-3 py-2 text-right font-semibold">Allowances</th>
                <th class="px-3 py-2 text-right font-semibold">Gross</th>
                <th class="px-3 py-2 text-right font-semibold">Deductions</th>
                <th class="px-3 py-2 text-right font-semibold">Net</th>
                <th class="px-3 py-2 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($run->items->sortBy('slip_number') as $item)
                <tr>
                    <td class="px-3 py-2 tabular-nums text-slate-600 dark:text-slate-300">{{ $item->slip_number }}</td>
                    <td class="px-3 py-2">
                        <span class="block text-slate-900 dark:text-white">{{ $item->employee?->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $item->employee?->employee_code }}</span>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money((string) $item->basic_salary) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money((string) $item->allowance_amount) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money((string) $item->gross_earnings) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money((string) $item->total_deductions) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ money((string) $item->net_salary) }}</td>
                    <td class="px-3 py-2 text-xs">{{ $item->status->label() }}</td>
                </tr>
            @endforeach
        </x-ui.table>

        <dl class="mt-4 flex flex-wrap gap-8 border-t border-slate-200 pt-4 text-sm dark:border-slate-700">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total gross</dt>
                <dd class="tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $run->total_gross) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total deductions</dt>
                <dd class="tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $run->total_deductions) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total net</dt>
                <dd class="tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $run->total_net) }}</dd>
            </div>
        </dl>
    </x-ui.card>
@endsection
