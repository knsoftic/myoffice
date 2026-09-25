{{--
    Section type `rich_content` (placements home, page; repeatable) — requirement §7, §101,
    phase-03 §6.1.

    Receives:
      $section  the published snapshot (id, anchor)
      $content  heading, subheading, body (sanitised rich text), layout (full | text_left | text_right),
                background (surface | muted | brand)
      $items    highlight => [icon, title, text]
      $media    image_1 (card profile)

    `text_left` / `text_right` need an image to mean anything; without one the block falls back to a
    single readable column instead of leaving half the row empty.
--}}

@php
    $fields = (array) ($content ?? []);

    $heading = trim((string) data_get($fields, 'heading', ''));
    $subheading = trim((string) data_get($fields, 'subheading', ''));
    $body = data_get($fields, 'body');
    $hasBody = is_string($body) && (trim(strip_tags($body)) !== '' || preg_match('/<(?:img|iframe)/i', $body) === 1);

    $image = data_get($media ?? [], 'image_1');
    $image = is_array($image) && filled($image['url'] ?? null) ? $image : null;

    $layout = in_array(data_get($fields, 'layout'), ['full', 'text_left', 'text_right'], true) ? data_get($fields, 'layout') : 'full';
    $layout = $image === null ? 'full' : $layout;

    $background = in_array(data_get($fields, 'background'), ['surface', 'muted', 'brand'], true) ? data_get($fields, 'background') : 'surface';

    $highlights = collect(data_get($items ?? [], 'highlight', []))
        ->filter(static fn ($item): bool => filled(data_get($item, 'content.title')))
        ->values();

    $lazy = filter_var(site_setting('website.image_lazy_loading', true), FILTER_VALIDATE_BOOLEAN);
@endphp

@if ($heading !== '' || $subheading !== '' || $hasBody || $image !== null || $highlights->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" :background="$background" :label="$heading !== '' ? $heading : null">
        @if ($layout === 'full')
            <div class="mx-auto max-w-3xl">
                <x-site.heading data-fx="rise" :title="$heading" :subtitle="$subheading" align="center" />

                @if ($hasBody)
                    <x-site.prose data-fx="rise" data-fx-delay="1" :html="$body" size="lg" @class(['mt-8' => $heading !== '' || $subheading !== '']) />
                @endif
            </div>

            @if ($image !== null)
                <div data-fx="deck" class="mx-auto mt-12 max-w-5xl overflow-hidden rounded-2xl bg-slate-100 shadow-xl ring-1 ring-slate-900/5 dark:bg-slate-800 dark:ring-white/10">
                    <x-site.image :media="$image" profile="card" :lazy="$lazy" img-class="h-auto w-full object-cover" />
                </div>
            @endif
        @else
            <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                <div data-fx="{{ $layout === 'text_right' ? 'right' : 'left' }}" @class(['lg:order-2' => $layout === 'text_right'])>
                    <x-site.heading :title="$heading" :subtitle="$subheading" align="left" />

                    @if ($hasBody)
                        <x-site.prose :html="$body" size="lg" @class(['mt-8' => $heading !== '' || $subheading !== '']) />
                    @endif
                </div>

                <div data-fx="{{ $layout === 'text_right' ? 'left' : 'right' }}" @class(['lg:order-1' => $layout === 'text_right'])>
                    <div class="overflow-hidden rounded-2xl bg-slate-100 shadow-xl ring-1 ring-slate-900/5 dark:bg-slate-800 dark:ring-white/10">
                        <x-site.image :media="$image" profile="card" :lazy="$lazy" ratio="4/3" class="h-full w-full" />
                    </div>
                </div>
            </div>
        @endif

        @if ($highlights->isNotEmpty())
            <ul role="list" @class([
                'mt-16 grid gap-6 sm:grid-cols-2',
                'lg:grid-cols-3' => $highlights->count() !== 4,
                'lg:grid-cols-4' => $highlights->count() === 4,
            ])>
                @foreach ($highlights as $highlight)
                    <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="flex gap-4 rounded-2xl border border-slate-200/80 bg-white/70 p-6 dark:border-white/10 dark:bg-white/[0.03]">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">
                            <x-ui.icon :name="data_get($highlight, 'content.icon') ?: 'sparkles'" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ data_get($highlight, 'content.title') }}</h3>
                            @if (filled(data_get($highlight, 'content.text')))
                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ data_get($highlight, 'content.text') }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-site.section>
@endif
