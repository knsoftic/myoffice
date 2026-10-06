{{--
    The printed Advanced Report — admin.advanced-reports.print (D177).

    On the one letterhead (`layouts.print`), landscape because sixteen columns do not fit portrait,
    and printing itself once it has loaded. The heading block on the right of the letterhead lists the
    active filters (`AdvancedReportFilters::describe()`), the row count and when it was produced. It renders the same data array as the PDF
    (`AdvancedReportExporter::document()`) through the same partials, so the paper copy and the PDF
    cannot drift; and that data comes from the same filters and query as the screen, so the sheet
    holds exactly the rows the screen was filtered to.

    Capped at AdvancedStudentReportService::PRINT_MAX_ROWS rows: above that the controller sends the
    viewer back to the screen with a warning rather than handing a browser sixty pages.

    Variables: $title, $subtitle, $filters, $criteria, $headers, $numericColumns, $rows, $rowCount,
               $summary, $canSeeMoney, $omitted, $generatedAt, $generatedBy, $asPdf, $backUrl, $backLabel.
--}}

@extends('layouts.print')

@section('title', $title.' — '.$filters->periodLabel())

@push('print-styles')
    <style>
        @@page { size: A4 landscape; margin: 10mm 9mm 12mm; }
        .sheet { width: 277mm; padding: 12mm 10mm; }
        table.doc.report th, table.doc.report td { padding: 4px 5px; font-size: 10px; }
        table.doc.report thead th { font-size: 8.5px; }
        table.doc.summary td { border-bottom: 0; padding: 4px 8px 4px 0; }
        @@media print {
            .sheet { width: auto; padding: 0; }
        }
    </style>
@endpush

@section('document')
    <h2>{{ $title }}</h2>
    <div class="muted tiny">{{ $subtitle }}</div>
    @include('admin.advanced-reports._report-criteria')
@endsection

@section('content')
    <h3 class="section">Summary</h3>
    @include('admin.advanced-reports._report-summary')

    <h3 class="section">Students — {{ app_number($rowCount) }} {{ $rowCount === 1 ? 'row' : 'rows' }}</h3>
    @include('admin.advanced-reports._report-table')

    @unless ($asPdf ?? false)
        <script nonce="{{ csp_nonce() }}">window.addEventListener('load', () => window.print());</script>
    @endunless
@endsection

@section('footer')
    <div>{{ $title }} · {{ $filters->periodLabel() }} · {{ app_number($rowCount) }} {{ $rowCount === 1 ? 'row' : 'rows' }}</div>
    <div>Generated {{ app_datetime($generatedAt) }} by {{ $generatedBy }}. Figures are as at that moment.</div>
@endsection
