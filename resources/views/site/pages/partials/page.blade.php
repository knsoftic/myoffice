{{--
    The body of a custom page (route `site.page`, phase-03 §2.7, §7.6, requirement §101). Shared by the
    three allowlisted templates in site/pages/ — default, wide and legal — which differ only in measure
    and in the legal template's "last updated" line and contact aside.

    Receives:
      $site     App\Support\SitePayload; `sections` holds this page's published sections when the page
                uses the `sections` layout
      $page     the published page — the array PublicPageService builds, or a Page model selected with
                Page::PUBLIC_COLUMNS. Keys read: title, slug, layout (content | sections, enum or
                string), show_banner, banner_heading, banner_subheading, banner (media array or null),
                body (sanitised published_content; `published_content` is accepted as the fallback key),
                published_at
      $template 'default' | 'wide' | 'legal'

    One <h1> per page: the banner's heading, or — with the banner switched off — the page title at the
    top of the content. The body goes through <x-site.prose>, which sanitises it again on render
    (INV-13). A page whose layout is `sections` renders its sections through the one renderer.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $page = $page ?? data_get($site ?? null, 'page');
    $template = $template ?? 'default';
    $template = in_array($template, ['default', 'wide', 'legal'], true) ? $template : 'default';

    $title = trim((string) data_get($page, 'title', ''));
    $bannerHeading = trim((string) (data_get($page, 'banner_heading') ?: $title));
    $bannerSubheading = trim((string) data_get($page, 'banner_subheading', ''));
    $showBanner = (bool) data_get($page, 'show_banner', true);

    $banner = data_get($page, 'banner');
    $banner = is_array($banner) && filled($banner['url'] ?? null) ? array_merge($banner, ['alt' => '']) : null;

    $layout = data_get($page, 'layout');
    $layout = $layout instanceof \BackedEnum ? $layout->value : (string) $layout;
    $usesSections = $layout === 'sections';

    $body = data_get($page, 'body') ?? data_get($page, 'published_content');
    $hasBody = is_string($body) && (trim(strip_tags($body)) !== '' || preg_match('/<(?:img|iframe)/i', $body) === 1);

    $pageSections = collect(data_get($site ?? null, 'sections', []))->values();

    $publishedAt = data_get($page, 'published_at');
    $updatedOn = filled($publishedAt) ? app_date($publishedAt) : '';

    $homeUrl = Route::has('site.home') ? route('site.home') : url('/');

    $email = trim((string) site_setting('contact.email', ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    $phone = trim((string) site_setting('contact.phone', ''));
    $phoneHref = $phone !== '' ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;

    $measure = match ($template) {
        'wide' => 'max-w-5xl',
        default => 'max-w-3xl',
    };
@endphp

@if ($showBanner)
    <section @class([
        'relative isolate overflow-hidden',
        'dark' => $banner !== null,
    ]) aria-labelledby="page-title">
        <div @class([
            'relative',
            'bg-slate-950' => $banner !== null,
            'border-b border-slate-200/80 bg-slate-50 dark:border-white/10 dark:bg-slate-900/40' => $banner === null,
        ])>
            @if ($banner !== null)
                <div class="absolute inset-0 -z-10" aria-hidden="true">
                    <x-site.image :media="$banner" profile="banner" :eager="true" class="absolute inset-0 h-full w-full" />
                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/60 to-slate-950/40"></div>
                </div>
            @else
                <div class="pointer-events-none absolute -top-32 right-0 -z-10 h-72 w-[40rem] rounded-full bg-brand-400/15 blur-3xl dark:bg-brand-600/10" aria-hidden="true"></div>
            @endif

            <div @class([
                'mx-auto max-w-screen-xl px-4 sm:px-6 lg:px-8',
                'pb-16 pt-24 sm:pb-20 sm:pt-32 lg:pb-24 lg:pt-40' => $banner !== null,
                'py-12 sm:py-16 lg:py-20' => $banner === null,
            ])>
                <nav aria-label="Breadcrumb">
                    <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                        <li>
                            <a href="{{ $homeUrl }}" class="inline-flex items-center gap-1.5 rounded transition hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:hover:text-white">
                                <x-ui.icon name="home" class="h-4 w-4" />
                                <span>Home</span>
                            </a>
                        </li>
                        <li aria-hidden="true"><x-ui.icon name="chevron-right" class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" /></li>
                        <li><span aria-current="page" class="font-medium text-slate-700 dark:text-slate-200">{{ $title }}</span></li>
                    </ol>
                </nav>

                <h1 id="page-title" class="mt-6 max-w-4xl text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">{{ $bannerHeading }}</h1>

                @if ($bannerSubheading !== '')
                    <p class="mt-5 max-w-2xl text-pretty text-lg leading-relaxed text-slate-600 sm:text-xl dark:text-slate-300">{{ $bannerSubheading }}</p>
                @endif

                @if ($template === 'legal' && $updatedOn !== '')
                    <p class="mt-6 inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="clock" class="h-4 w-4" />
                        <span>Last updated <time datetime="{{ app_date($publishedAt, 'Y-m-d') }}">{{ $updatedOn }}</time></span>
                    </p>
                @endif
            </div>
        </div>
    </section>
@endif

@if ($usesSections)
    @unless ($showBanner)
        <h1 class="sr-only">{{ $title }}</h1>
    @endunless

    @include('site.partials.sections', ['sections' => $pageSections])
@else
    <div class="bg-white dark:bg-slate-950">
        <div @class([
            'mx-auto px-4 py-14 sm:px-6 sm:py-20 lg:px-8',
            'max-w-screen-xl' => $template === 'legal',
            $measure => $template !== 'legal',
        ])>
            <div @class(['lg:grid lg:grid-cols-12 lg:gap-16' => $template === 'legal'])>
                <article @class([
                    'lg:col-span-8' => $template === 'legal',
                ])>
                    @unless ($showBanner)
                        <h1 id="page-title" class="text-balance text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">{{ $title }}</h1>

                        @if ($template === 'legal' && $updatedOn !== '')
                            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Last updated {{ $updatedOn }}</p>
                        @endif
                    @endunless

                    @if ($hasBody)
                        <x-site.prose
                            :html="$body"
                            :size="$template === 'legal' ? 'default' : 'lg'"
                            @class(['mt-8' => ! $showBanner])
                        />
                    @else
                        <p @class(['text-base text-slate-500 dark:text-slate-400', 'mt-8' => ! $showBanner])>This page has no content yet.</p>
                    @endif
                </article>

                @if ($template === 'legal' && ($email !== null || $phoneHref !== null))
                    <aside class="mt-14 lg:col-span-4 lg:mt-0" aria-labelledby="page-questions">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6 lg:sticky lg:top-28 dark:border-white/10 dark:bg-white/[0.03]">
                            <h2 id="page-questions" class="text-base font-semibold text-slate-900 dark:text-white">Questions about this policy?</h2>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Get in touch and we will explain anything that is unclear.</p>

                            <ul role="list" class="mt-5 space-y-3 text-sm">
                                @if ($email !== null)
                                    <li>
                                        <a href="mailto:{{ $email }}" class="inline-flex items-center gap-2.5 break-all rounded font-medium text-brand-700 transition hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-300 dark:hover:text-brand-200">
                                            <x-ui.icon name="envelope" class="h-4 w-4 shrink-0" />
                                            <span>{{ $email }}</span>
                                        </a>
                                    </li>
                                @endif
                                @if ($phoneHref !== null)
                                    <li>
                                        <a href="{{ $phoneHref }}" class="inline-flex items-center gap-2.5 rounded font-medium text-brand-700 transition hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-300 dark:hover:text-brand-200">
                                            <x-ui.icon name="phone" class="h-4 w-4 shrink-0" />
                                            <span>{{ $phone }}</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    </aside>
                @endif
            </div>
        </div>
    </div>
@endif
