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
                style="background-image: linear-gradient(to right, rgb(var(--brand-500) / 0.08) 1px, transparent 1px), linear-gradient(to bottom, rgb(var(--brand-500) / 0.08) 1px, transparent 1px); background-size: 3.5rem 3.5rem;"
            ></div>
            <div class="absolute -top-40 left-1/2 h-[36rem] w-[36rem] -translate-x-1/2 rounded-full bg-brand-400/20 blur-3xl dark:bg-brand-500/15"></div>
            <div class="absolute -right-32 top-1/3 h-80 w-80 rounded-full bg-brand-300/20 blur-3xl dark:bg-brand-700/20"></div>
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
                    @if ($heading !== '')
                        <h1 class="text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl lg:leading-[1.05] dark:text-white">
                            {{ $heading }}
                        </h1>
                    @endif

                    @if ($subtitle !== '')
                        <p @class([
                            'text-pretty text-lg leading-relaxed text-slate-600 sm:text-xl dark:text-slate-300',
                            'mt-6' => $heading !== '',
                            'mx-auto max-w-2xl' => ! $alignLeft,
                            'max-w-2xl' => $alignLeft,
                        ])>{{ $subtitle }}</p>
                    @endif

                    @if (filled($description))
                        <x-site.prose
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
                        <div @class([
                            'mt-10 flex flex-col gap-3 sm:flex-row sm:flex-wrap',
                            'sm:justify-center' => ! $alignLeft,
                        ])>
                            @foreach ($buttons as $link)
                                <x-site.button :link="$link" size="lg" />
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($heroImage !== null)
                    <div @class([
                        'relative lg:col-span-6 xl:col-span-5' => $split,
                        'relative mx-auto mt-16 max-w-5xl sm:mt-20' => ! $split,
                    ])>
                        <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-gradient-to-tr from-brand-500/20 via-brand-300/10 to-transparent blur-2xl dark:from-brand-500/25" aria-hidden="true"></div>
                        <div class="overflow-hidden rounded-2xl bg-white/60 p-1.5 shadow-2xl ring-1 ring-slate-900/10 backdrop-blur dark:bg-white/5 dark:ring-white/10">
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

            @if ($statistics !== [])
                <x-site.stats :items="$statistics" class="mt-16 sm:mt-20" />
            @endif
        </div>
    </div>
</x-site.section>
