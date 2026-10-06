{{--
    The Advanced Report as a PDF — rendered by dompdf in AdvancedReportExporter::pdf() (D177).

    Standalone and self-contained on purpose: dompdf has no bundler and no network (remote fetching is
    forced off for the render), so the stylesheet is inline, there is no script, no web font and no
    image URL. A4 landscape — sixteen columns do not fit portrait — set both here and on the renderer.

    It renders the same data array as the printed sheet (`AdvancedReportExporter::document()`) through
    the same three partials, so the PDF and the paper copy cannot disagree; the class names below
    (`table.doc`, `.num`, `.muted`, `.tiny`, `.strong`, `h3.section`) are the print layout's, restyled
    for a denser page. The heading block on the right lists the active filters, as the printed
    sheet's does.

    Variables: $title, $subtitle, $filters, $criteria, $headers, $numericColumns, $rows, $rowCount,
               $summary, $canSeeMoney, $omitted, $generatedAt, $generatedBy.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $filters->periodLabel() }}</title>

    <style>
        @@page { size: A4 landscape; margin: 10mm 9mm 12mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: #0f172a;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8.5px;
            line-height: 1.35;
        }

        .letterhead { display: table; width: 100%; border-bottom: 2px solid #0f172a; padding-bottom: 6px; }
        .letterhead > div { display: table-cell; vertical-align: top; }
        .letterhead .document { text-align: right; }
        .letterhead h1 { margin: 0 0 2px; font-size: 13px; }
        .letterhead h2 { margin: 0 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; }

        .muted { color: #64748b; }
        .tiny { font-size: 7.5px; }
        .strong { font-weight: bold; }

        h3.section {
            margin: 10px 0 4px;
            font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .08em;
            color: #64748b;
        }

        table.doc { width: 100%; border-collapse: collapse; }
        table.doc thead { display: table-header-group; }
        table.doc th, table.doc td {
            padding: 3px 4px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top;
        }
        table.doc thead th {
            font-size: 6.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748b;
            background: #f8fafc; border-bottom: 1px solid #cbd5e1;
        }
        table.doc .num { text-align: right; white-space: nowrap; }
        table.doc tbody tr { page-break-inside: avoid; }
        table.doc.summary td { border-bottom: 0; padding: 3px 8px 3px 0; }

        .panel { margin-top: 6px; padding: 5px 7px; border: 1px solid #e2e8f0; background: #f8fafc; }

        .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #e2e8f0; font-size: 7.5px; color: #64748b; }
    </style>
</head>
<body>
    <div class="letterhead">
        <div>
            <h1>{{ setting('company.legal_name') ?: setting('company.name', config('app.name')) }}</h1>
            <div class="muted tiny">{{ $subtitle }}</div>
        </div>
        <div class="document">
            <h2>{{ $title }}</h2>
            @include('admin.advanced-reports._report-criteria')
        </div>
    </div>

    <h3 class="section">Summary</h3>
    @include('admin.advanced-reports._report-summary')

    <h3 class="section">Students — {{ app_number($rowCount) }} {{ $rowCount === 1 ? 'row' : 'rows' }}</h3>
    @include('admin.advanced-reports._report-table')

    <div class="footer">
        {{ $title }} · {{ $filters->periodLabel() }} · {{ app_number($rowCount) }} {{ $rowCount === 1 ? 'row' : 'rows' }}.
        Generated {{ app_datetime($generatedAt) }} by {{ $generatedBy }}; figures are as at that moment.
    </div>
</body>
</html>
