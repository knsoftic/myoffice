{{--
    A public 1–5 star rating (phase-04 §2.10 / §2.11 `rating`). A null or out-of-range rating renders nothing —
    never zero stars, which would read as the worst possible review.

    @include('site.marketing.partials.stars', ['rating' => 4, 'size' => 'h-4 w-4'])
--}}

@php
    $starRating = is_numeric($rating ?? null) ? (int) $rating : null;
    $starRating = $starRating !== null && $starRating >= 1 && $starRating <= 5 ? $starRating : null;
@endphp

@if ($starRating !== null)
    <span class="inline-flex items-center gap-0.5" role="img" aria-label="Rated {{ $starRating }} out of 5">
        @for ($star = 1; $star <= 5; $star++)
            <svg class="{{ $size ?? 'h-4 w-4' }} {{ $star <= $starRating ? 'text-amber-400' : 'text-slate-200 dark:text-slate-700' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                <path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" />
            </svg>
        @endfor
    </span>
@endif
