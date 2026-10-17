{{--
    Section type `office` (LandingSectionTypes) — the office: a photo gallery and where to find it.

    Receives:
      $section  the published snapshot (id, anchor)
      $content  eyebrow, heading, description, show_details, directions_label
      $items    photo => [image (media, hero profile — uncropped), caption]

    The photos sit in a masonry grid at their own proportions (the hero profile keeps the shape and the
    snapshot carries the dimensions, so nothing shifts while they load); the first nine show, and the
    ninth says how many more there are. Publishing refuses a photo with no alt text; the caption stands
    in for a draft preview. Any photo opens the lightbox, which walks every photo with the
    arrow keys, closes on Escape and returns focus to the photo that opened it.

    Beside them, the address, hours, phone, email and a directions link — read from Settings › Contact
    through `OrganizationSchema::office()`, the same facts the footer and the home page JSON-LD state.
    With neither photos nor details the section renders nothing.
--}}

@php
    use App\Support\Cms\OrganizationSchema;

    $fields = (array) ($content ?? []);

    $eyebrow = trim((string) data_get($fields, 'eyebrow', ''));
    $heading = trim((string) data_get($fields, 'heading', ''));
    $description = trim((string) data_get($fields, 'description', ''));
    $directionsLabel = trim((string) data_get($fields, 'directions_label', ''));

    $photos = collect(data_get($items ?? [], 'photo', []))
        ->filter(static fn ($item): bool => filled(data_get($item, 'media.url')))
        ->map(static function ($item, $index): array {
            $caption = trim((string) data_get($item, 'content.caption', ''));
            $media = (array) data_get($item, 'media');
            $alt = filled(data_get($media, 'alt')) ? (string) data_get($media, 'alt') : $caption;

            return ['media' => array_merge($media, ['alt' => $alt]), 'caption' => $caption, 'alt' => $alt];
        })
        ->values();

    $office = (bool) data_get($fields, 'show_details', true) ? OrganizationSchema::office() : null;
    $hasDetails = $office !== null
        && ($office['address'] !== '' || $office['hours'] !== [] || $office['phone'] !== '' || $office['email'] !== '');

    $visible = 9;
    $more = max(0, $photos->count() - $visible);

    $lazy = filter_var(site_setting('website.image_lazy_loading', true), FILTER_VALIDATE_BOOLEAN);
@endphp

