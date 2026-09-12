@props([
    'href' => null,
    'icon' => null,
    'variant' => 'default',
    'type' => 'button',
    'badge' => null,
    'active' => false,
])

{{--
    x-ui.dropdown-item — one row inside x-ui.dropdown. role="menuitem" is what the
    keyboard navigation in uiDropdown() looks for, so always use this (or set the role
    yourself) for menu entries.
--}}

@php
    $variants = [
        'default' => 'text-slate-700 hover:bg-slate-100 hover:text-slate-900 focus-visible:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white dark:focus-visible:bg-slate-800',
        'danger' => 'text-rose-600 hover:bg-rose-50 hover:text-rose-700 focus-visible:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10 dark:hover:text-rose-300 dark:focus-visible:bg-rose-500/10',
        'muted' => 'text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200',
    ];

    $classes = implode(' ', [
        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors',
        $variants[$variant] ?? $variants['default'],
        $active ? 'bg-slate-100 font-medium dark:bg-slate-800' : '',
    ]);

    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    {{ $attributes->class($classes) }}
    role="menuitem"
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
>
    @if ($icon)
        <x-ui.icon :name="$icon" class="h-4 w-4 shrink-0 opacity-70" />
    @endif

    <span class="min-w-0 flex-1 truncate">{{ $slot }}</span>

    @if (filled($badge))
        <span class="ml-auto shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-2xs font-semibold text-slate-600 tabular-nums dark:bg-slate-800 dark:text-slate-300">{{ $badge }}</span>
    @endif
</{{ $tag }}>
