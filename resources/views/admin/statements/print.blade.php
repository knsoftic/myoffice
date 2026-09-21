<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement — {{ $collaborator->displayName() }}</title>

    {{-- The print view and the PDF are the same document. The difference is a stylesheet and a header,
         not a second layout: a PDF that drifts from the printed page is a support call waiting to
         happen, and both render the one `StatementData` the screen renders ([D-IMP-7]). --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 12px;
            line-height: 1.45;
            color: #0f172a;
            background: #fff;
        }
        h1 { margin: 0; font-size: 18px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
                border-bottom: 2px solid #0f172a; padding-bottom: 12px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        thead { display: table-header-group; }
        th, td { padding: 5px 7px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.balance td { background: #f8fafc; font-weight: 600; }
        .proof { margin-top: 16px; padding: 10px 12px; border: 1px solid #bbf7d0; background: #f0fdf4;
                 font-variant-numeric: tabular-nums; }
        .groups { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 14px; }
        .groups div { flex: 1 1 140px; border: 1px solid #e2e8f0; padding: 8px 10px; }
        .groups span { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        .groups strong { font-size: 13px; font-variant-numeric: tabular-nums; }
        .note { margin-top: 18px; font-size: 10px; color: #64748b; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body @unless ($asPdf) onload="window.print()" @endunless>
    <div class="head">
        <div>
            <h1>{{ setting('company.name', config('app.name')) }}</h1>
            <div class="muted">
                {{ setting('contact.address') }}<br>
                {{ setting('contact.phone') }} @if (setting('contact.email')) · {{ setting('contact.email') }} @endif
            </div>
        </div>
        <div class="right">
            <h1>Commission statement</h1>
            <div class="muted">{{ $collaborator->displayName() }} · {{ $collaborator->collaborator_code }}</div>
            <div class="muted">{{ app_date($range->start()) }} – {{ app_date($range->end()) }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Type</th>
                <th class="num">Credit</th>
                <th class="num">Debit</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr class="balance">
                <td>{{ app_date($range->start()) }}</td>
                <td colspan="5">Opening balance</td>
                <td class="num">{{ money($statement->opening) }}</td>
            </tr>

            @foreach ($statement->lines as $line)
                <tr>
                    <td>{{ app_date($line->date) }}</td>
                    <td>{{ $line->reference }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->purpose?->label() ?? 'Payout' }}</td>
                    <td class="num">{{ bccomp($line->credit, '0.00', 2) === 0 ? '' : money($line->credit, false) }}</td>
                    <td class="num">{{ bccomp($line->debit, '0.00', 2) === 0 ? '' : money($line->debit, false) }}</td>
                    <td class="num">{{ money($line->balance, false) }}</td>
                </tr>
            @endforeach

            <tr class="balance">
                <td>{{ app_date($range->end()) }}</td>
                <td colspan="5">Closing balance</td>
                <td class="num">{{ money($statement->closing) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($statement->isEmpty())
        <p class="muted">{{ $statement->emptyMessage() }}</p>
    @endif

    <div class="groups">
        <div><span>Student commissions</span><strong>{{ money($statement->subtotal('student_commissions'), false) }}</strong></div>
        <div><span>Project commissions</span><strong>{{ money($statement->subtotal('project_commissions'), false) }}</strong></div>
        <div><span>Adjustments</span><strong>{{ money($statement->subtotal('adjustments'), false) }}</strong></div>
        <div><span>Reversals</span><strong>{{ money($statement->subtotal('reversals'), false) }}</strong></div>
        <div><span>Payouts</span><strong>{{ money($statement->subtotal('payouts'), false) }}</strong></div>
    </div>

    <p class="proof"><strong>Proof:</strong> {{ $statement->proof() }}</p>

    <p class="note">
        Generated {{ app_datetime($generatedAt) }}.
        @if ($statement->isFiltered)
            A filter was in force: {{ collect($statement->filters->queryParameters())->map(fn ($v, $k) => $k . '=' . $v)->join(', ') }}.
            The balances are unfiltered; only the listed rows were narrowed.
        @endif
        Every figure is dated on the business date — the date money was received or paid — so this document can be
        reproduced years from now.
    </p>
</body>
</html>
