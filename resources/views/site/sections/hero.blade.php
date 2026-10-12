{{--
    Section type `hero` (placement home) — requirement §9, phase-03 §6.1, §8.7.

    Receives:
      $section        the published snapshot (`anchor` is read for the section id)
      $content        heading, subtitle, description (sanitised rich text), primary_button,
                      secondary_button, show_statistics, alignment (left|center), overlay_opacity (0-80)
      $items          `statistic` => the statistics (manual or live), resolved by <x-site.stats>
      $media          hero_image, background_image, background_video, video_poster
      $overlayHeader  bool — the header sits on top of this hero, so the copy clears it

    The hero owns the page's only <h1>.

    Background, in order:
      · a background video, muted and looped (x-site.image renders it) — inserted by Alpine only on a
        wide screen whose visitor has not asked for reduced motion, and only while
        `website.hero_video_enabled` is on, so a phone never downloads it (R-7). The poster (or the
        background image) paints underneath and is what everyone else sees;
      · a background image, eager with fetchpriority=high — it is the Largest Contentful Paint;
      · neither: the brand gradient with a faint grid and two soft glows, which is also what a freshly
        installed site looks like (no demo images are seeded, §6.14).

    Any background image or video puts the copy in a forced-dark scope (white text) under an overlay
    whose strength is the editor's `overlay_opacity`, so contrast is a setting, not luck.
--}}

@php
    $fields = (array) ($content ?? []);
    $media = (array) ($media ?? []);

    $heading = trim((string) data_get($fields, 'heading', ''));
    $subtitle = trim((string) data_get($fields, 'subtitle', ''));
    $description = data_get($fields, 'description');
    $alignLeft = data_get($fields, 'alignment') === 'left';
    $overlayOpacity = max(0, min(80, (int) data_get($fields, 'overlay_opacity', 40)));

    $withUrl = static fn (mixed $item): ?array => is_array($item) && filled($item['url'] ?? null) ? $item : null;
    $decorative = static fn (?array $item): ?array => $item === null ? null : array_merge($item, ['alt' => '']);

    $heroImage = $withUrl(data_get($media, 'hero_image'));
    $backgroundImage = $withUrl(data_get($media, 'background_image'));
    $poster = $withUrl(data_get($media, 'video_poster'));
    $video = $withUrl(data_get($media, 'background_video'));

    $videoEnabled = filter_var(site_setting('website.hero_video_enabled', true), FILTER_VALIDATE_BOOLEAN);
    $video = $videoEnabled && $video !== null && (bool) ($video['is_video'] ?? false) ? $video : null;

    // What paints when the video does not: its poster, else the background image.
    $stillBackground = $decorative($backgroundImage ?? ($video !== null || data_get($media, 'background_video.url') ? $poster : null));

    $hasBackground = $stillBackground !== null || $video !== null;

    $buttons = array_values(array_filter([
        data_get($fields, 'primary_button'),
        data_get($fields, 'secondary_button'),
    ], static fn ($link): bool => is_array($link) && filled($link['label'] ?? null) && filled($link['url'] ?? null)));

    $statistics = (bool) data_get($fields, 'show_statistics', true) ? (array) data_get($items ?? [], 'statistic', []) : [];

    $split = $alignLeft && $heroImage !== null;
    $anchor = data_get($section ?? null, 'anchor');

    // The pill above the heading and the rating under the buttons (both optional content fields).
    $badgeText = trim((string) data_get($fields, 'badge_text', ''));
    $badgeTag = trim((string) data_get($fields, 'badge_tag', ''));
    $ratingRaw = trim((string) data_get($fields, 'rating_value', ''));
    $rating = is_numeric($ratingRaw) && (float) $ratingRaw > 0 && (float) $ratingRaw <= 5 ? $ratingRaw : null;
    $ratingText = trim((string) data_get($fields, 'rating_text', ''));
    // Whole stars filled up to the rating, rounded to the nearest half for the last one.
    $ratingHalves = $rating === null ? 0 : (int) round(((float) $rating) * 2);

    // With no image of their own and no photo behind it, the hero shows a drawn dashboard preview with the
    // statistics floating on it as chips — the same figures, so nothing on it is invented.
    $mockup = $heroImage === null && ! $hasBackground;
@endphp

<x-site.section
    :anchor="$anchor"
    background="none"
    padding="none"
    width="full"
    :label="$heading !== '' ? $heading : null"
    class="isolate overflow-hidden"
