@props([
    'item' => null,
    'value' => null,
    'label' => null,
    'prefix' => null,
    'suffix' => null,
    'icon' => null,
    'tone' => 'light',
    'align' => 'center',
    'gradient' => false,
    'size' => 'default',
])

{{--
    x-site.stat — one statistic of requirement §9 (projects completed, happy clients, students
    trained, active courses, team members, years of experience).

        <x-site.stat :item="$stat" tone="dark" />

    `item` is one published `statistic` repeater row:

        ['content' => ['label' => 'Students Trained', 'prefix' => null, 'suffix' => '+', 'icon' => …],
         'value'   => '1248.00' | null,      // already resolved by StatisticsProvider (§6.11)
         'metric'  => 'students_trained', 'value_mode' => 'auto']

    **INV-12, the rule that matters here: a null value renders NOTHING AT ALL.** Not a zero, not the
    caption on its own, not a dash. A statistics strip that says "0 Students Trained" because the
    institute module is not installed yet is worse than a strip with five numbers in it, and
    `StatisticsProvider::valueFor()` returns null precisely so this component can disappear.

    Values arrive as **decimal strings** and are formatted by `app_number()`, which is float-free and
    honours the `localization` separators — `"1248.00"` renders as `1,248` (FT-31). A value that
    carries real decimals keeps two of them; a whole number shows none.
--}}

@php
    $content = (array) data_get($item, 'content', []);

    $rawValue = $value ?? data_get($item, 'value');
    $rawValue = is_string($rawValue) || is_int($rawValue) || is_float($rawValue) ? (string) $rawValue : null;

    $hasValue = $rawValue !== null && trim($rawValue) !== '';

    // "1500.00" → 1,500 · "4.25" → 4.25. No float arithmetic: the decimals are read off the string.
    $decimals = 0;

    if ($hasValue && str_contains($rawValue, '.')) {
        $fraction = rtrim(substr($rawValue, strpos($rawValue, '.') + 1), '0');
        $decimals = strlen($fraction) > 0 ? min(2, strlen($fraction)) : 0;
    }

    $formatted = $hasValue ? app_number($rawValue, $decimals) : null;

    $caption = (string) ($label ?? data_get($content, 'label') ?? '');
    $statPrefix = (string) ($prefix ?? data_get($content, 'prefix') ?? '');
    $statSuffix = (string) ($suffix ?? data_get($content, 'suffix') ?? '');
    // `:icon="false"` suppresses the item's own icon when the parent draws one (the hero's chips).
    $statIcon = $icon === false ? null : ($icon ?? data_get($content, 'icon'));
    $small = $size === 'sm';

    $valueTone = match (true) {
        $tone === 'dark' => 'text-white',
        // Clipped gradient text; the colour stops keep the contrast of the plain figure in both themes.
        (bool) $gradient => 'bg-gradient-to-br from-slate-900 via-slate-800 to-brand-600 bg-clip-text text-transparent dark:from-white dark:via-white dark:to-brand-300',
        default => 'text-slate-900 dark:text-white',
    };
    $labelTone = $tone === 'dark' ? 'text-slate-300' : 'text-slate-500 dark:text-slate-400';
    $iconTone = $tone === 'dark' ? 'text-brand-300' : 'text-brand-600 dark:text-brand-400';

    $alignment = $align === 'left' ? 'items-start text-left' : 'items-center text-center';
@endphp

@if ($hasValue)
    <div {{ $attributes->class(['flex flex-col', 'gap-0.5' => $small, 'gap-1' => ! $small, $alignment]) }}>
        @if (filled($statIcon))
            <x-ui.icon :name="$statIcon" class="mb-1 h-6 w-6 {{ $iconTone }}" />
        @endif

        <p @class(['font-bold tracking-tight tabular-nums', 'text-lg leading-tight sm:text-xl' => $small, 'text-3xl sm:text-4xl' => ! $small, $valueTone])>
            @if ($statPrefix !== '')<span @class(['font-semibold', 'text-sm' => $small, 'text-xl sm:text-2xl' => ! $small])>{{ $statPrefix }}</span>@endif{{ $formatted }}@if ($statSuffix !== '')<span @class(['font-semibold', 'text-sm' => $small, 'text-xl sm:text-2xl' => ! $small])>{{ $statSuffix }}</span>@endif
        </p>

        @if ($caption !== '')
            <p @class(['text-xs font-medium', 'uppercase tracking-wider' => ! $small, $labelTone])>{{ $caption }}</p>
        @endif
    </div>
@endif
