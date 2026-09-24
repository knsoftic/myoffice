<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $result->meta['report_title'] ?? 'Report' }}</title>

    {{--
        The printed page - phase-19-23 6.21.

        **The same Blade renders the print view and the PDF.** A PDF that had drifted from the
        printed page would be a second document claiming to be the first, and nobody would notice
        until two people compared printouts.

        Styles are inline because dompdf does not fetch stylesheets, and because a printed report
        that arrived unstyled because an asset URL moved is a report somebody reprints at cost.
    --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .sub { color: #64748b; font-size: 11px; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
             color: #475569; border-bottom: 1px solid #cbd5e1; padding: 6px 4px; }
        td { padding: 5px 4px; border-bottom: 1px solid #f1f5f9; }
        tr { page-break-inside: avoid; }
        .r { text-align: right; }
        .c { text-align: center; }
        tfoot td { border-top: 2px solid #cbd5e1; font-weight: 700; }
        .meta { margin: 0 0 14px; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .meta div { margin: 2px 0; }
        .warn { margin: 0 0 12px; padding: 8px 10px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        footer { margin-top: 18px; color: #94a3b8; font-size: 10px; }
    </style>
</head>
<body>
@php
    $meta = $result->meta;
    $columns = $meta['columns'] ?? [];
    $omitted = $meta['omitted_columns'] ?? [];
@endphp

<h1>{{ $meta['report_title'] ?? 'Report' }}</h1>
<p class="sub">{{ setting('company.name', config('app.name')) }}</p>

{{-- What this page covered. The single most important block on a printed report: six months later
     it is the only thing that says what the numbers were about. --}}
<div class="meta">
    <div><strong>Period:</strong> {{ $meta['range_label'] ?? '' }} ({{ $meta['from'] ?? '' }} to {{ $meta['to'] ?? '' }})</div>

    @if (! empty($meta['date_label']))
        <div><strong>Measured on:</strong> {{ $meta['date_label'] }}</div>
    @endif

    @if (! empty($meta['basis']))
        <div><strong>Basis:</strong> {{ $meta['basis'] }}</div>
    @endif

    @if (! empty($meta['filters']))
        <div><strong>Filters:</strong>
            @foreach ($meta['filters'] as $key => $value)
                {{ str_replace('_', ' ', (string) $key) }} = {{ is_array($value) ? implode(', ', $value) : $value }}@if (! $loop->last); @endif
            @endforeach
        </div>
    @endif

    <div><strong>Rows:</strong> {{ app_number((int) ($meta['row_count'] ?? 0)) }}</div>
</div>

@if ($omitted !== [])
    {{-- INV-23-2 travels onto the paper: a narrower table that said nothing would be read as the
         whole report, and its totals as complete totals. --}}
    <div class="warn">
        <strong>Not shown:</strong> {{ implode(', ', $omitted) }}.
        The person who printed this does not have permission to see those columns, and the totals
        below cover only what is here.
    </div>
@endif

@if (($meta['available'] ?? true) === false)
    <p>{{ $meta['reason'] ?? 'This report is not available.' }}</p>
@elseif ($result->rows === [])
    <p>Nothing fell inside this period with these filters.</p>
@else
    <table>
        <thead>
        <tr>
            @foreach ($columns as $column)
                <th class="{{ ($column['align'] ?? 'left') === 'right' ? 'r' : (($column['align'] ?? '') === 'center' ? 'c' : '') }}">
                    {{ $column['label'] }}
                </th>
            @endforeach
        </tr>
        </thead>

        <tbody>
        @foreach ($result->rows as $row)
            <tr>
                @foreach ($columns as $column)
                    @php($value = $row[$column['key']] ?? null)
                    <td class="{{ ($column['align'] ?? 'left') === 'right' ? 'r' : (($column['align'] ?? '') === 'center' ? 'c' : '') }}">
                        @if ($value === null || $value === '')
                            &mdash;
                        @elseif (($column['type'] ?? '') === 'money')
                            {{ money((string) $value) }}
                        @elseif (($column['type'] ?? '') === 'percent')
                            {{ app_number((float) $value, 2) }}%
                        @elseif (($column['type'] ?? '') === 'number')
                            {{ app_number((float) $value) }}
                        @else
                            {{ $value }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>

        @if ($result->totals !== [])
            <tfoot>
            <tr>
                @foreach ($columns as $index => $column)
                    @php($total = $result->totals[$column['key']] ?? null)
                    <td class="{{ ($column['align'] ?? 'left') === 'right' ? 'r' : (($column['align'] ?? '') === 'center' ? 'c' : '') }}">
                        @if ($index === 0)
                            Total
                        @elseif ($total !== null)
                            @if (($column['type'] ?? '') === 'money')
                                {{ money((string) $total) }}
                            @elseif (($column['type'] ?? '') === 'percent')
                                {{ app_number((float) $total, 2) }}%
                            @else
                                {{ app_number((float) $total) }}
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
            </tfoot>
        @endif
    </table>
@endif

<footer>
    Generated {{ $meta['generated_at'] ?? '' }}
    @auth by {{ auth()->user()->name }} @endauth
</footer>

@if (! ($asPdf ?? false))
    <script>window.addEventListener('load', () => window.print());</script>
@endif
</body>
</html>
