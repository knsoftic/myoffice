{{--
    The pivot for one granularity: a row per day, week or month; a column per category (largest
    first); the Approved / Awaiting-approval subtotals; the count and the row total; and a grand-total
    row underneath. Expects: $sheet (ExpenseSheet), $key (day|week|month), $rows, $heading.
--}}
@php
    $value = static fn (string|int $amount): string => $sheet->seesMoney ? money((string) $amount, false) : app_number((int) $amount);
    $stickyHead = 'sticky left-0 z-20 bg-slate-50 px-4 py-3 text-left font-semibold dark:bg-slate-900';
    $stickyCell = 'sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-2 text-left font-medium text-slate-700 dark:bg-slate-900 dark:text-slate-200';
@endphp

<x-ui.table :dense="true" max-height="60vh" :is-empty="! $sheet->hasData"
            :caption="'Expense sheet, '.strtolower($heading).' by category'">
    <x-slot:head>
        <th scope="col" class="{{ $stickyHead }}">{{ $heading }}</th>
        @foreach ($sheet->columns as $column)
            <th scope="col" class="whitespace-nowrap px-4 py-3 text-right font-semibold">{{ $column['name'] }}</th>
        @endforeach
        @foreach ($sheet->subtotalStatuses as $status)
            <th scope="col" class="whitespace-nowrap border-l border-slate-200 px-4 py-3 text-right font-semibold dark:border-slate-700">{{ $status->label() }}</th>
        @endforeach
        <th scope="col" class="whitespace-nowrap border-l border-slate-200 px-4 py-3 text-right font-semibold dark:border-slate-700">Expenses</th>
        <th scope="col" class="whitespace-nowrap px-4 py-3 text-right font-semibold">Total</th>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr>
            <th scope="row" class="{{ $stickyCell }}">{{ $row['label'] }}</th>
            @foreach ($sheet->columns as $column)
                @php $cell = $row['cells'][$column['key']]; @endphp
                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums {{ $sheet->isZero($cell) ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $sheet->isZero($cell) ? '—' : $value($cell) }}
                </td>
            @endforeach
            @foreach ($sheet->subtotalStatuses as $status)
                @php $cell = $row['statuses'][$status->value]; @endphp
                <td class="whitespace-nowrap border-l border-slate-200 px-4 py-2 text-right tabular-nums dark:border-slate-700 {{ $sheet->isZero($cell) ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $sheet->isZero($cell) ? '—' : $value($cell) }}
                </td>
            @endforeach
            <td class="whitespace-nowrap border-l border-slate-200 px-4 py-2 text-right tabular-nums text-slate-500 dark:border-slate-700 dark:text-slate-400">
                {{ $row['count'] > 0 ? app_number($row['count']) : '—' }}
            </td>
            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums {{ $sheet->isZero($row['total']) ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-white' }}">
                {{ $sheet->isZero($row['total']) ? '—' : $value($row['total']) }}
            </td>
        </tr>
    @endforeach

    @if ($sheet->hasData)
    <x-slot:foot>
        <tr>
            <th scope="row" class="sticky left-0 z-10 whitespace-nowrap bg-slate-50 text-left dark:bg-slate-900">Grand total</th>
            @foreach ($sheet->columns as $column)
                <td class="whitespace-nowrap text-right tabular-nums">{{ $value($sheet->totals['cells'][$column['key']]) }}</td>
            @endforeach
            @foreach ($sheet->subtotalStatuses as $status)
                <td class="whitespace-nowrap border-l border-slate-200 text-right tabular-nums dark:border-slate-700">{{ $value($sheet->totals['statuses'][$status->value]) }}</td>
            @endforeach
            <td class="whitespace-nowrap border-l border-slate-200 text-right tabular-nums dark:border-slate-700">{{ app_number($sheet->totals['count']) }}</td>
            <td class="whitespace-nowrap text-right tabular-nums text-slate-900 dark:text-white">
                {{ $sheet->seesMoney ? money((string) $sheet->totals['total']) : app_number((int) $sheet->totals['total']) }}
            </td>
        </tr>
    </x-slot:foot>
    @endif

    <x-slot:empty>
        <x-ui.empty-state icon="table-cells" title="No expenses match these filters" :compact="true"
                          message="Widen the From / To dates or clear a filter." />
    </x-slot:empty>
</x-ui.table>
