@props([
    'column' => null,
    'sort' => null,
    'direction' => 'asc',
    'default' => 'asc',
    'align' => 'left',
    'sortKey' => 'sort',
    'directionKey' => 'direction',
    'numeric' => false,
])

{{--
    x-ui.th-sortable — a sortable column header that builds its own query string and
    preserves every other filter on the page.

        <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc">
            Joined
        </x-ui.th-sortable>

    Controller side:
        $sort = $request->string('sort', 'name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
--}}

@php
    $isActive = filled($column) && (string) $sort === (string) $column;
    $current = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';

    // Clicking an active column flips it; an inactive column starts at its declared default.
    $next = $isActive ? ($current === 'asc' ? 'desc' : 'asc') : (strtolower((string) $default) === 'desc' ? 'desc' : 'asc');

    $url = filled($column)
        ? request()->fullUrlWithQuery([$sortKey => $column, $directionKey => $next, 'page' => null])
        : null;

    $alignClass = match ($align) {
        'right' => 'justify-end text-right',
        'center' => 'justify-center text-center',
        default => 'justify-start text-left',
    };

    $arrow = $isActive ? ($current === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevron-up-down';
@endphp

<th
    scope="col"
    {{ $attributes->class([
        'px-4 py-3',
        'text-right' => $align === 'right',
        'text-center' => $align === 'center',
        'tabular-nums' => $numeric,
    ]) }}
    @if ($isActive) aria-sort="{{ $current === 'asc' ? 'ascending' : 'descending' }}" @endif
>
    @if ($url)
        <a
            href="{{ $url }}"
            class="group inline-flex w-full items-center gap-1.5 {{ $alignClass }} rounded transition-colors hover:text-slate-900 dark:hover:text-white {{ $isActive ? 'text-slate-900 dark:text-white' : '' }}"
            title="Sort by {{ strip_tags($slot->toHtml()) }} ({{ $next }}ending)"
        >
            <span class="truncate">{{ $slot }}</span>
            <x-ui.icon
                :name="$arrow"
                class="h-3.5 w-3.5 shrink-0 transition-opacity {{ $isActive ? 'text-brand-600 opacity-100 dark:text-brand-400' : 'opacity-40 group-hover:opacity-100' }}"
            />
        </a>
    @else
        <span class="inline-flex w-full items-center {{ $alignClass }}">{{ $slot }}</span>
    @endif
</th>
