@props([
    'items' => [],
    'tone' => 'light',
    'variant' => 'panel',
])

{{--
    x-site.stats — a strip of requirement §9 statistics (the hero's six, the about section's own).

        <x-site.stats :items="$items['statistic'] ?? []" />
        <x-site.stats :items="$items['statistic'] ?? []" variant="plain" />

    Resolution happens HERE, once, for every partial that shows statistics (integration note K-4):
    a published `auto` item carries `value = null` and `is_live = true`, because a live count must not
    freeze at publish time. So:

      · `manual` item -> its `value` (the typed decimal string);
      · `auto` item   -> `StatisticsProvider` (its `valueForSnapshot(array)` when it has one, otherwise
                         `resolve(StatisticMetric)`), falling back to `manual_value`;
      · still null    -> the item is dropped before layout (INV-12): no "0", no orphan caption, and
                         the grid is sized for the statistics that actually render.

    When the provider class is not installed yet, a live item simply falls back to its manual value —
    the honest behaviour for a metric whose module does not exist.

    Variants: `panel` is a card of cells separated by hairlines (each cell draws its own top and left
    rule and the card clips the outer ones, so a short last row leaves no grey hole); `plain` is the grid
    without the card, for use on a busy background. `cards` gives every statistic its own glass card with a
    gradient figure — the hero's strip, where the numbers carry the page.
--}}

@php
    use App\Enums\Cms\StatisticMetric;

    $providerClass = 'App\\Services\\Cms\\StatisticsProvider';
    $provider = class_exists($providerClass) ? rescue(static fn () => app($providerClass), null) : null;

    $resolved = collect(is_iterable($items) ? $items : [])
        ->map(static function ($item) use ($provider): array {
            $item = is_array($item) ? $item : (array) $item;
            $live = (bool) ($item['is_live'] ?? false) || ($item['value_mode'] ?? null) === 'auto';
            $value = $item['value'] ?? null;

            if ($live) {
                $value = null;

                if ($provider !== null) {
                    $value = rescue(static function () use ($provider, $item): mixed {
                        if (method_exists($provider, 'valueForSnapshot')) {
                            return $provider->valueForSnapshot($item);
                        }

                        $metric = StatisticMetric::tryFrom((string) ($item['metric'] ?? ''));

                        return $metric !== null && $metric->isLive() && method_exists($provider, 'resolve')
                            ? $provider->resolve($metric)
                            : null;
                    }, null);
                }

                $value ??= $item['manual_value'] ?? null;
            }

            $item['value'] = is_string($value) || is_int($value) ? (string) $value : null;

            return $item;
        })
        ->filter(static fn (array $item): bool => $item['value'] !== null
            && trim($item['value']) !== ''
            && trim((string) data_get($item, 'content.label', '')) !== '')
        ->values();

    $count = $resolved->count();

    // Full class names only, so Tailwind's scanner sees every one of them.
    $columns = match (true) {
        $count <= 1 => 'grid-cols-1',
        $count === 2 => 'grid-cols-2',
        $count === 3 => 'grid-cols-1 sm:grid-cols-3',
        $count === 4 => 'grid-cols-2 lg:grid-cols-4',
        $count === 5 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
        $count === 6 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
        default => 'grid-cols-2 sm:grid-cols-4',
    };

    // `cards`: each statistic its own glass card — the hero's strip, where the numbers are the point.
    $cards = $variant === 'cards';
    // `chips`: up to three small glass chips, laid out by the parent (the hero's dashboard preview floats them on
    // its frame from lg up; below that they sit in a row under it).
    $chips = $variant === 'chips';
    $panel = ! $cards && ! $chips && $variant !== 'plain';
@endphp

@if ($count > 0 && $chips)
    @php
        // Full class names, so Tailwind sees them: where each chip sits on the frame from lg up.
        $spots = [
            'lg:absolute lg:-left-6 lg:top-20 xl:-left-14',
            'lg:absolute lg:-right-6 lg:top-1/3 xl:-right-14',
            'lg:absolute lg:-bottom-6 lg:left-1/3',
        ];
        $floats = ['fx-float', 'fx-float fx-float-late', 'fx-float fx-float-later'];
    @endphp
    <ul role="list" {{ $attributes->class(['mt-6 grid gap-3 sm:grid-cols-3 lg:mt-0 lg:contents']) }}>
        @foreach ($resolved->take(3)->values() as $i => $stat)
            <li class="{{ $spots[$i] }} {{ $floats[$i] }} z-10 flex items-center gap-3 rounded-2xl border border-slate-200/80 bg-white/85 px-4 py-3 shadow-xl shadow-brand-900/10 backdrop-blur-xl dark:border-white/10 dark:bg-slate-900/80 dark:shadow-black/40">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-md shadow-brand-600/30" aria-hidden="true">
                    <x-ui.icon :name="data_get($stat, 'content.icon') ?: ['chart-bar', 'users', 'academic-cap'][$i]" class="h-4 w-4" />
                </span>
                <x-site.stat :item="$stat" size="sm" align="left" :icon="false" />
            </li>
        @endforeach
    </ul>
@elseif ($count > 0)
    <div {{ $attributes->class([
        'overflow-hidden rounded-2xl' => $panel,
        'bg-white/90 shadow-sm ring-1 ring-slate-200/80 backdrop-blur dark:bg-slate-950/70 dark:ring-white/10' => $panel,
    ]) }}>
        <ul role="list" @class(['grid', $columns, '-ml-px -mt-px' => $panel, 'gap-4 sm:gap-5' => $cards, 'gap-8' => ! $panel && ! $cards])>
            @foreach ($resolved as $stat)
                <li @class([
                    'flex',
                    'border-l border-t border-slate-200/80 px-4 py-7 sm:px-6 dark:border-white/10' => $panel,
                    'group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white/70 px-5 py-7 shadow-sm backdrop-blur transition duration-300 hover:-translate-y-0.5 hover:border-brand-300/70 hover:shadow-card-hover sm:px-6 dark:border-white/10 dark:bg-white/[0.04] dark:hover:border-brand-400/40' => $cards,
                ])>
                    @if ($cards)
                        <span class="pointer-events-none absolute inset-x-6 top-0 h-px bg-gradient-to-r from-transparent via-brand-500/70 to-transparent opacity-60 transition duration-300 group-hover:opacity-100" aria-hidden="true"></span>
                        <span class="pointer-events-none absolute -bottom-12 left-1/2 h-24 w-40 -translate-x-1/2 rounded-full bg-brand-500/10 opacity-0 blur-2xl transition duration-300 group-hover:opacity-100" aria-hidden="true"></span>
                    @endif
                    <x-site.stat :item="$stat" :tone="$tone" :gradient="$cards" class="relative w-full" />
                </li>
            @endforeach
        </ul>
    </div>
@endif
