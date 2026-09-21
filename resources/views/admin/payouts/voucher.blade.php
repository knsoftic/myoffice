<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payout->payout_no }} — payout voucher</title>

    {{-- A voucher is paper. It carries its own styles so it prints identically from any machine,
         and it deliberately does not load the app shell: no sidebar, no theme toggle, nothing that
         would end up in the printer tray. --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 28px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 13px;
            line-height: 1.5;
            color: #0f172a;
            background: #fff;
        }
        h1 { margin: 0; font-size: 19px; }
        h2 { margin: 24px 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
                border-bottom: 2px solid #0f172a; padding-bottom: 14px; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .total { font-size: 22px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 16px; }
        .sign { margin-top: 48px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
        .sign div { border-top: 1px solid #0f172a; padding-top: 6px; font-size: 11px; color: #475569; }
        .note { margin-top: 24px; font-size: 11px; color: #64748b; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="head">
        <div>
            <h1>{{ setting('company.name', config('app.name')) }}</h1>
            <div class="muted">
                {{ setting('contact.address') }}<br>
                {{ setting('contact.phone') }} @if (setting('contact.email')) · {{ setting('contact.email') }} @endif
            </div>
        </div>
        <div class="right">
            <h1>Payout voucher</h1>
            <div class="muted">{{ $payout->payout_no }}</div>
            <div class="muted">{{ $payout->status->label() }}</div>
        </div>
    </div>

    <div class="grid">
        <div>
            <h2>Paid to</h2>
            <strong>{{ $payout->collaborator?->displayName() }}</strong><br>
            <span class="muted">{{ $payout->collaborator?->collaborator_code }}</span><br>
            <span class="muted">{{ $payout->maskedAccount() }}</span>
        </div>
        <div class="right">
            <h2>Amount</h2>
            <div class="total">{{ money($payout->amount) }}</div>
            <div class="muted">
                {{ $payout->method->label() }}
                @if (filled($payout->transaction_id)) · {{ $payout->transaction_id }} @endif
            </div>
            <div class="muted">
                @if ($payout->paid_on)
                    Value date {{ app_date($payout->paid_on) }}
                @else
                    Not yet paid
                @endif
            </div>
        </div>
    </div>

    @if ($payout->statement_from !== null)
        <p class="muted">Settles commission from {{ app_date($payout->statement_from) }} to {{ app_date($payout->statement_to) }}.</p>
    @endif

    <h2>Commissions settled</h2>
    <table>
        <thead>
            <tr>
                <th>Entry</th>
                <th>Dated</th>
                <th class="num">Entry amount</th>
                <th class="num">Settled here</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payout->allocations as $allocation)
                <tr>
                    <td>{{ $allocation->entry?->reference ?? 'CLE-' . $allocation->ledger_entry_id }}</td>
                    <td>{{ app_date($allocation->entry_transaction_date) }}</td>
                    <td class="num">{{ $allocation->entry ? money($allocation->entry->amount) : '—' }}</td>
                    <td class="num">{{ money($allocation->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No live claims.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" class="num">Total</th>
                <th class="num">{{ money($payout->amount) }}</th>
            </tr>
        </tfoot>
    </table>

    <div class="sign">
        <div>Prepared by{{ $payout->requestedBy ? ' — ' . $payout->requestedBy->name : '' }}</div>
        <div>Approved by{{ $payout->approvedBy ? ' — ' . $payout->approvedBy->name : '' }}</div>
        <div>Received by</div>
    </div>

    <p class="note">
        Printed {{ app_datetime(now()) }}. Each line above names the commission entry this payment settles, so the
        figure can be traced back to the receipt that earned it.
    </p>
</body>
</html>
