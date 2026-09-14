@props([
    'variant' => 'icon',
])

{{--
    x-site.theme-toggle — Light / Dark / System on the public site (requirement §2, phase-03 §8.14).

        <x-site.theme-toggle />                      the header's single icon button (light <-> dark)
        <x-site.theme-toggle variant="segmented" />  the footer's three-way control, System included

    It drives the same `$store.theme` the panels use (resources/js/theme.js), so the choice is
    stored under the same localStorage key and a visitor who is also staff sees one preference
    everywhere. The first paint is decided by `site.partials.theme-script`, which falls back to
    `appearance.default_theme` when the visitor has made no choice.

    Hidden entirely when `website.show_theme_toggle` is off.

    The icon state is bound to the store, not to `dark:` utilities, because the header can sit
    inside a forced-dark scope over a hero image: a `dark:` swap there would show the sun while the
    page itself is light.
--}}

@php
    // Never rescued (INV-10): a key that is not public must fail the render, not quietly print its default.
    $siteSetting = static fn (string $key, mixed $default = null): mixed => site_setting($key, $default);

    $enabled = filter_var($siteSetting('website.show_theme_toggle', true), FILTER_VALIDATE_BOOLEAN);

    $options = [
        ['light', 'sun', 'Light'],
        ['dark', 'moon', 'Dark'],
        ['system', 'computer-desktop', 'System'],
    ];
@endphp

@if ($enabled)
    @if ($variant === 'segmented')
        <div
            role="group"
            aria-label="Colour theme"
            {{ $attributes->class('inline-flex items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 ring-1 ring-inset ring-slate-200 dark:bg-white/5 dark:ring-white/10') }}
        >
            @foreach ($options as [$value, $icon, $label])
                <button
                    type="button"
                    x-on:click="$store.theme.set('{{ $value }}')"
                    x-bind:aria-pressed="$store.theme.is('{{ $value }}') ? 'true' : 'false'"
                    x-bind:class="$store.theme.is('{{ $value }}')
                        ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white'
                        : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-md transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60"
                    title="{{ $label }}"
                >
                    <x-ui.icon :name="$icon" class="h-4 w-4" />
                    <span class="sr-only">{{ $label }} theme</span>
                </button>
            @endforeach
        </div>
    @else
        <button
            type="button"
            x-on:click="$store.theme.set($store.theme.isDark ? 'light' : 'dark')"
            x-bind:aria-label="$store.theme.isDark ? 'Switch to the light theme' : 'Switch to the dark theme'"
            aria-label="Switch colour theme"
            {{ $attributes->class('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-600 transition duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white') }}
        >
            <span x-show="! $store.theme.isDark"><x-ui.icon name="moon" class="h-5 w-5" /></span>
            <span x-show="$store.theme.isDark" x-cloak><x-ui.icon name="sun" class="h-5 w-5" /></span>
        </button>
    @endif
@endif
