{{--
    The public "page not found" page (phase-03 §8.14) — header and footer intact, so an unknown slug,
    an unpublished page or a reserved first segment still looks like the company's site.

    Receives:
      $site  optional SitePayload with `sections` empty (header, footer and seo when the controller has
             them). With no payload at all — rendered from the exception handler — the layout falls
             back to the brand and footer built from settings.

    The HTTP status (404) is set by whoever renders this view; the view marks itself noindex. It is a
    search-free but menu-rich dead end: a way home, a way to write to the company, and the header
    navigation laid out as a list of places to go.
--}}

@extends('site.layouts.public')

@section('title', 'Page not found')
@section('robots', 'noindex, nofollow')

@section('content')
    @php
        $homeUrl = \Illuminate\Support\Facades\Route::has('site.home') ? route('site.home') : url('/');

        $email = trim((string) site_setting('contact.email', ''));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;

        $destinations = collect(data_get($site ?? null, 'header.menus.menu_ref.items', []))
            ->flatMap(static fn ($node): array => array_merge([$node], (array) data_get($node, 'children', [])))
            ->filter(static fn ($node): bool => filled(data_get($node, 'label'))
                && (string) data_get($node, 'visibility', 'all') !== 'auth'
                && (string) data_get($node, 'link_type', '') !== 'section_anchor'
                && preg_match('/^(https?:\/\/|\/)/i', (string) data_get($node, 'url', '')) === 1)
            ->unique(static fn ($node): string => (string) data_get($node, 'url'))
            ->take(8)
            ->values();
    @endphp

    <x-site.section background="brand" padding="loose">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-brand-600 dark:text-brand-400">Error 404</p>
            <h1 class="mt-4 text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">We can’t find that page</h1>
            <p class="mx-auto mt-6 max-w-xl text-pretty text-lg leading-relaxed text-slate-600 dark:text-slate-300">
                The link may be out of date, or the page may have moved. Everything else is where you left it.
            </p>

            <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-site.button label="Back to the home page" :url="$homeUrl" style="primary" icon="home" size="lg" />
                @if ($email !== null)
                    <x-site.button :label="'Email '.$email" :url="'mailto:'.$email" style="outline" icon="envelope" size="lg" />
                @endif
            </div>
        </div>

        @if ($destinations->isNotEmpty())
            <nav aria-labelledby="not-found-destinations" class="mx-auto mt-20 max-w-4xl">
                <h2 id="not-found-destinations" class="text-center text-sm font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Or try one of these</h2>

                <ul role="list" class="mt-8 grid gap-3 sm:grid-cols-2">
                    @foreach ($destinations as $node)
                        <li>
                            <a
                                href="{{ data_get($node, 'url') }}"
                                @if (data_get($node, 'new_tab')) target="_blank" rel="noopener noreferrer" @endif
                                class="group flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-5 py-4 text-sm font-semibold text-slate-900 shadow-card transition duration-150 hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-card-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:hover:border-brand-500/50"
                            >
                                <span>{{ data_get($node, 'label') }}</span>
                                <x-ui.icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-brand-600 dark:group-hover:text-brand-400" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </x-site.section>
@endsection
