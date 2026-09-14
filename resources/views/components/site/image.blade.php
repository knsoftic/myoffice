@props([
    'media' => null,
    'asset' => null,
    'profile' => null,
    'eager' => false,
    'alt' => null,
    'sizes' => null,
    'ratio' => null,
    'imgClass' => 'h-full w-full object-cover',
    'lazy' => true,
])

{{--
    x-site.image — the ONLY way a public view renders an image (phase-03 §6.8, §8.14, CLAUDE.md
    rule "no bare <img> in site/ views").

        <x-site.image :media="$media['hero_image'] ?? null" profile="hero" :eager="true" />
        <x-site.image :media="$item['media'] ?? null" profile="card" ratio="16/9" />

    `media` is the **published media array** a section snapshot carries for one role — never a model,
    so a missing relation can never N+1 or explode in a view (§8.14). Every key is optional and the
    component degrades in this order:

        url          the src of the fallback <img>                     (required — no url, no render)
        srcset       the fallback format's srcset, widths included     (omitted when absent)
        webp_srcset  a WebP <source srcset>                            (omitted when absent)
        sizes        the `sizes` attribute                             (falls back to the profile's)
        width/height intrinsic pixels, so there is no layout shift     (omitted when absent)
        alt          alt text — `alt_text` on media_assets             (falls back to the `alt` prop)
        is_video     true renders <video> with `poster` instead        (hero background video)
        poster       the poster image url for a video

    A `MediaAsset` model is accepted too — the contract's own signature (§6.8, phase-04 §6.6):

        <x-site.image :asset="$asset" profile="card" />

    and a model passed as `:media` is treated the same way. Either is turned into the snapshot shape by
    `MediaService::toSnapshot($asset, $profile)` (url, srcset, WebP srcset, sizes, dimensions, alt), so it
    renders exactly what a published section would. A section partial should still be handed its snapshot
    array, which costs no query.

    It never renders a broken `srcset`: when the asset's derivatives are not usable
    (`derivatives_status` not ready/skipped) the snapshot simply carries no `srcset` and the original
    is rendered at its intrinsic size (§6.8).

    Laziness: everything is `loading="lazy" decoding="async"` except `:eager="true"`, which is the
    hero image — it gets `fetchpriority="high"` instead, because it is the Largest Contentful Paint
    element and lazy-loading it would be a performance bug (§8.14).
--}}

@php
    use App\Enums\Cms\ImageProfile as ImageProfileCase;
    use App\Models\Cms\MediaAsset;
    use App\Services\Cms\MediaService;
    use App\Support\Cms\ImageProfile as ImageProfileSpec;

    // A model (`:asset`, or a model handed to `:media`) becomes the snapshot array a section carries.
    $model = $asset instanceof MediaAsset ? $asset : ($media instanceof MediaAsset ? $media : null);

    if ($model !== null) {
        $media = app(MediaService::class)->toSnapshot(
            $model,
            filled($profile) ? ImageProfileCase::tryFrom((string) $profile) : null,
        );
    }

    $url = (string) (data_get($media, 'url') ?? '');
    $isVideo = (bool) data_get($media, 'is_video', false);
    $poster = (string) (data_get($media, 'poster') ?? '');

    $altText = (string) (data_get($media, 'alt') ?? data_get($media, 'alt_text') ?? $alt ?? '');

    $srcset = (string) (data_get($media, 'srcset') ?? '');
    $webpSrcset = (string) (data_get($media, 'webp_srcset') ?? '');

    // `sizes` comes from the snapshot, then from the profile's own declaration (§6.8's table),
    // never from a number guessed in a view.
    $sizesAttribute = (string) ($sizes
        ?? data_get($media, 'sizes')
        ?? (filled($profile) && ImageProfileSpec::exists((string) $profile)
            ? ImageProfileSpec::sizesAttribute((string) $profile)
            : '100vw'));

    $width = data_get($media, 'width');
    $height = data_get($media, 'height');

    $loading = $eager ? 'eager' : ($lazy ? 'lazy' : null);

    $ratioStyle = filled($ratio) ? 'aspect-ratio:'.str_replace('/', ' / ', (string) $ratio).';' : '';
@endphp

@if ($url !== '')
    @if ($isVideo)
        {{--
            A background video is muted, looped and autoplayed with no controls — browsers block
            anything else (§8.7). The poster carries the first paint and is what the CSS swaps in
            below `md` and under `prefers-reduced-motion`, handled by the hero partial.
        --}}
        <video
            {{ $attributes->class(['block', 'h-full w-full object-cover' => $imgClass !== '']) }}
            @if ($ratioStyle !== '') style="{{ $ratioStyle }}" @endif
            autoplay
            muted
            loop
            playsinline
            preload="metadata"
            @if ($poster !== '') poster="{{ $poster }}" @endif
            aria-hidden="true"
            tabindex="-1"
        >
            <source src="{{ $url }}" @if (filled(data_get($media, 'mime_type'))) type="{{ data_get($media, 'mime_type') }}" @endif>
        </video>
    @else
        <picture {{ $attributes->class('block') }} @if ($ratioStyle !== '') style="{{ $ratioStyle }}" @endif>
            @if ($webpSrcset !== '')
                <source type="image/webp" srcset="{{ $webpSrcset }}" sizes="{{ $sizesAttribute }}">
            @endif

            <img
                src="{{ $url }}"
                @if ($srcset !== '') srcset="{{ $srcset }}" sizes="{{ $sizesAttribute }}" @endif
                alt="{{ $altText }}"
                @if (filled($width)) width="{{ (int) $width }}" @endif
                @if (filled($height)) height="{{ (int) $height }}" @endif
                @if ($loading !== null) loading="{{ $loading }}" @endif
                @if ($eager) fetchpriority="high" @endif
                decoding="async"
                class="{{ $imgClass }}"
            >
        </picture>
    @endif
@endif
