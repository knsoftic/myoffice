{{--
    Section type `leadership` (LandingSectionTypes) — the chief executive.

    Receives:
      $section  the published snapshot (id, anchor)
      $content  eyebrow, heading, name, designation, quote, message, linkedin_url, is_founder
      $items    fact => [value, label]
      $media    photo (hero profile — uncropped, so a portrait keeps its shape)

    The photo sits beside the words with the name on a card over its foot; without a photo the words
    take the full width. Publishing refuses a photo with no alt text; the name stands in for a draft
    preview. The message is plain text: an empty line starts a new paragraph. With no name
    the section renders nothing — there is nobody to introduce.

    The same facts reach search engines as a `Person` in the home page's JSON-LD (`OrganizationSchema`).
--}}

@php
    $fields = (array) ($content ?? []);

    $eyebrow = trim((string) data_get($fields, 'eyebrow', ''));
    $heading = trim((string) data_get($fields, 'heading', ''));
    $name = trim((string) data_get($fields, 'name', ''));
    $designation = trim((string) data_get($fields, 'designation', ''));
    $quote = trim((string) data_get($fields, 'quote', ''));

    $paragraphs = collect(preg_split('/\R\s*\R/u', trim((string) data_get($fields, 'message', ''))) ?: [])
        ->map(static fn (string $paragraph): string => trim($paragraph))
        ->filter()
        ->values();

    $linkedin = trim((string) data_get($fields, 'linkedin_url', ''));
    $linkedin = preg_match('~^https?://\S+$~i', $linkedin) === 1 ? $linkedin : null;

    $photo = data_get($media ?? [], 'photo');
    $photo = filled(data_get($photo, 'url'))
        ? array_merge((array) $photo, ['alt' => filled(data_get($photo, 'alt')) ? data_get($photo, 'alt') : $name])
        : null;

    $facts = collect(data_get($items ?? [], 'fact', []))
        ->filter(static fn ($item): bool => filled(data_get($item, 'content.value')) && filled(data_get($item, 'content.label')))
        ->values();

    $lazy = filter_var(site_setting('website.image_lazy_loading', true), FILTER_VALIDATE_BOOLEAN);
@endphp

@if ($name !== '')
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="none" :label="$heading !== '' ? $heading : $name" class="isolate overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-slate-50 dark:bg-slate-900/60"></div>
            <div class="absolute -left-24 top-1/3 h-72 w-72 rounded-full bg-brand-400/15 blur-3xl dark:bg-brand-500/10"></div>
            <div class="absolute -right-24 bottom-0 h-72 w-72 rounded-full bg-violet-400/15 blur-3xl dark:bg-violet-500/10"></div>
        </div>

        <div class="grid items-center gap-14 lg:grid-cols-12 lg:gap-16">
            @if ($photo !== null)
                <div class="lg:col-span-5" data-fx="rise">
                    <div class="relative mx-auto max-w-sm pb-8 lg:max-w-none">
                        <div class="absolute -inset-3 -z-10 rounded-[2.25rem] bg-gradient-to-br from-brand-500/25 via-violet-500/15 to-cyan-400/25 blur-2xl dark:from-brand-500/20 dark:to-cyan-400/10" aria-hidden="true"></div>

                        <div class="overflow-hidden rounded-[2rem] bg-slate-200 shadow-2xl shadow-brand-900/10 ring-1 ring-slate-900/5 dark:bg-slate-800 dark:shadow-black/40 dark:ring-white/10">
                            <x-site.image
                                :media="$photo"
                                profile="hero"
                                sizes="(min-width:1024px) 40vw, (min-width:640px) 24rem, 100vw"
                                :lazy="$lazy"
                                img-class="aspect-[4/5] h-full w-full object-cover object-top"
                            />
                        </div>

                        <div class="absolute inset-x-6 bottom-0 rounded-2xl bg-white/90 px-5 py-4 shadow-lg ring-1 ring-slate-900/5 backdrop-blur dark:bg-slate-900/90 dark:ring-white/10">
                            <p class="font-semibold text-slate-900 dark:text-white">{{ $name }}</p>
                            @if ($designation !== '')
                                <p class="mt-0.5 text-sm font-medium text-brand-600 dark:text-brand-300">{{ $designation }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <div @class(['lg:col-span-7' => $photo !== null, 'mx-auto max-w-3xl lg:col-span-12' => $photo === null])>
                <x-site.heading data-fx="rise" :eyebrow="$eyebrow" :title="$heading" align="left" :accent="true" />

                <figure @class(['mt-8' => $heading !== '' || $eyebrow !== ''])>
                    @if ($quote !== '' || $paragraphs->isNotEmpty())
                        <blockquote data-fx="rise" data-fx-delay="1">
                            @if ($quote !== '')
                                <p class="relative text-pretty text-xl font-semibold leading-snug text-slate-900 sm:text-2xl dark:text-white">
                                    <span class="absolute -left-1 -top-6 select-none font-serif text-6xl leading-none text-brand-500/25 dark:text-brand-400/30" aria-hidden="true">&ldquo;</span>
                                    <span class="relative">{{ $quote }}</span>
                                </p>
                            @endif

                            @if ($paragraphs->isNotEmpty())
                                <div @class(['space-y-4 text-base leading-relaxed text-slate-600 dark:text-slate-300', 'mt-6' => $quote !== ''])>
                                    @foreach ($paragraphs as $paragraph)
                                        <p class="whitespace-pre-line">{{ $paragraph }}</p>
                                    @endforeach
                                </div>
                            @endif
                        </blockquote>
                    @endif

                    <figcaption class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-3" data-fx="rise" data-fx-delay="2">
                        <span class="h-px w-10 bg-gradient-to-r from-brand-500 to-violet-500" aria-hidden="true"></span>
                        <span>
                            <span class="block font-semibold text-slate-900 dark:text-white">{{ $name }}</span>
                            @if ($designation !== '')
                                <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $designation }}</span>
                            @endif
                        </span>

                        @if ($linkedin !== null)
                            <a
                                href="{{ $linkedin }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm font-medium text-slate-700 transition hover:border-brand-300 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:border-brand-400/40 dark:hover:text-brand-300"
                            >
                                LinkedIn
                                <x-ui.icon name="arrow-top-right-on-square" class="h-4 w-4" />
                                <span class="sr-only">(opens in a new tab)</span>
                            </a>
                        @endif
                    </figcaption>
                </figure>

                @if ($facts->isNotEmpty())
                    <dl @class([
                        'mt-10 grid gap-4',
                        'grid-cols-2' => $facts->count() !== 3,
                        'grid-cols-1 sm:grid-cols-3' => $facts->count() === 3,
                        'sm:grid-cols-4' => $facts->count() === 4,
                    ])>
                        @foreach ($facts as $fact)
                            <div data-fx="rise" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="flex flex-col-reverse rounded-2xl border border-slate-200/80 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/[0.03]">
                                <dt class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ data_get($fact, 'content.label') }}</dt>
                                <dd class="bg-gradient-to-r from-brand-600 to-violet-600 bg-clip-text text-2xl font-bold tracking-tight text-transparent dark:from-brand-300 dark:to-violet-300">{{ data_get($fact, 'content.value') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>
    </x-site.section>
@endif
