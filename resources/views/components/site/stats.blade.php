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
    without the card, for use on a busy background.
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

    $panel = $variant !== 'plain';
@endphp

@if ($count > 0)
    <div {{ $attributes->class([
        'overflow-hidden rounded-2xl' => $panel,
        'bg-white/90 shadow-sm ring-1 ring-slate-200/80 backdrop-blur dark:bg-slate-950/70 dark:ring-white/10' => $panel,
    ]) }}>
        <ul role="list" @class(['grid', $columns, '-ml-px -mt-px' => $panel, 'gap-8' => ! $panel])>
            @foreach ($resolved as $stat)
                <li @class([
                    'flex',
                    'border-l border-t border-slate-200/80 px-4 py-7 sm:px-6 dark:border-white/10' => $panel,
                ])>
                    <x-site.stat :item="$stat" :tone="$tone" class="w-full" />
                </li>
            @endforeach
        </ul>
    </div>
@endif
