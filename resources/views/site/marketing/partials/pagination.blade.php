{{--
    Public pagination for the Phase 4 listing pages (phase-04 §8.11 "pagination preserves the query string").

    @include('site.marketing.partials.pagination', ['paginator' => $posts, 'label' => 'posts'])

    Renders nothing for a single page. Previous / next plus numbered pages from the paginator's own link
    collection (with "…" gaps), every link carrying the current query string.
--}}

@php
    $paginator = $paginator ?? null;
@endphp

@if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $paginator->hasPages())
    @php
        $paginator = $paginator->withQueryString();
        $links = $paginator->linkCollection();
        $pages = $links->slice(1, max(0, $links->count() - 2))->values();
        $base = 'inline-flex h-10 min-w-[2.5rem] items-center justify-center rounded-lg px-3 text-sm font-medium tabular-nums transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60';
    @endphp

    <nav class="mt-12 flex flex-col items-center gap-4 sm:flex-row sm:justify-between" aria-label="Pagination">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Page {{ app_number($paginator->currentPage()) }} of {{ app_number($paginator->lastPage()) }}
            <span class="text-slate-400 dark:text-slate-500">· {{ app_number($paginator->total()) }} {{ $label ?? 'results' }}</span>
        </p>

        <ul role="list" class="flex flex-wrap items-center gap-1">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="{{ $base }} cursor-not-allowed text-slate-300 dark:text-slate-600" aria-hidden="true"><x-ui.icon name="chevron-left" class="h-4 w-4" /></span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $base }} text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white">
                        <x-ui.icon name="chevron-left" class="h-4 w-4" /><span class="sr-only">Previous page</span>
                    </a>
                @endif
            </li>
            @foreach ($pages as $link)
                <li>
                    @if ($link['url'] === null)
                        <span class="px-1 text-sm text-slate-400" aria-hidden="true">…</span>
                    @elseif ($link['active'])
                        <span class="{{ $base }} bg-brand-600 text-white shadow-sm dark:bg-brand-500" aria-current="page">{{ $link['label'] }}</span>
                    @else
                        <a href="{{ $link['url'] }}" class="{{ $base }} text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Page {{ $link['label'] }}">{{ $link['label'] }}</a>
                    @endif
                </li>
            @endforeach
            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $base }} text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white">
                        <x-ui.icon name="chevron-right" class="h-4 w-4" /><span class="sr-only">Next page</span>
                    </a>
                @else
                    <span class="{{ $base }} cursor-not-allowed text-slate-300 dark:text-slate-600" aria-hidden="true"><x-ui.icon name="chevron-right" class="h-4 w-4" /></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
