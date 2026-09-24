@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'padded' => true,
    'compact' => false,
    'hover' => false,
    'level' => 'h2',
])

{{--
    x-ui.card — the canonical surface.

        <x-ui.card title="Recent activity" subtitle="Last 7 days">
            <x-slot:actions><x-ui.button size="sm" variant="secondary">View all</x-ui.button></x-slot:actions>
            …body…
            <x-slot:footer>12 of 340 entries</x-slot:footer>
        </x-ui.card>

    Pass :padded="false" when the body is a table or list that should run edge to edge.

    **`level` is the heading level of the card title, and it defaults to h2** (phase-24-25 section
    6.5). A card sits directly under the page's h1, so the h3 this used to hard-code skipped a rung:
    somebody navigating by heading level had no way to tell whether they had jumped a section or
    landed inside one. Pass level="h3" for a card genuinely nested inside another section.
    A11Y-06 asserts the order on every screen it walks.
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
                        {{-- Rendered through $level; see the note above. The class list is the
                             same whatever the level, because the size is the card's, not the
                             document outline's. --}}
                        <{{ $level }} class="truncate text-sm font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</{{ $level }}>
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
