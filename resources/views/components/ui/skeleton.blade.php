@props([
    'variant' => 'text',
    'count' => 1,
    'columns' => 5,
])

{{--
    x-ui.skeleton — loading placeholders matched to the real layout.

        <x-ui.skeleton variant="text" :count="3" />
        <x-ui.skeleton variant="row" :count="8" :columns="6" />   // inside a <tbody>
        <x-ui.skeleton variant="stat" :count="4" />
        <x-ui.skeleton variant="card" :count="2" />
--}}

@php
    $count = max(1, (int) $count);
    $columns = max(1, (int) $columns);
    $bar = 'relative overflow-hidden rounded bg-slate-200/80 dark:bg-slate-800 skeleton-sweep';
    // Varied widths read as real content rather than a grey block.
    $widths = ['w-full', 'w-11/12', 'w-4/5', 'w-5/6', 'w-3/4'];
@endphp

@if ($variant === 'row')
    @for ($i = 0; $i < $count; $i++)
        <tr class="border-b border-slate-100 last:border-0 dark:border-slate-800">
            @for ($c = 0; $c < $columns; $c++)
                <td class="px-4 py-3.5">
                    <div class="{{ $bar }} h-3 {{ $c === 0 ? 'w-3/4' : $widths[($i + $c) % count($widths)] }}"></div>
                </td>
            @endfor
        </tr>
    @endfor
@elseif ($variant === 'stat')
    <div {{ $attributes->class('card-grid') }}>
        @for ($i = 0; $i < $count; $i++)
            <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200/70 shadow-sm sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div class="{{ $bar }} h-3 w-24"></div>
                    <div class="{{ $bar }} h-9 w-9 rounded-lg"></div>
                </div>
                <div class="{{ $bar }} mt-3 h-7 w-32"></div>
                <div class="{{ $bar }} mt-3 h-3 w-20"></div>
            </div>
        @endfor
    </div>
@elseif ($variant === 'card')
    <div {{ $attributes->class('space-y-4') }}>
        @for ($i = 0; $i < $count; $i++)
            <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200/70 shadow-sm sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center gap-3">
                    <div class="{{ $bar }} h-10 w-10 rounded-full"></div>
                    <div class="flex-1 space-y-2">
                        <div class="{{ $bar }} h-3 w-1/3"></div>
                        <div class="{{ $bar }} h-3 w-1/4"></div>
                    </div>
                </div>
                <div class="mt-4 space-y-2">
                    <div class="{{ $bar }} h-3 w-full"></div>
                    <div class="{{ $bar }} h-3 w-11/12"></div>
                    <div class="{{ $bar }} h-3 w-2/3"></div>
                </div>
            </div>
        @endfor
    </div>
@else
    <div {{ $attributes->class('space-y-2') }}>
        @for ($i = 0; $i < $count; $i++)
            <div class="{{ $bar }} h-3 {{ $widths[$i % count($widths)] }}"></div>
        @endfor
    </div>
@endif
