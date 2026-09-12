@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'iconTrailing' => null,
    'loading' => false,
    'disabled' => false,
    'block' => false,
])

{{--
    x-ui.button

        <x-ui.button icon="plus">New user</x-ui.button>
        <x-ui.button variant="secondary" size="sm" :href="route('admin.users.index')">Cancel</x-ui.button>
        <x-ui.button variant="danger" type="submit" :loading="true">Deleting…</x-ui.button>

    Renders an <a> when `href` is given, a <button> otherwise. Any extra attribute
    (wire:click, x-on:click, form, name, value…) passes straight through.
--}}

@php
    $base = 'group relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg border font-semibold tracking-tight transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-brand-400/40 dark:focus-visible:ring-offset-slate-950';

    $sizes = [
        'sm' => 'h-8 px-3 text-xs',
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-11 px-5 text-sm',
    ];

    $variants = [
        'primary' => 'border-transparent bg-brand-600 text-white shadow-sm hover:bg-brand-700 hover:shadow active:bg-brand-800 dark:bg-brand-500 dark:hover:bg-brand-400',
        'secondary' => 'border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white',
        'ghost' => 'border-transparent bg-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white',
        'danger' => 'border-transparent bg-rose-600 text-white shadow-sm hover:bg-rose-700 hover:shadow active:bg-rose-800 dark:bg-rose-600 dark:hover:bg-rose-500',
        'success' => 'border-transparent bg-emerald-600 text-white shadow-sm hover:bg-emerald-700 hover:shadow active:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500',
        'link' => 'h-auto border-transparent bg-transparent px-0 text-brand-600 underline-offset-4 hover:underline dark:text-brand-400',
    ];

    $iconSizes = [
        'sm' => 'h-3.5 w-3.5',
        'md' => 'h-4 w-4',
        'lg' => 'h-5 w-5',
    ];

    $size = array_key_exists($size, $sizes) ? $size : 'md';
    $variant = array_key_exists($variant, $variants) ? $variant : 'primary';

    $iconClass = $iconSizes[$size] ?? 'h-4 w-4';
    $isDisabled = (bool) $disabled || (bool) $loading;

    $classes = trim(implode(' ', array_filter([
        $base,
        $variant === 'link' ? $sizes['sm'] : $sizes[$size],
        $variants[$variant],
        $block ? 'w-full' : '',
        $isDisabled ? 'cursor-not-allowed opacity-60' : '',
    ])));

    $tag = $href && ! $isDisabled ? 'a' : ($href ? 'span' : 'button');
@endphp

<{{ $tag }}
    {{ $attributes->class($classes) }}
    @if ($tag === 'a') href="{{ $href }}"
    @elseif ($tag === 'button') type="{{ $type }}" @disabled($isDisabled)
    @endif
    @if ($loading) aria-busy="true" @endif
    @if ($isDisabled && $tag !== 'button') aria-disabled="true" @endif
>
    @if ($loading)
        <svg class="{{ $iconClass }} animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
    @elseif ($icon)
        <x-ui.icon :name="$icon" class="{{ $iconClass }}" />
    @endif

    @if (trim($slot->toHtml()) !== '')
        <span>{{ $slot }}</span>
    @endif

    @if ($iconTrailing && ! $loading)
        <x-ui.icon :name="$iconTrailing" class="{{ $iconClass }}" />
    @endif
</{{ $tag }}>
