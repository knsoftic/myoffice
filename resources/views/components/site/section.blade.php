@props([
    'anchor' => null,
    'background' => 'surface',
    'width' => 'default',
    'padding' => 'default',
    'label' => null,
    'tag' => 'section',
])

{{--
    x-site.section — the shell every public section sits in (§8.14 "Sections").

        <x-site.section :anchor="$section['anchor'] ?? null" background="muted" label="About us">
            …
        </x-site.section>

    It owns three things so that no section partial repeats layout chrome:

      · the `id`, taken from `website_sections.anchor`, which is what lets a menu item of type
        `section_anchor` link to it (§102);
      · the vertical rhythm — one generous scale (py-16 → py-28) used by every section, because
        inconsistent spacing is the single most visible sign of a page assembled from parts;
      · the container, `max-w-screen-xl` with the page gutter, so nothing ever touches the edge of
        a phone screen.

    `background` is the section palette, and every value has a `dark:` counterpart:
      surface  the page background        muted  a faint inset band
      brand    the brand gradient         dark   a deliberately dark band (stats, CTA)
      none     no background at all (the partial paints its own, e.g. the hero)

    `label` becomes `aria-labelledby`-less `aria-label` on the landmark, so a screen-reader user
    moving by region hears "About us, region" rather than six unnamed regions.
--}}

@php
    $backgrounds = [
        'surface' => 'bg-white dark:bg-slate-950',
        'muted' => 'bg-slate-50 dark:bg-slate-900/40',
        'brand' => 'bg-gradient-to-br from-brand-50 via-white to-brand-50 dark:from-brand-950/40 dark:via-slate-950 dark:to-slate-950',
        'dark' => 'bg-slate-900 dark:bg-slate-900',
        'none' => '',
    ];

    $paddings = [
        'none' => '',
        'tight' => 'py-10 sm:py-12',
        'default' => 'py-16 sm:py-20 lg:py-28',
        'loose' => 'py-20 sm:py-28 lg:py-36',
    ];

    $widths = [
        'default' => 'mx-auto w-full max-w-screen-xl px-4 sm:px-6 lg:px-8',
        'narrow' => 'mx-auto w-full max-w-3xl px-4 sm:px-6 lg:px-8',
        'prose' => 'mx-auto w-full max-w-2xl px-4 sm:px-6 lg:px-8',
        'wide' => 'mx-auto w-full max-w-screen-2xl px-4 sm:px-6 lg:px-8',
        'full' => 'w-full',
    ];

    $anchorId = filled($anchor) ? trim((string) $anchor, '#') : null;
@endphp

<{{ $tag }}
    @if ($anchorId !== null) id="{{ $anchorId }}" @endif
    @if (filled($label)) aria-label="{{ $label }}" @endif
    {{ $attributes->class([
        'relative',
        $backgrounds[$background] ?? $backgrounds['surface'],
        $paddings[$padding] ?? $paddings['default'],
        'scroll-mt-24' => $anchorId !== null,
    ]) }}
>
    <div class="{{ $widths[$width] ?? $widths['default'] }}">
        {{ $slot }}
    </div>
</{{ $tag }}>
