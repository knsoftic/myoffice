{{--
    Table View / Sheet & Analytics: the same expense register two ways. Both links carry the filters
    that are set (never the page number), so switching keeps the same expenses on screen. Both routes
    need `expenses.view_any`, so whoever sees the switch can open either side.
--}}
@php
    $switchQuery = array_filter(
        request()->only(['from', 'to', 'preset', 'q', 'category', 'context', 'status', 'mine']),
        static fn (mixed $value): bool => is_string($value) && $value !== '',
    );
@endphp

<x-ui.tabs variant="pill" class="mb-4 w-fit" :tabs="[
    [
        'label' => 'Table View',
        'url' => route('admin.expenses.index', $switchQuery),
        'icon' => 'table-cells',
        'active' => request()->routeIs('admin.expenses.index'),
    ],
    [
        'label' => 'Sheet & Analytics',
        'url' => route('admin.expense-sheet.index', $switchQuery),
        'icon' => 'chart-bar',
        'active' => request()->routeIs('admin.expense-sheet.index'),
    ],
]" />
