@props([
    'color' => 'slate',
    'variant' => 'soft',
    'size' => 'md',
    'dot' => false,
    'icon' => null,
    'pill' => true,
])

{{--
    x-ui.badge — status pill. `color` takes a Tailwind colour token, which is exactly what
    every enum's color() method returns, so statuses stay data-driven:

        <x-ui.badge :color="$user->status->color()" :dot="true">{{ $user->status->label() }}</x-ui.badge>
        <x-ui.badge color="brand" variant="solid" size="sm">Core</x-ui.badge>

    Classes are resolved from a literal map (never string-interpolated) so Tailwind's
    scanner always sees them.
--}}

@php
    // token => [soft, solid, outline, dot]
    $palette = [
        'slate' => [
            'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
            'bg-slate-600 text-white ring-slate-600 dark:bg-slate-600 dark:text-white dark:ring-slate-500',
            'bg-transparent text-slate-700 ring-slate-300 dark:text-slate-300 dark:ring-slate-600',
            'bg-slate-500 dark:bg-slate-400',
        ],
        'gray' => [
            'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700',
            'bg-gray-600 text-white ring-gray-600 dark:bg-gray-600 dark:text-white dark:ring-gray-500',
            'bg-transparent text-gray-700 ring-gray-300 dark:text-gray-300 dark:ring-gray-600',
            'bg-gray-500 dark:bg-gray-400',
        ],
        'brand' => [
            'bg-brand-50 text-brand-700 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/25',
            'bg-brand-600 text-white ring-brand-600 dark:bg-brand-500 dark:text-white dark:ring-brand-500',
            'bg-transparent text-brand-700 ring-brand-300 dark:text-brand-300 dark:ring-brand-500/40',
            'bg-brand-500 dark:bg-brand-400',
        ],
        'indigo' => [
            'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-500/25',
            'bg-indigo-600 text-white ring-indigo-600 dark:bg-indigo-500 dark:text-white dark:ring-indigo-500',
            'bg-transparent text-indigo-700 ring-indigo-300 dark:text-indigo-300 dark:ring-indigo-500/40',
            'bg-indigo-500 dark:bg-indigo-400',
        ],
        'violet' => [
            'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/25',
            'bg-violet-600 text-white ring-violet-600 dark:bg-violet-500 dark:text-white dark:ring-violet-500',
            'bg-transparent text-violet-700 ring-violet-300 dark:text-violet-300 dark:ring-violet-500/40',
            'bg-violet-500 dark:bg-violet-400',
        ],
        'purple' => [
            'bg-purple-50 text-purple-700 ring-purple-200 dark:bg-purple-500/10 dark:text-purple-300 dark:ring-purple-500/25',
            'bg-purple-600 text-white ring-purple-600 dark:bg-purple-500 dark:text-white dark:ring-purple-500',
            'bg-transparent text-purple-700 ring-purple-300 dark:text-purple-300 dark:ring-purple-500/40',
            'bg-purple-500 dark:bg-purple-400',
        ],
        'fuchsia' => [
            'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200 dark:bg-fuchsia-500/10 dark:text-fuchsia-300 dark:ring-fuchsia-500/25',
            'bg-fuchsia-600 text-white ring-fuchsia-600 dark:bg-fuchsia-500 dark:text-white dark:ring-fuchsia-500',
            'bg-transparent text-fuchsia-700 ring-fuchsia-300 dark:text-fuchsia-300 dark:ring-fuchsia-500/40',
            'bg-fuchsia-500 dark:bg-fuchsia-400',
        ],
        'pink' => [
            'bg-pink-50 text-pink-700 ring-pink-200 dark:bg-pink-500/10 dark:text-pink-300 dark:ring-pink-500/25',
            'bg-pink-600 text-white ring-pink-600 dark:bg-pink-500 dark:text-white dark:ring-pink-500',
            'bg-transparent text-pink-700 ring-pink-300 dark:text-pink-300 dark:ring-pink-500/40',
            'bg-pink-500 dark:bg-pink-400',
        ],
        'rose' => [
            'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25',
            'bg-rose-600 text-white ring-rose-600 dark:bg-rose-500 dark:text-white dark:ring-rose-500',
            'bg-transparent text-rose-700 ring-rose-300 dark:text-rose-300 dark:ring-rose-500/40',
            'bg-rose-500 dark:bg-rose-400',
        ],
        'red' => [
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/25',
            'bg-red-600 text-white ring-red-600 dark:bg-red-500 dark:text-white dark:ring-red-500',
            'bg-transparent text-red-700 ring-red-300 dark:text-red-300 dark:ring-red-500/40',
            'bg-red-500 dark:bg-red-400',
        ],
        'orange' => [
            'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/25',
            'bg-orange-600 text-white ring-orange-600 dark:bg-orange-500 dark:text-white dark:ring-orange-500',
            'bg-transparent text-orange-700 ring-orange-300 dark:text-orange-300 dark:ring-orange-500/40',
            'bg-orange-500 dark:bg-orange-400',
        ],
        'amber' => [
            'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25',
            'bg-amber-500 text-white ring-amber-500 dark:bg-amber-500 dark:text-slate-950 dark:ring-amber-500',
            'bg-transparent text-amber-700 ring-amber-300 dark:text-amber-300 dark:ring-amber-500/40',
            'bg-amber-500 dark:bg-amber-400',
        ],
        'yellow' => [
            'bg-yellow-50 text-yellow-800 ring-yellow-200 dark:bg-yellow-500/10 dark:text-yellow-300 dark:ring-yellow-500/25',
            'bg-yellow-500 text-white ring-yellow-500 dark:bg-yellow-500 dark:text-slate-950 dark:ring-yellow-500',
            'bg-transparent text-yellow-800 ring-yellow-300 dark:text-yellow-300 dark:ring-yellow-500/40',
            'bg-yellow-500 dark:bg-yellow-400',
        ],
        'lime' => [
            'bg-lime-50 text-lime-700 ring-lime-200 dark:bg-lime-500/10 dark:text-lime-300 dark:ring-lime-500/25',
            'bg-lime-600 text-white ring-lime-600 dark:bg-lime-500 dark:text-slate-950 dark:ring-lime-500',
            'bg-transparent text-lime-700 ring-lime-300 dark:text-lime-300 dark:ring-lime-500/40',
            'bg-lime-500 dark:bg-lime-400',
        ],
        'green' => [
            'bg-green-50 text-green-700 ring-green-200 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-500/25',
            'bg-green-600 text-white ring-green-600 dark:bg-green-500 dark:text-white dark:ring-green-500',
            'bg-transparent text-green-700 ring-green-300 dark:text-green-300 dark:ring-green-500/40',
            'bg-green-500 dark:bg-green-400',
        ],
        'emerald' => [
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25',
            'bg-emerald-600 text-white ring-emerald-600 dark:bg-emerald-500 dark:text-white dark:ring-emerald-500',
            'bg-transparent text-emerald-700 ring-emerald-300 dark:text-emerald-300 dark:ring-emerald-500/40',
            'bg-emerald-500 dark:bg-emerald-400',
        ],
        'teal' => [
            'bg-teal-50 text-teal-700 ring-teal-200 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/25',
            'bg-teal-600 text-white ring-teal-600 dark:bg-teal-500 dark:text-white dark:ring-teal-500',
            'bg-transparent text-teal-700 ring-teal-300 dark:text-teal-300 dark:ring-teal-500/40',
            'bg-teal-500 dark:bg-teal-400',
        ],
        'cyan' => [
            'bg-cyan-50 text-cyan-700 ring-cyan-200 dark:bg-cyan-500/10 dark:text-cyan-300 dark:ring-cyan-500/25',
            'bg-cyan-600 text-white ring-cyan-600 dark:bg-cyan-500 dark:text-white dark:ring-cyan-500',
            'bg-transparent text-cyan-700 ring-cyan-300 dark:text-cyan-300 dark:ring-cyan-500/40',
            'bg-cyan-500 dark:bg-cyan-400',
        ],
        'sky' => [
            'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/25',
            'bg-sky-600 text-white ring-sky-600 dark:bg-sky-500 dark:text-white dark:ring-sky-500',
            'bg-transparent text-sky-700 ring-sky-300 dark:text-sky-300 dark:ring-sky-500/40',
            'bg-sky-500 dark:bg-sky-400',
        ],
        'blue' => [
            'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/25',
            'bg-blue-600 text-white ring-blue-600 dark:bg-blue-500 dark:text-white dark:ring-blue-500',
            'bg-transparent text-blue-700 ring-blue-300 dark:text-blue-300 dark:ring-blue-500/40',
            'bg-blue-500 dark:bg-blue-400',
        ],
    ];

    $sizes = [
        'xs' => 'px-1.5 py-0.5 text-2xs gap-1',
        'sm' => 'px-2 py-0.5 text-xs gap-1',
        'md' => 'px-2.5 py-1 text-xs gap-1.5',
        'lg' => 'px-3 py-1 text-sm gap-1.5',
    ];

    $variantIndex = ['soft' => 0, 'solid' => 1, 'outline' => 2][$variant] ?? 0;
    $tokens = $palette[strtolower((string) $color)] ?? $palette['slate'];

    $classes = implode(' ', [
        'inline-flex max-w-full items-center font-medium ring-1 ring-inset',
        $pill ? 'rounded-full' : 'rounded-md',
        $sizes[$size] ?? $sizes['md'],
        $tokens[$variantIndex],
    ]);
@endphp

<span {{ $attributes->class($classes) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $variantIndex === 1 ? 'bg-white/80' : $tokens[3] }}" aria-hidden="true"></span>
    @endif

    @if ($icon)
        <x-ui.icon :name="$icon" class="h-3.5 w-3.5" />
    @endif

    <span class="truncate">{{ $slot }}</span>
</span>
