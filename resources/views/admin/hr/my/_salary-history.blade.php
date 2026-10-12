{{--
    The deduction history table of "My Salary & Deductions". Each row opens to show how its amount was
    calculated and which slip took it. Expects: $rows (MySalaryService history rows), $title, $empty.
--}}

@php
    $kindColours = ['late' => 'amber', 'absence' => 'rose', 'unpaid_leave' => 'violet', 'other' => 'slate', 'refund' => 'emerald'];
    $total = \App\Support\Money::round(\App\Support\Money::sum(['0', ...collect($rows)->pluck('amount')->all()]));
@endphp

<x-ui.card :title="$title" :padded="false">
    @if (collect($rows)->isEmpty())
        <div class="px-4 py-8">
            <x-ui.empty-state icon="check-circle" :title="$empty" :compact="true" />
        </div>
    @else
        <div class="overflow-x-auto" x-data="{ open: null }">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Date</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Reason</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Details</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Deduction amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($rows as $index => $row)
                        <tr class="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50"
                            x-on:click="open = open === {{ $index }} ? null : {{ $index }}"
                            x-on:keydown.enter="open = open === {{ $index }} ? null : {{ $index }}"
                            tabindex="0" role="button" :aria-expanded="open === {{ $index }}">
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700 dark:text-slate-200">
                                @if ($row['date'])
                                    {{ app_date($row['date']) }}
                                @else
                                    <span class="text-slate-500 dark:text-slate-400">{{ app_date($row['month_date'], 'M Y') }} (month)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$kindColours[$row['kind']] ?? 'slate'" size="xs">{{ $row['reason'] }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row['details'] }}</td>
                            <td @class([
                                'whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums',
                                'text-emerald-600 dark:text-emerald-400' => \App\Support\Money::compare($row['amount'], '0') < 0,
                                'text-slate-900 dark:text-white' => \App\Support\Money::compare($row['amount'], '0') >= 0,
                            ])>{{ money($row['amount']) }}</td>
                        </tr>
                        <tr x-show="open === {{ $index }}" x-cloak class="bg-slate-50/70 dark:bg-slate-900/40">
                            <td colspan="4" class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                <span class="font-semibold text-slate-700 dark:text-slate-200">How it was calculated:</span>
                                {{ $row['calculation'] }}
                                <span class="text-slate-400"> · taken by slip {{ $row['slip'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50 font-semibold dark:bg-slate-900/60">
                    <tr>
                        <td class="px-4 py-3 text-slate-900 dark:text-white" colspan="3">Total</td>
                        <td class="px-4 py-3 text-right tabular-nums text-slate-900 dark:text-white">{{ money($total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">Click a row to see how it was calculated. A negative amount was given back.</p>
    @endif
</x-ui.card>
