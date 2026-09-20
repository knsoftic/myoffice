@extends('layouts.admin')

@section('title', $run->run_number)

@php
    $locked = $run->status->isLocked();
@endphp

@section('header')
    <x-ui.page-header :title="$run->run_number" :subtitle="$run->periodLabel() . ' · ' . $run->run_type->label()" icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.payroll-runs.index')">All runs</x-ui.button>
            @if ($locked)
                <x-ui.button variant="ghost" :href="route('admin.payroll-runs.register.print', $run)" icon="printer">Register</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-center gap-6">
            @foreach ([
                ['Draft', 'draft'],
                ['Generated', 'generated'],
                ['Locked', 'locked'],
                ['Paid', 'paid'],
            ] as [$label, $value])
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2 w-2 rounded-full {{ $run->status->value === $value ? 'bg-brand-600' : 'bg-slate-300 dark:bg-slate-600' }}"></span>
                    <span class="text-sm {{ $run->status->value === $value ? 'font-semibold text-slate-900 dark:text-white' : 'text-slate-500 dark:text-slate-400' }}">{{ $label }}</span>
                </div>
            @endforeach
        </div>

        @if ($locked)
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                <strong class="font-semibold text-slate-900 dark:text-white">This run is locked.</strong>
                There is no unlock — a mistake found now is fixed with a correction run against the slip, which is
                generated, locked and paid through the same lifecycle.
            </p>
        @endif
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-4">
        <div class="space-y-4 lg:col-span-3">
            <x-ui.card :title="$run->employee_count . ' salary slip(s)'">
                <x-ui.table :is-empty="$items->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Slip</th>
                        <th class="px-4 py-3 text-left font-semibold">Employee</th>
                        @if ($showMoney)
                            <th class="px-4 py-3 text-right font-semibold">Gross</th>
                            <th class="px-4 py-3 text-right font-semibold">Deductions</th>
                            <th class="px-4 py-3 text-right font-semibold">Net</th>
                        @endif
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.payslips.show', $item) }}"
                                   class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $item->slip_number }}</a>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ app_number((float) $item->payable_days, 2) }} payable
                                    @if ((float) $item->lop_days > 0) · {{ app_number((float) $item->lop_days, 2) }} lost @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                <span class="block">{{ $item->employee?->name }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $item->employee?->employee_code }}</span>
                            </td>
                            @if ($showMoney)
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $item->gross_earnings) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ money((string) $item->total_deductions) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $item->net_salary) }}</td>
                            @endif
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$item->status->color()" size="xs">{{ $item->status->label() }}</x-ui.badge>
                                @if ($item->hold_reason)
                                    <span class="block max-w-xs truncate text-xs text-amber-600 dark:text-amber-400">{{ $item->hold_reason }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($showMoney)
                                        <x-ui.button variant="ghost" size="sm"
                                            :href="route('admin.payroll-runs.preview', ['run' => $run, 'employee' => $item->employee_id])">Explain</x-ui.button>
                                    @endif
                                    @if ($canPay && $item->status->isPayable())
                                        <x-ui.button variant="ghost" size="sm"
                                            x-on:click="$dispatch('open-modal', 'pay-{{ $item->id }}')">Pay</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="document-text" title="No slips yet"
                            description="Generate the run. Anybody with no salary structure or no attendance summary is named rather than paid zero." />
                    </x-slot:empty>
                </x-ui.table>

                @if ($items->hasPages())
                    <div class="mt-4">{{ $items->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($showMoney)
                <x-ui.card title="Totals">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Gross</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $run->total_gross) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Deductions</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $run->total_deductions) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                            <dt class="text-slate-700 dark:text-slate-200">Net</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $run->total_net) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Paid so far</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $run->total_paid) }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif

            <x-ui.card title="Basis" subtitle="Snapshotted when the run opened.">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Day divisor</dt>
                        <dd class="text-slate-900 dark:text-white">{{ str_replace('_', ' ', $run->day_basis) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Loss of pay on</dt>
                        <dd class="text-slate-900 dark:text-white">{{ $run->lop_basis }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Tax</dt>
                        <dd class="text-slate-900 dark:text-white">{{ str_replace('_', ' ', $run->tax_mode) }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            @if ($canGenerate)
                <x-ui.card title="Generate">
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        Rebuilds every draft slip. Bonus and commission lines already entered are carried over.
                    </p>
                    <form method="POST" action="{{ route('admin.payroll-runs.generate', $run) }}">
                        @csrf
                        <x-ui.button type="submit" class="w-full" icon="arrow-path">Generate slips</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canLock && $items->isNotEmpty())
                <x-ui.card title="Lock">
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        Freezes every slip and the attendance behind them. There is no unlock.
                    </p>
                    <form method="POST" action="{{ route('admin.payroll-runs.lock', $run) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" class="w-full" icon="lock-closed">Lock this run</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canCancel)
                <x-ui.card title="Cancel">
                    <form method="POST" action="{{ route('admin.payroll-runs.cancel', $run) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.textarea name="reason" label="Why" rows="2" required />
                        <x-ui.button type="submit" variant="ghost" class="w-full">Cancel the run</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($canPay)
        @foreach ($items as $item)
            @continue(! $item->status->isPayable())
            <x-ui.modal :name="'pay-' . $item->id" title="Record payment for {{ $item->slip_number }}" icon="banknotes">
                <form method="POST" action="{{ route('admin.payroll-items.pay', $item) }}" class="space-y-3">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ $item->employee?->name }} — {{ money((string) $item->net_salary) }}. Marking it paid also
                        posts any advance recovery on this slip.
                    </p>
                    <x-ui.form.select name="payment_method" label="How" :options="\App\Enums\PaymentMethod::options()" selected="bank_transfer" required />
                    <x-ui.form.input name="payment_reference" label="Reference" maxlength="64"
                        help="Required for everything except cash." />
                    <x-ui.form.input type="date" name="paid_at" label="Paid on" :value="now()->toDateString()" />
                    <x-ui.button type="submit" class="w-full">Record payment</x-ui.button>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
