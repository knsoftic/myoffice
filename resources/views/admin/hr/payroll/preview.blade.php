@extends('layouts.admin')

@section('title', 'Preview — ' . $employee->name)

@section('header')
    <x-ui.page-header
        :title="'Preview — ' . $employee->name"
        :subtitle="$run->run_number . ' · ' . $run->periodLabel()"
        icon="eye">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payroll-runs.show', $run)">Back to the run</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            This is the <strong class="font-semibold text-slate-900 dark:text-white">same calculator</strong> the
            generator uses, run read-only. A preview can therefore never differ from the slip that gets written.
        </p>
    </x-ui.card>

    @if ($draft->wasSkipped())
        <x-ui.card>
            <x-ui.empty-state icon="exclamation-triangle" title="This employee would be skipped"
                :description="'Reason: ' . str_replace('_', ' ', $draft->skipReason) . '. Nothing is written, and they are not paid zero.'" />
        </x-ui.card>
    @else
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card title="The lines">
                    <x-ui.table>
                        <x-slot:head>
                            <th class="px-4 py-3 text-left font-semibold">Component</th>
                            <th class="px-4 py-3 text-left font-semibold">How it was worked out</th>
                            <th class="px-4 py-3 text-right font-semibold">Amount</th>
                        </x-slot:head>

                        @foreach ($draft->lines as $line)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="block font-medium text-slate-900 dark:text-white">{{ $line->componentName }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $line->componentCode }} · {{ $line->group->label() }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">{{ $line->calculationNote }}</td>
                                <td class="px-4 py-3 text-right tabular-nums {{ $line->isDeduction() ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                    {{ $line->isDeduction() ? '−' : '' }}{{ money($line->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            </div>

            <div class="space-y-4">
                <x-ui.card title="Totals">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Gross earnings</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($draft->grossEarnings) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Deductions</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($draft->totalDeductions) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                            <dt class="text-slate-700 dark:text-slate-200">Net</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($draft->netSalary) }}</dd>
                        </div>
                    </dl>

                    @if ($draft->holdReason)
                        <p class="mt-3 text-xs text-amber-700 dark:text-amber-300">{{ $draft->holdReason }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card title="How it was divided">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Day divisor</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $draft->dayDivisor, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Per-day amount</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($draft->perDayAmount) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Taxable gross</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($draft->taxableGross) }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    @endif
@endsection
