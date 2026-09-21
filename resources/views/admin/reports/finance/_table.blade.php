{{--
    The body of one finance report, shared by the screen and the printed page (phase-13 §8.14).

    Four report shapes, one partial — because the screen and the print must agree to the paisa, and two
    implementations of "how a profit-and-loss statement is laid out" would eventually disagree about
    which line the commission goes on.

    Expects: $type (FinanceReportType) · $result (ReportResult) · optionally $print (bool) and
             $linkRows (bool, whether client rows deep-link into the invoice register).
--}}

@php
    $print = $print ?? false;
    $linkRows = ($linkRows ?? ! $print);
    $meta = $result->meta;

    // The printed page carries its own stylesheet, so the two renderings differ only in their classes.
    $cls = $print
        ? ['table' => 'doc', 'num' => 'num', 'muted' => 'muted', 'strong' => 'strong']
        : [
            'table' => 'w-full text-sm',
            'num' => 'text-right tabular-nums',
            'muted' => 'text-slate-500 dark:text-slate-400',
            'strong' => 'font-semibold text-slate-900 dark:text-white',
        ];

    $rowCls = $print ? '' : 'border-b border-slate-200 dark:border-slate-800';
    $cellCls = $print ? '' : 'px-3 py-2';
    $headCls = $print ? '' : 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
@endphp

