@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'padded' => true,
    'compact' => false,
    'hover' => false,
])

{{--
    x-ui.card — the canonical surface.

        <x-ui.card title="Recent activity" subtitle="Last 7 days">
            <x-slot:actions><x-ui.button size="sm" variant="secondary">View all</x-ui.button></x-slot:actions>
            …body…
            <x-slot:footer>12 of 340 entries</x-slot:footer>
        </x-ui.card>

    Pass :padded="false" when the body is a table or list that should run edge to edge.
--}}

@php
    $hasHeader = filled($title) || filled($subtitle) || isset($actions);
    $bodyPadding = $padded ? ($compact ? 'p-4' : 'p-4 sm:p-5') : '';
@endphp

<div {{ $attributes->class([
    'overflow-hidden rounded-xl bg-white ring-1 ring-slate-200/70 shadow-sm transition duration-150 dark:bg-slate-900 dark:ring-slate-800',
    'hover:shadow-card-hover hover:ring-slate-300/70 dark:hover:ring-slate-700' => $hover,
]) }}>
    @if ($hasHeader)
        <div class="flex items-start justify-between gap-3 border-b border-slate-200/80 px-4 py-3.5 sm:px-5 dark:border-slate-800">
            <div class="flex min-w-0 items-start gap-3">
                @if ($icon)
                    <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                        <x-ui.icon :name="$icon" class="h-4 w-4" />
                    </span>
                @endif

                <div class="min-w-0">
                    @if (filled($title))
                        <h3 class="truncate text-sm font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h3>
                    @endif

                    @if (filled($subtitle))
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>

            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class([$bodyPadding => $bodyPadding !== ''])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-slate-200/80 bg-slate-50/70 px-4 py-3 text-xs text-slate-500 sm:px-5 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
            {{ $footer }}
        </div>
    @endisset
</div>
