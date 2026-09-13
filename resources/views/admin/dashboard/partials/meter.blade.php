{{--
    A single horizontal bar split into coloured segments — the one bar shape this dashboard uses,
    so "62% of accounts are active" and "18 of 79 modules are on" read the same way.

    @include('admin.dashboard.partials.meter', [
        'segments' => [
            ['color' => 'emerald', 'share' => 62, 'label' => 'Active', 'value' => '11'],
            ['color' => 'slate',   'share' => 38, 'label' => 'Inactive'],
        ],
        'height' => 'h-2',      // optional
        'legend' => false,      // optional: render the labels underneath
    ])

    Colour classes come from a literal map, never interpolation, so Tailwind's scanner sees every
    one of them. `color` takes the same token every enum's color() returns.
--}}

@php
    $fills = [
        'brand' => 'bg-brand-500',
        'indigo' => 'bg-indigo-500',
        'violet' => 'bg-violet-500',
        'purple' => 'bg-purple-500',
        'emerald' => 'bg-emerald-500',
        'green' => 'bg-green-500',
        'teal' => 'bg-teal-500',
        'cyan' => 'bg-cyan-500',
        'sky' => 'bg-sky-500',
        'blue' => 'bg-blue-500',
        'amber' => 'bg-amber-500',
        'yellow' => 'bg-yellow-500',
        'orange' => 'bg-orange-500',
        'rose' => 'bg-rose-500',
        'red' => 'bg-red-500',
        'pink' => 'bg-pink-500',
        'slate' => 'bg-slate-400 dark:bg-slate-600',
        'gray' => 'bg-gray-400 dark:bg-gray-600',
    ];

    $dots = [
        'brand' => 'bg-brand-500',
        'indigo' => 'bg-indigo-500',
        'violet' => 'bg-violet-500',
        'purple' => 'bg-purple-500',
        'emerald' => 'bg-emerald-500',
        'green' => 'bg-green-500',
        'teal' => 'bg-teal-500',
        'cyan' => 'bg-cyan-500',
        'sky' => 'bg-sky-500',
        'blue' => 'bg-blue-500',
        'amber' => 'bg-amber-500',
        'yellow' => 'bg-yellow-500',
        'orange' => 'bg-orange-500',
        'rose' => 'bg-rose-500',
        'red' => 'bg-red-500',
        'pink' => 'bg-pink-500',
        'slate' => 'bg-slate-400 dark:bg-slate-600',
        'gray' => 'bg-gray-400 dark:bg-gray-600',
    ];

    $meterSegments = collect($segments ?? [])
        ->filter(fn ($segment): bool => is_array($segment) && (float) ($segment['share'] ?? 0) > 0)
        ->values();

    $meterHeight = $height ?? 'h-2';
    $meterLegend = $legend ?? false;
@endphp

<div
    class="flex w-full overflow-hidden rounded-full bg-slate-100 {{ $meterHeight }} dark:bg-slate-800"
    role="img"
    aria-label="{{ collect($segments ?? [])->map(fn ($s) => ($s['label'] ?? '').' '.(int) ($s['share'] ?? 0).'%')->implode(', ') }}"
>
    @foreach ($meterSegments as $segment)
        <div
            class="{{ $fills[$segment['color'] ?? 'slate'] ?? $fills['slate'] }} transition-all duration-300"
            style="width: {{ max(1, min(100, (float) $segment['share'])) }}%"
            title="{{ $segment['label'] ?? '' }} · {{ (int) $segment['share'] }}%"
        ></div>
    @endforeach
</div>

@if ($meterLegend)
    <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1.5">
        @foreach (($segments ?? []) as $segment)
            <span class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dots[$segment['color'] ?? 'slate'] ?? $dots['slate'] }}"></span>
                {{ $segment['label'] ?? '' }}
                @if (isset($segment['value']))
                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $segment['value'] }}</span>
                @endif
            </span>
        @endforeach
    </div>
@endif
