{{-- The slip body, shared by the screen and the print view: one definition, printed twice. --}}
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $employee?->name }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ $employee?->employee_code }}
                @if ($item->designation_title) · {{ $item->designation_title }} @endif
                @if ($item->department_name) · {{ $item->department_name }} @endif
            </p>
        </div>
        <div class="text-right">
            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $item->slip_number }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ $run?->periodLabel() }} · {{ $run?->run_number }}
            </p>
        </div>
    </div>

    @if ($showAttendance)
        <dl class="grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-4 text-sm sm:grid-cols-5 dark:bg-slate-800/50">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Working days</dt>
                <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $item->working_days, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Present</dt>
                <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $item->present_days, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Payable days</dt>
                <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $item->payable_days, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Lost pay</dt>
                <dd class="tabular-nums text-slate-900 dark:text-white">{{ app_number((float) $item->lop_days, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Per day</dt>
                <dd class="tabular-nums text-slate-900 dark:text-white">{{ money((string) $item->per_day_amount) }}</dd>
            </div>
        </dl>
    @endif

    <div class="grid gap-6 sm:grid-cols-2">
        <div>
            <h3 class="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Earnings</h3>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($earnings as $line)
                        <tr>
                            <td class="py-2">
                                <span class="block text-slate-900 dark:text-white">{{ $line->component_name }}</span>
                                @if ($line->calculation_note)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $line->calculation_note }}</span>
                                @endif
                            </td>
                            <td class="py-2 text-right tabular-nums text-slate-900 dark:text-white">{{ money((string) $line->amount) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-slate-200 dark:border-slate-700">
                        <td class="py-2 font-semibold text-slate-900 dark:text-white">Gross earnings</td>
                        <td class="py-2 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $item->gross_earnings) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div>
            <h3 class="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Deductions</h3>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($deductions as $line)
                        <tr>
                            <td class="py-2">
                                <span class="block text-slate-900 dark:text-white">{{ $line->component_name }}</span>
                                @if ($line->calculation_note)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $line->calculation_note }}</span>
                                @endif
                            </td>
                            <td class="py-2 text-right tabular-nums text-slate-900 dark:text-white">{{ money((string) $line->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-slate-500 dark:text-slate-400">Nothing deducted.</td><td></td></tr>
                    @endforelse
                    <tr class="border-t-2 border-slate-200 dark:border-slate-700">
                        <td class="py-2 font-semibold text-slate-900 dark:text-white">Total deductions</td>
                        <td class="py-2 text-right tabular-nums font-semibold text-slate-900 dark:text-white">{{ money((string) $item->total_deductions) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg bg-slate-900 px-4 py-3 text-white dark:bg-slate-700">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold">Net salary</span>
            <span class="text-lg font-semibold tabular-nums">{{ money((string) $item->net_salary) }}</span>
        </div>
        <p class="mt-1 text-xs text-slate-300">{{ $amountInWords }} only.</p>
    </div>

    @if ($item->paid_at)
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Paid {{ app_date($item->paid_at) }} by {{ $item->payment_method?->label() }}
            @if ($item->payment_reference) · reference {{ $item->payment_reference }} @endif
        </p>
    @endif

    @if ($footerNote)
        <p class="border-t border-slate-200 pt-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">{{ $footerNote }}</p>
    @endif
</div>
