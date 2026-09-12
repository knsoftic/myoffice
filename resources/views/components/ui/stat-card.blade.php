@props([
    'label' => null,
    'value' => null,
    'delta' => null,
    'deltaLabel' => null,
    'trend' => null,
    'icon' => null,
    'color' => 'brand',
    'href' => null,
    'loading' => false,
])

{{--
    x-ui.stat-card — one KPI.

        <x-ui.stat-card label="Active students" :value="1284" icon="academic-cap"
                        delta="+12.4%" trend="up" delta-label="vs last month" />
        <x-ui.stat-card label="Outstanding fees" :value="money($due)" icon="banknotes"
                        color="amber" :href="route('admin.student-fees.index')" />
        <x-ui.stat-card :loading="true" />   // skeleton while data loads
--}}

@php
    $iconTints = [
        'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
        'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    ];

    $trendStyles = [
        'up' => ['text-emerald-600 dark:text-emerald-400', 'arrow-trending-up'],
        'down' => ['text-rose-600 dark:text-rose-400', 'arrow-trending-down'],
        'flat' => ['text-slate-500 dark:text-slate-400', 'minus'],
        'neutral' => ['text-slate-500 dark:text-slate-400', 'minus'],
    ];

    [$trendClass, $trendIcon] = $trendStyles[(string) $trend] ?? ['text-slate-500 dark:text-slate-400', null];
    $tint = $iconTints[(string) $color] ?? $iconTints['brand'];

    $shell = 'group relative block overflow-hidden rounded-xl bg-white p-4 ring-1 ring-slate-200/70 shadow-sm transition duration-150 sm:p-5 dark:bg-slate-900 dark:ring-slate-800';
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    {{ $attributes->class([
        $shell,
        'hover:shadow-card-hover hover:ring-brand-300/70 dark:hover:ring-brand-500/40' => (bool) $href,
    ]) }}
    @if ($href) href="{{ $href }}" @endif
>
    @if ($loading)
        <div class="animate-pulse-soft space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div class="h-3 w-24 rounded bg-slate-200 dark:bg-slate-800"></div>
                <div class="h-9 w-9 rounded-lg bg-slate-200 dark:bg-slate-800"></div>
            </div>
            <div class="h-7 w-32 rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="h-3 w-20 rounded bg-slate-200 dark:bg-slate-800"></div>
        </div>
    @else
        <div class="flex items-start justify-between gap-3">
            <p class="truncate text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                {{ $label }}
            </p>

            @if ($icon)
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $tint }}">
                    <x-ui.icon :name="$icon" class="h-[1.125rem] w-[1.125rem]" />
                </span>
            @endif
        </div>

        <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
            {{ $value }}
        </p>

        @if (filled($delta) || filled($deltaLabel) || isset($footer))
            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                @if (filled($delta))
                    <span class="inline-flex items-center gap-1 font-semibold {{ $trendClass }} tabular-nums">
                        @if ($trendIcon)
                            <x-ui.icon :name="$trendIcon" class="h-3.5 w-3.5" />
                        @endif
                        {{ $delta }}
                    </span>
                @endif

                @if (filled($deltaLabel))
                    <span class="text-slate-500 dark:text-slate-400">{{ $deltaLabel }}</span>
                @endif

                @isset($footer)
                    <span class="text-slate-500 dark:text-slate-400">{{ $footer }}</span>
                @endisset
            </div>
        @endif

        @if ($href)
            {{-- Brand accent that lights up on hover, so the card reads as clickable. --}}
            <span class="pointer-events-none absolute inset-x-0 bottom-0 h-0.5 scale-x-0 bg-brand-500 transition-transform duration-150 group-hover:scale-x-100" aria-hidden="true"></span>
        @endif
    @endif

    {{ $slot }}
</{{ $tag }}>
