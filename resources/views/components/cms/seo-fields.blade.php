@props([
    'model' => null,
    'seo' => null,
    'inherited' => null,
    'displayUrl' => null,
    'ogAsset' => null,
    'readonly' => false,
    'prefix' => 'seo',
])

{{--
    <x-cms.seo-fields> — the one SEO block for services, service and portfolio categories, portfolio items,
    blog categories, blog posts and job openings (phase-04 §8.13, D23, ND-13).

        <x-cms.seo-fields
            :model="$service"                  // the entity (null on a create screen)
            :seo="$seoMeta"                    // ?App\Models\Cms\SeoMeta — SeoService::meta($service)
            :inherited="$seoInherited"         // ?App\Services\Cms\Data\SeoPayload — SeoService::for($service)
            :display-url="$publicUrl"          // what the search-result preview shows
            :og-asset="$seoMeta?->ogImage"     // ?MediaAsset currently set as the OG image
        />

    It declares **no entity column**. The fields post under `seo[...]` — exactly the six `seo_meta`
    columns of `SeoService::rules()` (title, meta_description, meta_keywords, canonical_url, robots,
    og_image_media_id) — and the host Form Request merges that one rule set instead of restating it; the
    controller writes them only through `SeoService::save($model, $request->validated('seo'))`.

    It renders Phase 3's own SEO panel (admin.cms.partials.seo-fields) so the snippet preview, the counters
    and the OG media picker are one implementation across the CMS. When the controller did not pass the
    stored row or the inherited payload, they are read here through SeoService (one query each) — never by
    touching `seo_meta` directly. The page must also render `admin.cms.partials.media-library-json`
    (the OG picker's library) and `admin.cms.partials.scripts`.
--}}

@php
    $seoRow = $seo;
    $seoPayload = $inherited;
    $isStoredModel = $model instanceof \Illuminate\Database\Eloquent\Model && $model->exists;

    if ($seoRow === null && $isStoredModel) {
        $seoRow = $model->relationLoaded('seo')
            ? $model->getRelation('seo')
            : rescue(static fn () => app(\App\Services\Cms\SeoService::class)->meta($model), null, false);
    }

    if ($seoPayload === null && $isStoredModel) {
        $seoPayload = rescue(static fn () => app(\App\Services\Cms\SeoService::class)->for($model), null, false);
    }

    $ogSelected = $ogAsset;

    if ($ogSelected === null && $seoRow instanceof \App\Models\Cms\SeoMeta && $seoRow->relationLoaded('ogImage')) {
        $ogSelected = $seoRow->getRelation('ogImage');
    }
@endphp

<div {{ $attributes->class('w-full') }}>
    @include('admin.cms.partials.seo-fields', [
        'seo' => $seoRow instanceof \App\Models\Cms\SeoMeta ? $seoRow : null,
        'inherited' => $seoPayload,
        'displayUrl' => $displayUrl ?? url('/'),
        'prefix' => $prefix,
        'readonly' => (bool) $readonly,
        'ogSelected' => $ogSelected instanceof \App\Models\Cms\MediaAsset ? $ogSelected : null,
        'ogSelectedIds' => $seoRow instanceof \App\Models\Cms\SeoMeta && filled($seoRow->og_image_media_id) ? [(int) $seoRow->og_image_media_id] : [],
    ])
</div>
