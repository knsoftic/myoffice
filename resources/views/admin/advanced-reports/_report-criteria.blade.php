{{--
    The active filters in words, for the heading block of the printed sheet and the PDF (D177).

    The most important lines on a printed report: six months later they are the only thing that says
    what the rows were about ("a printed sheet with no filter line is one nobody can reproduce"). The
    period is always stated — "All time" included — and so is the order, because two printouts of the
    same filters in a different order look like two different reports.

    Expects: $criteria (label => value, AdvancedReportFilters::describe()), $rowCount, $generatedAt.
    Styled by the including page: `.muted`, `.tiny` and `.strong` exist in both.
--}}

<div class="muted tiny" style="margin-top:6px;">
    @foreach ($criteria as $label => $value)
        <div><span class="strong">{{ $label }}:</span> {{ $value }}</div>
    @endforeach
    <div><span class="strong">Rows:</span> {{ app_number($rowCount) }} (one per student per course)</div>
    <div><span class="strong">Generated:</span> {{ app_datetime($generatedAt) }}</div>
</div>