>
    {{-- Background layer --}}
    <div class="absolute inset-0 -z-10" aria-hidden="true">
        @if ($hasBackground)
            <div class="absolute inset-0 bg-slate-950"></div>

            @if ($stillBackground !== null)
                <x-site.image :media="$stillBackground" profile="hero" :eager="true" class="absolute inset-0 h-full w-full" />
            @endif

            @if ($video !== null)
                <div
                    x-data="{ play: false }"
                    x-init="play = window.matchMedia('(min-width: 768px)').matches && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches"
                    class="absolute inset-0"
                >
                    <template x-if="play">
                        <div class="absolute inset-0">
                            <x-site.image :media="$video" class="absolute inset-0 h-full w-full motion-reduce:hidden" />
                        </div>
                    </template>
                </div>
            @endif

            <div class="absolute inset-0 bg-slate-950" style="opacity: {{ $overlayOpacity / 100 }}"></div>
            <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-slate-950/70 to-transparent"></div>
        @else
            <div class="absolute inset-0 bg-gradient-to-b from-brand-50 via-white to-white dark:from-brand-950/50 dark:via-slate-950 dark:to-slate-950"></div>
            <div
                class="absolute inset-0 opacity-70 [mask-image:radial-gradient(ellipse_at_top,black_30%,transparent_75%)] dark:opacity-40"
                data-fx-parallax
                style="background-image: linear-gradient(to right, rgb(var(--brand-500) / 0.08) 1px, transparent 1px), linear-gradient(to bottom, rgb(var(--brand-500) / 0.08) 1px, transparent 1px); background-size: 3.5rem 3.5rem; --fx-far: 28px;"
            ></div>
            <div class="absolute -top-40 left-1/2 h-[36rem] w-[36rem] -translate-x-1/2 rounded-full bg-brand-400/20 blur-3xl dark:bg-brand-500/15" data-fx-parallax style="--fx-far: 70px"></div>
            <div class="absolute -right-32 top-1/3 h-80 w-80 rounded-full bg-brand-300/20 blur-3xl dark:bg-brand-700/20" data-fx-parallax style="--fx-far: -110px"></div>
            <div class="absolute -left-40 bottom-0 h-72 w-72 rounded-full bg-sky-300/10 blur-3xl dark:bg-sky-500/10" data-fx-parallax style="--fx-far: 60px"></div>
            {{-- Aurora: a violet and a cyan glow under the brand one. --}}
            <div class="absolute left-[12%] top-24 h-72 w-[28rem] -rotate-12 rounded-full bg-violet-400/20 blur-3xl dark:bg-violet-600/20" data-fx-parallax style="--fx-far: 40px"></div>
            <div class="absolute right-[8%] top-44 h-64 w-96 rotate-12 rounded-full bg-cyan-300/20 blur-3xl dark:bg-cyan-500/10" data-fx-parallax style="--fx-far: -50px"></div>
            <div class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-brand-500/30 to-transparent dark:via-brand-400/30"></div>
        @endif
    </div>

    <div @class(['dark' => $hasBackground])>
        <div @class([
            'relative mx-auto w-full max-w-screen-xl px-4 sm:px-6 lg:px-8',
            'pb-16 sm:pb-20 lg:pb-24',
            'pt-32 sm:pt-40 lg:pt-44' => $overlayHeader ?? false,
            'pt-16 sm:pt-24 lg:pt-28' => ! ($overlayHeader ?? false),
            'min-h-[34rem] lg:min-h-[40rem]' => $hasBackground,
        ])>
            <div @class([
                'grid items-center gap-12 lg:grid-cols-12 lg:gap-16' => $split,
            ])>
                <div @class([
                    'lg:col-span-6 xl:col-span-7' => $split,
                    'max-w-3xl' => $alignLeft && ! $split,
                    'mx-auto max-w-4xl text-center' => ! $alignLeft,
                ])>
                    @if ($badgeText !== '')
                        <p data-fx="rise" data-fx-lcp @class([
                            'mb-7 inline-flex max-w-full items-center gap-2.5 rounded-full border border-brand-200/70 bg-white/70 py-1.5 pl-2 pr-3 text-xs font-medium text-slate-700 shadow-sm backdrop-blur sm:text-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-200',
                        ])>
                            <span class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-60 motion-reduce:animate-none"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-brand-500"></span>
                            </span>
                            <span class="truncate">{{ $badgeText }}</span>
                            @if ($badgeTag !== '')
                                <span class="hidden h-3.5 w-px bg-slate-300 sm:block dark:bg-white/15" aria-hidden="true"></span>
                                <span class="shrink-0 bg-gradient-to-r from-brand-600 to-violet-600 bg-clip-text font-semibold text-transparent dark:from-brand-300 dark:to-cyan-300">{{ $badgeTag }}</span>
                            @endif
                        </p>
                    @endif

                    @if ($heading !== '')
                        {{-- `data-fx-lcp`: the hero heading is the page's Largest Contentful Paint on
                             almost every public page, so it rises but never fades — see app.css. --}}
                        <h1 data-fx="rise" data-fx-lcp class="text-balance bg-gradient-to-br from-slate-900 via-slate-800 to-brand-700 bg-clip-text pb-1 text-4xl font-extrabold tracking-tight text-transparent sm:text-5xl lg:text-7xl lg:leading-[1.02] dark:from-white dark:via-white dark:to-brand-300">
                            {{ $heading }}
                        </h1>
                    @endif

                    @if ($subtitle !== '')
                        <p data-fx="rise" data-fx-lcp data-fx-delay="1" @class([
                            'text-pretty text-lg leading-relaxed text-slate-600 sm:text-xl dark:text-slate-300',
                            'mt-6' => $heading !== '',
                            'mx-auto max-w-2xl' => ! $alignLeft,
                            'max-w-2xl' => $alignLeft,
                        ])>{{ $subtitle }}</p>
                    @endif

                    @if (filled($description))
                        <x-site.prose
                            data-fx="rise"
                            data-fx-delay="2"
                            :html="$description"
                            size="lg"
                            @class([
                                'mt-5',
                                'mx-auto max-w-2xl' => ! $alignLeft,
                                'max-w-2xl' => $alignLeft,
                            ])
                        />
                    @endif

                    @if ($buttons !== [])
                        <div data-fx="rise" data-fx-delay="3" @class([
                            'mt-10 flex flex-col gap-3 sm:flex-row sm:flex-wrap',
                            'sm:justify-center' => ! $alignLeft,
                        ])>
                            @foreach ($buttons as $link)
                                <x-site.button :link="$link" size="lg" />
                            @endforeach
                        </div>
                    @endif

                    @if ($rating !== null)
                        <div data-fx="rise" data-fx-delay="3" @class([
                            'mt-8 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600 dark:text-slate-300',
                            'justify-center' => ! $alignLeft,
                        ])>
                            <span class="flex items-center gap-0.5" aria-hidden="true">
                                @for ($star = 1; $star <= 5; $star++)
                                    <x-ui.icon name="star" @class([
                                        'h-4 w-4',
                                        'fill-amber-400 text-amber-400' => $ratingHalves >= $star * 2 - 1,
                                        'text-slate-300 dark:text-slate-600' => $ratingHalves < $star * 2 - 1,
                                    ]) />
                                @endfor
                            </span>
                            <span><strong class="font-semibold text-slate-900 dark:text-white">{{ $rating }}</strong>/5<span class="sr-only"> stars</span></span>
                            @if ($ratingText !== '')
                                <span class="text-slate-500 dark:text-slate-400">{{ $ratingText }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($heroImage !== null)
                    <div @class([
                        'relative lg:col-span-6 xl:col-span-5' => $split,
                        'relative mx-auto mt-16 max-w-5xl sm:mt-20' => ! $split,
                    ])>
                        <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-gradient-to-tr from-brand-500/20 via-brand-300/10 to-transparent blur-2xl dark:from-brand-500/25" aria-hidden="true"></div>
                        <div data-fx="deck" data-fx-delay="2" class="overflow-hidden rounded-2xl bg-white/60 p-1.5 shadow-2xl ring-1 ring-slate-900/10 backdrop-blur dark:bg-white/5 dark:ring-white/10">
                            <x-site.image
                                :media="$heroImage"
                                profile="hero"
                                :eager="! $hasBackground"
                                class="overflow-hidden rounded-xl"
                                img-class="h-auto w-full object-cover"
                            />
                        </div>
                    </div>
                @endif
            </div>

            @if ($mockup)
                @include('site.sections.partials.hero-mockup', ['statistics' => $statistics])
            @elseif ($statistics !== [])
                <x-site.stats data-fx="rise" data-fx-delay="4" :items="$statistics" variant="cards" class="mx-auto mt-16 max-w-5xl sm:mt-20" />
            @endif
        </div>
    </div>
</x-site.section>
