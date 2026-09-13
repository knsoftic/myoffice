{{--
    The media library as JSON, rendered ONCE per page, read by every cmsMediaPicker on it.

    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary])

    `library` accepts either shape, so every controller can pass what it already has:
      · a list of arrays — SectionController::mediaLibrary(): id, name, alt_text, mime_type, kind
        ('image'|'video'), url, width, height (thumbnail_url optional);
      · a Collection<App\Models\Cms\MediaAsset> (the thumbnail is derived here).
    Missing or null renders an empty library: the picker then offers the upload link only, and a slot's
    current value is still kept (see media-picker `selectedIds`).

    Only display data is emitted — no path on disk, no checksum, no uploader. `@json` escapes <, >, &
    and quotes, so a hostile original filename cannot close the script tag.
--}}

@php
    $mediaService = app(\App\Services\Cms\MediaService::class);

    $library = collect($library ?? ($mediaLibrary ?? ($mediaChoices ?? [])))
        ->map(static function ($asset) use ($mediaService): ?array {
            if ($asset instanceof \App\Models\Cms\MediaAsset) {
                $isVideo = $asset->isVideo();

                return [
                    'id' => (int) $asset->id,
                    'label' => (string) ($asset->title ?: $asset->original_name),
                    'alt' => $asset->alt_text,
                    'url' => $isVideo ? null : $mediaService->url($asset, 192),
                    'is_video' => $isVideo,
                    'needs_alt' => ! $isVideo && ! $asset->hasAltText(),
                ];
            }

            if (! is_array($asset) || ! isset($asset['id'])) {
                return null;
            }

            $isVideo = ($asset['kind'] ?? null) === 'video' || str_starts_with((string) ($asset['mime_type'] ?? ''), 'video/') || (bool) ($asset['is_video'] ?? false);
            $alt = $asset['alt_text'] ?? ($asset['alt'] ?? null);

            return [
                'id' => (int) $asset['id'],
                'label' => (string) ($asset['name'] ?? ($asset['label'] ?? ('Media #'.$asset['id']))),
                'alt' => $alt,
                'url' => $isVideo ? null : ($asset['thumbnail_url'] ?? ($asset['url'] ?? null)),
                'is_video' => $isVideo,
                'needs_alt' => ! $isVideo && trim((string) $alt) === '',
            ];
        })
        ->filter()
        ->values()
        ->all();
@endphp

<script type="application/json" id="cms-media-library">@json($library)</script>
