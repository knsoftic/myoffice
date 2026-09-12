@props([
    'paginator' => null,
    'label' => 'results',
    'links' => true,
])

{{--
    x-ui.pagination-summary — "Showing 1–25 of 340 users" plus the page links.

        <x-slot:footer>
            <x-ui.pagination-summary :paginator="$users" label="users" />
        </x-slot:footer>

    The links are rendered here rather than through $paginator->links() so they follow the
    brand palette and stay dark-mode correct, and so the count is not printed twice.
    Works with LengthAwarePaginator and with simplePaginate (which has no total).
--}}

@php
    $hasTotal = $paginator && method_exists($paginator, 'total');
    $from = $paginator?->firstItem();
    $to = $paginator?->lastItem();
    $total = $hasTotal ? $paginator->total() : null;

    if ($paginator && $links) {
        $paginator = $paginator->withQueryString();
    }

    // linkCollection() yields Previous, the numbered pages (with '...' separators), then Next.
    // We render prev/next ourselves, so drop the first and last entries.
    $pages = collect();

    if ($paginator && $links && method_exists($paginator, 'linkCollection')) {
        $all = $paginator->linkCollection();
        $pages = $all->slice(1, max(0, $all->count() - 2))->values();
    }

    $pageBase = 'inline-flex h-8 min-w-[2rem] items-center justify-center rounded-lg px-2 text-xs font-medium tabular-nums transition-colors';
    $pageIdle = 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white';
    $pageOn = 'bg-brand-600 text-white shadow-sm dark:bg-brand-500';
    $arrow = 'inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white';
    $arrowOff = 'inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-300 dark:border-slate-800 dark:bg-slate-900/50 dark:text-slate-700';
@endphp

@if ($paginator)
    <div {{ $attributes->class('flex flex-col items-center justify-between gap-3 sm:flex-row') }}>
        <p class="text-xs text-slate-500 tabular-nums dark:text-slate-400">
            @if ($total === 0 || $from === null)
                No {{ $label }} found
            @else
                Showing
                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format((int) $from) }}</span>–<span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format((int) $to) }}</span>
                @if ($hasTotal)
                    of <span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format((int) $total) }}</span>
                @endif
                {{ $label }}
            @endif
        </p>

        @if ($links && $paginator->hasPages())
            <nav class="flex items-center gap-1" aria-label="Pagination">
                @if ($paginator->onFirstPage())
                    <span class="{{ $arrowOff }}" aria-hidden="true">
                        <x-ui.icon name="chevron-left" class="h-4 w-4" />
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $arrow }}" aria-label="Previous page">
                        <x-ui.icon name="chevron-left" class="h-4 w-4" />
                    </a>
                @endif

                @foreach ($pages as $page)
                    @if ($page['url'] === null)
                        <span class="px-1 text-xs text-slate-400 dark:text-slate-600" aria-hidden="true">…</span>
                    @elseif ($page['active'])
                        <span class="{{ $pageBase }} {{ $pageOn }}" aria-current="page">{{ $page['label'] }}</span>
                    @else
                        <a href="{{ $page['url'] }}" class="{{ $pageBase }} {{ $pageIdle }}" aria-label="Page {{ $page['label'] }}">
                            {{ $page['label'] }}
                        </a>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $arrow }}" aria-label="Next page">
                        <x-ui.icon name="chevron-right" class="h-4 w-4" />
                    </a>
                @else
                    <span class="{{ $arrowOff }}" aria-hidden="true">
                        <x-ui.icon name="chevron-right" class="h-4 w-4" />
                    </span>
                @endif
            </nav>
        @endif
    </div>
@endif
