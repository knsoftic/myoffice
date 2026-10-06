{{--
    The rows of the printed sheet and the PDF (D177) — one partial for both, so the paper copy and the
    PDF cannot disagree about a column.

    The rows are `AdvancedStudentReportService::exportRows(..., formatMoney: true)`: the same rows, the
    same order and the same columns as the CSV and the workbook, already formatted for a reader (dates,
    "Rs 12,500.00", "Rs 500.00 in advance"). Columns are the viewer's export headers, so a withheld
    money column is absent here exactly as it is everywhere else.

    Expects: $headers (list<string>), $rows (list<array<string, string>>), $numericColumns (list<string>).
--}}

<table class="doc report">
    <thead>
        <tr>
            @foreach ($headers as $header)
                <th @class(['num' => in_array($header, $numericColumns, true)])>{{ $header }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($headers as $header)
                    @php($cell = (string) ($row[$header] ?? ''))
                    <td @class(['num' => in_array($header, $numericColumns, true)])>{{ $cell !== '' ? $cell : '—' }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ max(1, count($headers)) }}" class="muted">No students match these filters.</td>
            </tr>
        @endforelse
    </tbody>
</table>
