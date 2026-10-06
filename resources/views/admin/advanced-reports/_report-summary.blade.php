{{--
    The summary figures on the printed sheet and the PDF (D177) — the screen's cards, as a table,
    because dompdf lays out tables reliably and grids not at all.

    Money figures appear only when `AdvancedStudentReportService::summary()` returned them, which it
    does only for a viewer who may see money; the overdue count is shown to everybody. When money was
    withheld the sheet says so, naming the columns (INV-23-2): a narrower table that said nothing
    would be read as the whole report, and its missing totals as zero.

    Expects: $summary (AdvancedStudentReportService::summary()), $canSeeMoney (bool), $omitted (list).
--}}

@php
    use App\Support\Money;

    $figures = [
        ['Total students', app_number($summary['total_students'])],
        ['Active students', app_number($summary['active_students'])],
        ['Completed students', app_number($summary['completed_students'])],
        ['Total enrolled', app_number($summary['total_enrolled']).' admissions'],
    ];

    if ($canSeeMoney && array_key_exists('total_fees', $summary)) {
        $remaining = $summary['total_remaining'];

        $figures[] = ['Total fees', money($summary['total_fees'])];
        $figures[] = ['Total paid', money($summary['total_paid'])];
        $figures[] = ['Total remaining', Money::isNegative($remaining) ? money(Money::abs($remaining)).' in advance' : money($remaining)];
        $figures[] = ['Overdue payments', money($summary['overdue_amount']).' · '.app_number($summary['overdue_count']).' admissions'];
    } else {
        $figures[] = ['Overdue payments', app_number($summary['overdue_count']).' admissions'];
    }
@endphp

<table class="doc summary">
    <tbody>
        @foreach (array_chunk($figures, 4) as $line)
            <tr>
                @foreach ($line as [$label, $value])
                    <td style="width:25%;">
                        <div class="muted tiny">{{ $label }}</div>
                        <div class="strong">{{ $value }}</div>
                    </td>
                @endforeach
                @for ($pad = count($line); $pad < 4; $pad++)
                    <td style="width:25%;"></td>
                @endfor
            </tr>
        @endforeach
    </tbody>
</table>

@if ($omitted !== [])
    <div class="panel tiny">
        <span class="strong">Not included:</span> {{ implode(', ', $omitted) }} — the person who produced this
        copy may not see fee amounts, so those columns and the money totals are left out, not zero.
    </div>
@endif
