{{--
    Character counter for one field (phase-03 §8.5 / §8.12 `x-cms.length-meter`).

    @include('admin.cms.partials.length-meter', [
        'for' => 'field-content-heading',  // the input's id
        'max' => 120,                      // hard limit (rose past it); null = none
        'idealMin' => 50, 'idealMax' => 60, // SEO sweet spot (amber outside it); optional
    ])

    Amber at 90% of the limit or outside the ideal range, rose past the limit. The server enforces the
    limit; this only tells the editor before they press Save.
--}}

@php
    $max = isset($max) && $max !== null ? (int) $max : 0;
    $idealMin = $idealMin ?? null;
    $idealMax = $idealMax ?? null;

    $config = array_filter([
        'for' => (string) $for,
        'max' => $max,
        'idealMin' => $idealMin,
        'idealMax' => $idealMax,
    ], static fn (mixed $value): bool => $value !== null);
@endphp

<div
    x-data="cmsLengthMeter(@js($config))"
    class="mt-1 flex items-center gap-2 text-2xs tabular-nums"
    aria-live="polite"
>
    <div class="h-1 w-16 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800" aria-hidden="true">
        <div
            class="h-full rounded-full transition-all duration-150"
            x-bind:style="`width: ${percent}%`"
            x-bind:class="{
                'bg-rose-500 dark:bg-rose-400': tone === 'rose',
                'bg-amber-500 dark:bg-amber-400': tone === 'amber',
                'bg-emerald-500 dark:bg-emerald-400': tone === 'emerald',
                'bg-slate-300 dark:bg-slate-600': tone === 'slate',
            }"
        ></div>
    </div>

    <span
        x-bind:class="{
            'text-rose-600 dark:text-rose-400': tone === 'rose',
            'text-amber-600 dark:text-amber-400': tone === 'amber',
            'text-slate-500 dark:text-slate-400': tone === 'emerald' || tone === 'slate',
        }"
    >
        <span x-text="count">0</span>@if ($max > 0) / {{ $max }}@endif
        @if ($idealMin !== null && $idealMax !== null)
            <span class="text-slate-400 dark:text-slate-500">· ideal {{ $idealMin }}–{{ $idealMax }}</span>
        @endif
    </span>
</div>
