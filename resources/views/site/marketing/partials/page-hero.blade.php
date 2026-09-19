{{--
    The banner of a Phase 4 public page — breadcrumb trail, the page's single <h1>, a standfirst (phase-04 §8.11,
    phase-03 §8.14 "a page has exactly one <h1>"). Styled to match site/pages/partials/page.

    @include('site.marketing.partials.page-hero', [
        'title' => 'Services',
        'subtitle' => 'What we build, and what it costs to start.',
        'crumbs' => [['label' => 'Blog', 'url' => route('site.blog.index')]],   // between Home and the current page
        'eyebrow' => null,                                                     // small line above the title
        'current' => null,                                                     // breadcrumb label of this page (default: title)
    ])
--}}

@php
    $homeUrl = \Illuminate\Support\Facades\Route::has('site.home') ? route('site.home') : url('/');
    $crumbs = collect($crumbs ?? [])->filter(static fn ($crumb): bool => filled($crumb['label'] ?? null))->values();
    $subtitle = trim((string) ($subtitle ?? ''));
@endphp

<section class="relative isolate overflow-hidden border-b border-slate-200/80 bg-slate-50 dark:border-white/10 dark:bg-slate-900/40" aria-labelledby="page-title">
    <div class="pointer-events-none absolute -top-32 right-0 -z-10 h-72 w-[40rem] rounded-full bg-brand-400/15 blur-3xl dark:bg-brand-600/10" aria-hidden="true"></div>

    <div class="mx-auto max-w-screen-xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8 lg:py-20">
        <nav aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                <li>
                    <a href="{{ $homeUrl }}" class="inline-flex items-center gap-1.5 rounded transition hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:hover:text-white">
                        <x-ui.icon name="home" class="h-4 w-4" />
                        <span>Home</span>
                    </a>
                </li>
                @foreach ($crumbs as $crumb)
                    <li aria-hidden="true"><x-ui.icon name="chevron-right" class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" /></li>
                    <li>
                        @if (filled($crumb['url'] ?? null))
                            <a href="{{ $crumb['url'] }}" class="rounded transition hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:hover:text-white">{{ $crumb['label'] }}</a>
                        @else
                            <span>{{ $crumb['label'] }}</span>
                        @endif
                    </li>
                @endforeach
                <li aria-hidden="true"><x-ui.icon name="chevron-right" class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" /></li>
                <li><span aria-current="page" class="font-medium text-slate-700 dark:text-slate-200">{{ \Illuminate\Support\Str::limit((string) ($current ?? $title), 60) }}</span></li>
            </ol>
        </nav>

        @if (filled($eyebrow ?? null))
            <p class="mt-6 text-xs font-semibold uppercase tracking-[0.14em] text-brand-600 dark:text-brand-400">{{ $eyebrow }}</p>
        @endif

        <h1 id="page-title" @class([
            'max-w-4xl text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white',
            'mt-6' => blank($eyebrow ?? null),
            'mt-2' => filled($eyebrow ?? null),
        ])>{{ $title }}</h1>

        @if ($subtitle !== '')
            <p class="mt-5 max-w-2xl text-pretty text-lg leading-relaxed text-slate-600 sm:text-xl dark:text-slate-300">{{ $subtitle }}</p>
        @endif
    </div>
</section>
