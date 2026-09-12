@props([
    'icon' => 'ellipsis-vertical',
    'label' => null,
    'variant' => 'ghost',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'loading' => false,
    'disabled' => false,
    'badge' => false,
])

{{--
    x-ui.icon-button — a square, icon-only control. `label` is REQUIRED for accessibility:
    it becomes the aria-label and the native tooltip.

        <x-ui.icon-button icon="pencil" label="Edit user" :href="route('admin.users.edit', $user)" />
        <x-ui.icon-button icon="bell" label="Notifications" :badge="true" />
--}}

@php
    $base = 'relative inline-flex items-center justify-center rounded-lg border transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-brand-400/40 dark:focus-visible:ring-offset-slate-950';

    $sizes = [
        'xs' => 'h-7 w-7',
        'sm' => 'h-8 w-8',
        'md' => 'h-9 w-9',
        'lg' => 'h-10 w-10',
    ];

    $iconSizes = [
        'xs' => 'h-3.5 w-3.5',
        'sm' => 'h-4 w-4',
        'md' => 'h-[1.125rem] w-[1.125rem]',
        'lg' => 'h-5 w-5',
    ];

    $variants = [
        'primary' => 'border-transparent bg-brand-600 text-white shadow-sm hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400',
        'secondary' => 'border-slate-300 bg-white text-slate-600 shadow-sm hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white',
        'ghost' => 'border-transparent bg-transparent text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
        'danger' => 'border-transparent bg-transparent text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10',
    ];

    $size = array_key_exists($size, $sizes) ? $size : 'md';
    $variant = array_key_exists($variant, $variants) ? $variant : 'ghost';
    $isDisabled = (bool) $disabled || (bool) $loading;

    $classes = trim(implode(' ', array_filter([
        $base,
        $sizes[$size],
        $variants[$variant],
        $isDisabled ? 'cursor-not-allowed opacity-60' : '',
    ])));

    $tag = $href && ! $isDisabled ? 'a' : ($href ? 'span' : 'button');
@endphp

<{{ $tag }}
    {{ $attributes->class($classes) }}
    @if ($tag === 'a') href="{{ $href }}"
    @elseif ($tag === 'button') type="{{ $type }}" @disabled($isDisabled)
    @endif
    @if ($label) aria-label="{{ $label }}" title="{{ $label }}" @endif
    @if ($loading) aria-busy="true" @endif
>
    @if ($loading)
        <svg class="{{ $iconSizes[$size] }} animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
    @else
        <x-ui.icon :name="$icon" class="{{ $iconSizes[$size] }}" />
    @endif

    @if ($badge)
        <span class="absolute right-1.5 top-1.5 flex h-2 w-2">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
            <span class="relative inline-flex h-2 w-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-slate-900"></span>
        </span>
        <span class="sr-only">(unread)</span>
    @endif

    {{ $slot }}
</{{ $tag }}>
