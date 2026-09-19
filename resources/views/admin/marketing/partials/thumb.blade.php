{{--
    A small admin thumbnail for a `*_media_id` image (phase-04 §6.6, D24).

    @include('admin.marketing.partials.thumb', [
        'model' => $service,
        'relations' => ['imageAsset', 'image'],   // candidate relation names, first LOADED one wins
        'column' => 'image_media_id',             // shows a "not loaded" placeholder when the id is set
        'alt' => $service->name,
        'box' => 'h-10 w-14',                      // size utilities
        'icon' => 'photo',                         // fallback glyph
        'round' => false,                          // true = avatar circle
    ])

    Only an eager-loaded relation is ever read (never a lazy load, D59): a controller that forgot the
    `with()` gets a neutral placeholder, not an N+1 query per row. The URL comes from Phase 3's
    MediaService (the 192px derivative), never from a hand-built storage path.
--}}

@php
    $thumbAsset = null;

    foreach ((array) ($relations ?? []) as $relationName) {
        if ($model instanceof \Illuminate\Database\Eloquent\Model && $model->relationLoaded($relationName)) {
            $candidate = $model->getRelation($relationName);

            if ($candidate instanceof \App\Models\Cms\MediaAsset) {
                $thumbAsset = $candidate;
                break;
            }
        }
    }

    $thumbUrl = null;

    if ($thumbAsset !== null && $thumbAsset->isImage()) {
        try {
            $thumbUrl = app(\App\Services\Cms\MediaService::class)->url($thumbAsset, 192);
        } catch (\Throwable) {
            $thumbUrl = null;
        }
    }

    $box = $box ?? 'h-10 w-14';
    $round = (bool) ($round ?? false);
    $shape = $round ? 'rounded-full' : 'rounded-lg';
    $hasId = filled($column ?? null) && $model instanceof \Illuminate\Database\Eloquent\Model && filled($model->getAttribute($column));
    $thumbAlt = (string) ($thumbAsset?->alt_text ?: ($alt ?? ''));
@endphp

@if ($thumbUrl)
    <img
        src="{{ $thumbUrl }}"
        alt="{{ $thumbAlt }}"
        loading="lazy"
        decoding="async"
        class="{{ $box }} {{ $shape }} shrink-0 bg-slate-100 object-cover ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
    >
@else
    <span
        class="{{ $box }} {{ $shape }} inline-flex shrink-0 items-center justify-center bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-500 dark:ring-slate-700"
        @if ($hasId) title="Image #{{ $model->getAttribute($column) }}" @endif
        aria-hidden="true"
    >
        <x-ui.icon :name="$icon ?? 'photo'" class="h-4 w-4" />
    </span>
@endif