@if ($photos->isNotEmpty() || $hasDetails)
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading !== '' ? $heading : null">
        <x-site.heading data-fx="rise" :eyebrow="$eyebrow" :title="$heading" :subtitle="$description" align="center" />

        <div @class([
            'grid gap-8 lg:grid-cols-12 lg:gap-10' => $photos->isNotEmpty() && $hasDetails,
            'mt-12' => $heading !== '' || $description !== '' || $eyebrow !== '',
        ])>
            @if ($photos->isNotEmpty())
                <div
                    @class(['lg:col-span-8' => $hasDetails])
                    x-data="{
                        open: false,
                        index: 0,
                        count: {{ $photos->count() }},
                        opener: null,
                        show(i, el) { this.index = i; this.opener = el; this.open = true; this.$nextTick(() => this.$refs.close?.focus()); },
                        hide() { this.open = false; this.$nextTick(() => this.opener?.focus()); },
                        next() { this.index = (this.index + 1) % this.count; },
                        prev() { this.index = (this.index - 1 + this.count) % this.count; },
                    }"
                    x-on:keydown.escape.window="if (open) hide()"
                    x-on:keydown.arrow-right.window="if (open) next()"
                    x-on:keydown.arrow-left.window="if (open) prev()"
                >
                    <ul role="list" @class([
                        'gap-4 [&>li]:mb-4',
                        'columns-1 sm:columns-2' => $hasDetails || $photos->count() > 1,
                        'lg:columns-3' => ! $hasDetails && $photos->count() > 2,
                    ])>
                        @foreach ($photos->take($visible) as $i => $photo)
                            <li class="break-inside-avoid" data-fx="rise" data-fx-delay="{{ ($i % 3) + 1 }}">
                                <figure class="group relative overflow-hidden rounded-2xl bg-slate-100 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-white/10">
                                    <button
                                        type="button"
                                        x-on:click="show({{ $i }}, $el)"
                                        class="block w-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500"
                                    >
                                        <x-site.image
                                            :media="$photo['media']"
                                            profile="hero"
                                            :sizes="$hasDetails ? '(min-width:1024px) 30vw, (min-width:640px) 50vw, 100vw' : '(min-width:1024px) 33vw, (min-width:640px) 50vw, 100vw'"
                                            :lazy="$lazy"
                                            img-class="h-auto w-full object-cover transition duration-500 group-hover:scale-[1.03]"
                                        />

                                        @if ($i === $visible - 1 && $more > 0)
                                            <span class="absolute inset-0 flex items-center justify-center bg-slate-950/60 text-2xl font-bold text-white backdrop-blur-[2px]">+{{ $more }}</span>
                                            <span class="sr-only">and {{ $more }} more photos. </span>
                                        @endif
                                        <span class="sr-only">Open photo {{ $i + 1 }} of {{ $photos->count() }}</span>
                                    </button>

                                    @if ($photo['caption'] !== '' && ! ($i === $visible - 1 && $more > 0))
                                        <figcaption class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/80 via-slate-950/30 to-transparent px-4 pb-3 pt-10 text-sm font-medium text-white">
                                            {{ $photo['caption'] }}
                                        </figcaption>
                                    @endif
                                </figure>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Lightbox --}}
                    <div
                        x-show="open"
                        x-cloak
                        x-transition.opacity
                        x-trap.noscroll="open"
                        class="dark fixed inset-0 z-modal flex flex-col bg-slate-950/95 p-4 sm:p-8"
                        role="dialog"
                        aria-modal="true"
                        aria-label="{{ $heading !== '' ? $heading : 'Office photos' }}"
                        style="display: none"
                    >
                        <div class="flex items-center justify-between text-sm text-slate-300">
                            <span class="tabular-nums" x-text="(index + 1) + ' / ' + count"></span>
                            <button type="button" x-ref="close" x-on:click="hide()" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-200 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                <x-ui.icon name="x-mark" class="h-6 w-6" />
                                <span class="sr-only">Close the gallery</span>
                            </button>
                        </div>

                        <div class="relative flex min-h-0 flex-1 items-center justify-center py-4" x-on:click.self="hide()">
                            @foreach ($photos as $i => $photo)
                                <figure x-show="index === {{ $i }}" class="flex max-h-full w-full max-w-6xl flex-col items-center">
                                    <x-site.image :media="$photo['media']" profile="hero" sizes="100vw" img-class="max-h-[75vh] w-auto max-w-full rounded-lg object-contain" />
                                    @if ($photo['caption'] !== '')
                                        <figcaption class="mt-3 text-center text-sm text-slate-300">{{ $photo['caption'] }}</figcaption>
                                    @endif
                                </figure>
                            @endforeach

                            @if ($photos->count() > 1)
                                <button type="button" x-on:click="prev()" class="absolute left-0 top-1/2 inline-flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                    <x-ui.icon name="chevron-left" class="h-6 w-6" /><span class="sr-only">Previous photo</span>
                                </button>
                                <button type="button" x-on:click="next()" class="absolute right-0 top-1/2 inline-flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                    <x-ui.icon name="chevron-right" class="h-6 w-6" /><span class="sr-only">Next photo</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if ($hasDetails)
                <aside
                    @class(['lg:col-span-4' => $photos->isNotEmpty(), 'mx-auto max-w-xl' => $photos->isEmpty()])
                    aria-label="{{ trim((string) site_setting('company.name', '')) !== '' ? trim((string) site_setting('company.name', '')).' office' : 'Office details' }}"
                >
                    <div data-fx="right" class="space-y-6 rounded-2xl border border-slate-200 bg-slate-50 p-6 lg:sticky lg:top-28 dark:border-white/10 dark:bg-white/[0.03]">
                        @if ($office['address'] !== '')
                            <div class="flex gap-4">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20" aria-hidden="true">
                                    <x-ui.icon name="map-pin" class="h-5 w-5" />
                                </span>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Address</p>
                                    <address class="mt-1 text-sm not-italic leading-relaxed text-slate-800 dark:text-slate-200">{{ $office['address'] }}</address>
                                </div>
                            </div>
                        @endif

                        @if ($office['hours'] !== [])
                            <div class="flex gap-4">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20" aria-hidden="true">
                                    <x-ui.icon name="clock" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Office hours</p>
                                    <dl class="mt-1 space-y-1 text-sm">
                                        @foreach ($office['hours'] as $row)
                                            <div class="flex justify-between gap-4">
                                                <dt class="text-slate-600 dark:text-slate-400">{{ $row['days'] }}</dt>
                                                <dd @class([
                                                    'tabular-nums',
                                                    'text-slate-800 dark:text-slate-200' => $row['value'] !== 'Closed',
                                                    'text-slate-400 dark:text-slate-500' => $row['value'] === 'Closed',
                                                ])>{{ $row['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            </div>
                        @endif

                        @if ($office['phone'] !== '' || $office['email'] !== '')
                            <div class="space-y-3 border-t border-slate-200 pt-6 dark:border-white/10">
                                @if ($office['phone_href'] !== null)
                                    <a href="{{ $office['phone_href'] }}" class="flex items-center gap-3 text-sm text-slate-700 transition hover:text-brand-700 dark:text-slate-300 dark:hover:text-brand-300">
                                        <x-ui.icon name="phone" class="h-5 w-5 text-slate-400 dark:text-slate-500" />
                                        <span>{{ $office['phone'] }}</span>
                                    </a>
                                @endif
                                @if ($office['email'] !== '')
                                    <a href="mailto:{{ $office['email'] }}" class="flex items-center gap-3 break-all text-sm text-slate-700 transition hover:text-brand-700 dark:text-slate-300 dark:hover:text-brand-300">
                                        <x-ui.icon name="envelope" class="h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                                        <span>{{ $office['email'] }}</span>
                                    </a>
                                @endif
                                @if ($office['whatsapp_href'] !== null)
                                    <a href="{{ $office['whatsapp_href'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 text-sm text-slate-700 transition hover:text-brand-700 dark:text-slate-300 dark:hover:text-brand-300">
                                        <x-ui.icon name="chat-bubble-left-right" class="h-5 w-5 text-slate-400 dark:text-slate-500" />
                                        <span>WhatsApp<span class="sr-only"> (opens in a new tab)</span></span>
                                    </a>
                                @endif
                            </div>
                        @endif

                        @if ($directionsLabel !== '' && $office['directions_url'] !== null)
                            <x-site.button :label="$directionsLabel" :url="$office['directions_url']" style="primary" icon="map-pin" :new-tab="true" :block="true" />
                        @endif
                    </div>
                </aside>
            @endif
        </div>
    </x-site.section>
@endif
