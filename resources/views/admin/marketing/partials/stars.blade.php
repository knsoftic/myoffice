{{--
    A 1–5 rating as stars (phase-04 §2.10 `rating`: unsignedTinyInteger 1–5, null renders no stars).

    @include('admin.marketing.partials.stars', ['rating' => $testimonial->rating, 'size' => 'h-3.5 w-3.5'])

    Null or out of range prints a muted dash — never zero stars, which would read as a one-star review.
--}}

@php
    $ratingValue = is_numeric($rating ?? null) ? (int) $rating : null;
    $ratingValue = $ratingValue !== null && $ratingValue >= 1 && $ratingValue <= 5 ? $ratingValue : null;
    $starSize = $size ?? 'h-3.5 w-3.5';
@endphp

@if ($ratingValue === null)
    <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
@else
    <span class="inline-flex items-center gap-0.5" role="img" aria-label="Rated {{ $ratingValue }} out of 5">
        @for ($star = 1; $star <= 5; $star++)
            <svg class="{{ $starSize }} {{ $star <= $ratingValue ? 'text-amber-400 dark:text-amber-300' : 'text-slate-200 dark:text-slate-700' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">
                <path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" />
            </svg>
        @endfor
    </span>
@endif