@switch ($type)

    {{-- ------------------------------------------------------------------ Income --}}
    @case (\App\Enums\FinanceReportType::Income)
        <table class="{{ $cls['table'] }}">
            <thead>
                <tr>
                    <th class="{{ $headCls }}">Source</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Entries</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Received</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    @php
                        $share = bccomp((string) $result->totals['amount'], '0.00', 2) === 1
                            ? bcdiv(bcmul((string) $row['amount'], '100', 4), (string) $result->totals['amount'], 2)
                            : '0.00';
                    @endphp
                    <tr class="{{ $rowCls }}">
                        <td class="{{ $cellCls }}">{{ $row['source'] }}</td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }}">{{ $row['entries'] }}</td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($row['amount']) }}</td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['muted'] }}">{{ $share }}%</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="{{ $print ? 'total' : 'border-t-2 border-slate-900 dark:border-slate-200' }}">
                    <td class="{{ $cellCls }} {{ $cls['strong'] }}">Total received</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }}">{{ $result->totals['entries'] ?? 0 }}</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($result->totals['amount'] ?? '0.00') }}</td>
                    <td class="{{ $cellCls }}"></td>
                </tr>
            </tfoot>
        </table>
        @break

    {{-- ---------------------------------------------------------------- Expenses --}}
    @case (\App\Enums\FinanceReportType::Expenses)
        <table class="{{ $cls['table'] }}">
            <thead>
                <tr>
                    <th class="{{ $headCls }}">Category</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Entries</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Spent</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    @php
                        $share = bccomp((string) $result->totals['amount'], '0.00', 2) === 1
                            ? bcdiv(bcmul((string) $row['amount'], '100', 4), (string) $result->totals['amount'], 2)
                            : '0.00';
                    @endphp
                    <tr class="{{ $rowCls }}">
                        <td class="{{ $cellCls }}">
                            @if ($linkRows && filled($row['category_code'] ?? null))
                                <a href="{{ route('admin.expenses.index', [
                                    'from' => $meta['from'] ?? null,
                                    'to' => $meta['to'] ?? null,
                                    'status' => 'approved',
                                ]) }}" class="text-brand-700 hover:underline dark:text-brand-300">{{ $row['category'] }}</a>
                            @else
                                {{ $row['category'] }}
                            @endif
                        </td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }}">{{ $row['entries'] }}</td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($row['amount']) }}</td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['muted'] }}">{{ $share }}%</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="{{ $print ? 'total' : 'border-t-2 border-slate-900 dark:border-slate-200' }}">
                    <td class="{{ $cellCls }} {{ $cls['strong'] }}">Total approved</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }}">{{ $result->totals['entries'] ?? 0 }}</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($result->totals['amount'] ?? '0.00') }}</td>
                    <td class="{{ $cellCls }}"></td>
                </tr>
            </tfoot>
        </table>

        @if ((int) ($meta['pending_count'] ?? 0) > 0)
            <p class="{{ $print ? 'muted tiny' : 'mt-3 text-xs text-slate-500 dark:text-slate-400' }}">
                Excluded: {{ $meta['pending_count'] }}
                {{ \Illuminate\Support\Str::plural('claim', (int) $meta['pending_count']) }}
                totalling {{ money($meta['pending_amount'] ?? '0.00') }} still waiting for approval.
                Money awaiting a decision is real, and including it would make this total move every
                time somebody typed a number.
            </p>
        @endif
        @break

    {{-- --------------------------------------------------------- Profit and loss --}}
    @case (\App\Enums\FinanceReportType::ProfitLoss)
        <table class="{{ $cls['table'] }}">
            <tbody>
                @foreach ($result->rows as $row)
                    <tr class="{{ $rowCls }} {{ ($row['is_memo'] ?? false) && ! $print ? 'text-slate-500 dark:text-slate-400' : '' }}">
                        <td class="{{ $cellCls }} {{ $print ? 'muted' : 'text-xs text-slate-400' }}" style="width:3rem;">
                            {{ $row['block'] }}
                        </td>
                        <td class="{{ $cellCls }} {{ ($row['is_memo'] ?? false) ? '' : $cls['strong'] }}">
                            {{ $row['label'] }}
                        </td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ ($row['is_memo'] ?? false) ? $cls['muted'] : $cls['strong'] }}">
                            {{ money($row['amount']) }}
                        </td>
                    </tr>
                @endforeach

                <tr class="{{ $print ? 'total' : 'border-t-2 border-slate-900 dark:border-slate-200' }}">
                    <td class="{{ $cellCls }}"></td>
                    <td class="{{ $cellCls }} {{ $cls['strong'] }}">Net profit</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">
                        {{ money($result->totals['net_profit'] ?? '0.00') }}
                    </td>
                </tr>

                <tr class="{{ $rowCls }}">
                    <td class="{{ $cellCls }}"></td>
                    <td class="{{ $cellCls }} {{ $cls['muted'] }}">Commission accrued this period (memo)</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['muted'] }}">
                        {{ money($meta['commission_accrued_memo'] ?? '0.00') }}
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="{{ $print ? 'muted tiny' : 'mt-3 text-xs text-slate-500 dark:text-slate-400' }}">
            The memo line is what the business <em>became liable for</em> this period, which is a different
            question from what it paid out — it is deliberately in neither bottom line.
            {{ $meta['note'] ?? '' }}
        </p>

        @if (! $print && ($result->groups['income'] ?? []) !== [])
            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <div>
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Block A — income
                    </h4>
                    <table class="w-full text-sm">
                        @foreach ($result->groups['income'] as $row)
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-1.5">{{ $row['source'] }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ money($row['amount']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                <div>
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Block B — expenses
                    </h4>
                    <table class="w-full text-sm">
                        @forelse ($result->groups['expenses'] as $row)
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <td class="px-3 py-1.5">{{ $row['category'] }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-1.5 text-slate-500 dark:text-slate-400">Nothing approved in this range.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        @endif
        @break

    {{-- -------------------------------------------------------- Receivables aging --}}
    @case (\App\Enums\FinanceReportType::ReceivablesAging)
        <table class="{{ $cls['table'] }}">
            <thead>
                <tr>
                    <th class="{{ $headCls }}">Client</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Outstanding</th>
                    @foreach (\App\Enums\AgingBucket::cases() as $bucket)
                        <th class="{{ $headCls }} {{ $cls['num'] }}">{{ $bucket->label() }}</th>
                    @endforeach
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Credits</th>
                    <th class="{{ $headCls }} {{ $cls['num'] }}">Net exposure</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    <tr class="{{ $rowCls }}">
                        <td class="{{ $cellCls }}">
                            @if ($linkRows)
                                <a href="{{ route('admin.invoices.index', ['client' => $row['client_id'], 'outstanding' => 1, 'preset' => 'year']) }}"
                                   class="{{ $cls['strong'] }} hover:underline">{{ $row['client'] }}</a>
                            @else
                                <span class="{{ $cls['strong'] }}">{{ $row['client'] }}</span>
                            @endif
                            <span class="{{ $print ? 'muted tiny' : 'block text-xs text-slate-500 dark:text-slate-400' }}">
                                {{ $row['invoices'] }} {{ \Illuminate\Support\Str::plural('invoice', (int) $row['invoices']) }}
                                · oldest due {{ app_date($row['oldest_due']) }}
                                @if (! empty($row['last_payment']))
                                    · last paid {{ app_date($row['last_payment']) }}
                                @endif
                            </span>
                        </td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($row['outstanding']) }}</td>
                        @foreach (\App\Enums\AgingBucket::cases() as $bucket)
                            <td class="{{ $cellCls }} {{ $cls['num'] }} {{ bccomp((string) $row[$bucket->value], '0.00', 2) === 1 ? '' : $cls['muted'] }}">
                                {{ bccomp((string) $row[$bucket->value], '0.00', 2) === 1 ? money($row[$bucket->value], false) : '—' }}
                            </td>
                        @endforeach
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['muted'] }}">
                            {{ bccomp((string) $row['unapplied_credits'], '0.00', 2) === 1 ? money($row['unapplied_credits'], false) : '—' }}
                        </td>
                        <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($row['net_exposure']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="{{ $print ? 'total' : 'border-t-2 border-slate-900 dark:border-slate-200' }}">
                    <td class="{{ $cellCls }} {{ $cls['strong'] }}">
                        {{ $result->totals['invoices'] ?? 0 }} outstanding
                        {{ \Illuminate\Support\Str::plural('invoice', (int) ($result->totals['invoices'] ?? 0)) }}
                    </td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($result->totals['outstanding'] ?? '0.00') }}</td>
                    @foreach (\App\Enums\AgingBucket::cases() as $bucket)
                        <td class="{{ $cellCls }} {{ $cls['num'] }}">{{ money($result->totals[$bucket->value] ?? '0.00', false) }}</td>
                    @endforeach
                    <td class="{{ $cellCls }} {{ $cls['num'] }}">{{ money($result->totals['unapplied_credits'] ?? '0.00', false) }}</td>
                    <td class="{{ $cellCls }} {{ $cls['num'] }} {{ $cls['strong'] }}">{{ money($result->totals['net_exposure'] ?? '0.00') }}</td>
                </tr>
            </tfoot>
        </table>

        <p class="{{ $print ? 'muted tiny' : 'mt-3 text-xs text-slate-500 dark:text-slate-400' }}">
            Credits are money this client has already paid that nobody has attached to an invoice.
            Showing them beside the debt is what stops this report overstating what the business is owed.
        </p>
        @break

@endswitch
