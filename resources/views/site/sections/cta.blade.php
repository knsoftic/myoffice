{{--
    Section type `cta` (placements home, page; repeatable) — requirement §100, phase-03 §6.1.

    Receives:
      $section  the published snapshot (anchor)
      $cta      the resolved CTA block (`SnapshotBuilder::cta()`), or null when the referenced block is a
                draft, deleted, or was never chosen — then nothing renders (FT-12: a deleted block
                leaves the section empty, never a 500)

    The block is referenced, never copied (§2.8), so the look is the block's `variant`, rendered by
    <x-site.cta> through `site.cta.{variant}`. `full_width` bleeds edge to edge; every other variant
    sits in the page container with the standard section rhythm.
--}}

@php
    use App\Enums\Cms\CtaVariant;

    $block = is_array($cta ?? null) ? $cta : null;
    $variant = CtaVariant::tryFrom((string) data_get($block, 'variant', '')) ?? CtaVariant::Banner;
    $label = trim((string) data_get($block, 'heading', ''));
@endphp

@if ($block !== null && $label !== '')
    @if ($variant === CtaVariant::FullWidth)
        <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="none" padding="none" width="full" :label="$label">
            <x-site.cta :cta="$block" />
        </x-site.section>
    @else
        <x-site.section
            :anchor="data_get($section ?? null, 'anchor')"
            background="surface"
            :padding="$variant === CtaVariant::Inline ? 'tight' : 'default'"
            :label="$label"
        >
            <x-site.cta :cta="$block" />
        </x-site.section>
    @endif
@endif
