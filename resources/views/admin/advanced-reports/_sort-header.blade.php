{{--
    One sortable column header of the Advanced Reports table (D177).

    The same markup and behaviour as `x-ui.th-sortable` — an active column flips, an inactive one
    starts at its declared default, `aria-sort` on the active one — with one difference that is the
    reason this partial exists: the link is built from the **sanitised** filters
    (`AdvancedReportFilters::withSort()->toQuery()`), not from the raw query string. A course the
    viewer may not filter by, a batch that does not fit the course, a money sort for a viewer without
    money or a reversed date range is corrected once by the Form Request; a sort link must not send
    the uncorrected version back. Pagination links are built the same way (`paginate()`).

    Expects: $filters (from the page), $column (sort key), $label, and optionally
             $default ('asc'|'desc', default 'asc'), $align ('left'|'right'), $numeric (bool).
--}}

@php
    $sortDefault = ($default ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $sortAlign = ($align ?? 'left') === 'right' ? 'right' : 'left';
    $sortActive = $filters->sort === $column;
    $sortCurrent = $filters->direction === 'desc' ? 'desc' : 'asc';
    $sortNext = $sortActive ? ($sortCurrent === 'asc' ? 'desc' : 'asc') : $sortDefault;
    $sortUrl = route('admin.advanced-reports.index', $filters->withSort($column, $sortNext)->toQuery());
    $sortArrow = $sortActive ? ($sortCurrent === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevron-up-down';
@endphp

<th scope="col"
    @class(['px-4 py-3', 'text-right' => $sortAlign === 'right', 'tabular-nums' => (bool) ($numeric ?? false)])
    @if ($sortActive) aria-sort="{{ $sortCurrent === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ $sortUrl }}"
       @class([
           'group inline-flex w-full items-center gap-1.5 rounded transition-colors hover:text-slate-900 dark:hover:text-white',
           'justify-end text-right' => $sortAlign === 'right',
           'justify-start text-left' => $sortAlign !== 'right',
           'text-slate-900 dark:text-white' => $sortActive,
       ])
       title="Sort by {{ $label }} ({{ $sortNext }}ending)">
        <span class="truncate">{{ $label }}</span>
        <x-ui.icon :name="$sortArrow"
                   class="h-3.5 w-3.5 shrink-0 transition-opacity {{ $sortActive ? 'text-brand-600 opacity-100 dark:text-brand-400' : 'opacity-40 group-hover:opacity-100' }}" />
    </a>
</th>
