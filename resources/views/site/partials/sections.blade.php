{{--
    The section renderer — the ONLY place a public section partial is chosen (phase-03 §6.9, INV-2).

        @include('site.partials.sections', ['sections' => data_get($site, 'sections', [])])

    Receives:
      $sections  the ordered published sections for this placement. Each element is either the
                 snapshot itself (SnapshotBuilder's array: section_key, anchor, fields, items, media,
                 cta, menus, faqs, provider) or a row carrying it under `published_content`.
      $site      optional; its header fields decide whether the header overlays a leading hero.

    Each partial `site.sections.{key}` (SectionRegistry::view()) receives:
      $section  the full snapshot            $content  $section['fields']
      $items    $section['items']            $media    $section['media']
      $cta      $section['cta']              $menus    $section['menus']
      $faqs     $section['faqs'] ?? []       $index    0-based position on the page
      $overlayHeader  bool, only ever true for a hero in first position

    A section whose type is not in the registry, whose partial does not exist, or whose snapshot is
    empty is skipped — never a 500 (INV-2). Logging the orphan is PublicPageService's job, so it
    happens once per publish, not once per render.
--}}

@php
    $rows = collect($sections ?? [])->values();

    $headerOverlays = (bool) data_get($site ?? null, 'header.fields.transparent_over_hero', false);
@endphp

@foreach ($rows as $index => $row)
    @php
        $snapshot = is_array(data_get($row, 'published_content')) ? data_get($row, 'published_content') : $row;
        $snapshot = is_array($snapshot) ? $snapshot : (is_object($snapshot) ? (array) $snapshot : []);

        if (is_array($row) || is_object($row)) {
            $snapshot['section_key'] ??= data_get($row, 'section_key');
            $snapshot['anchor'] = data_get($row, 'anchor', $snapshot['anchor'] ?? null);
        }

        $sectionKey = (string) ($snapshot['section_key'] ?? '');
        $sectionView = $sectionKey !== '' && \App\Support\Cms\SectionRegistry::exists($sectionKey)
            ? \App\Support\Cms\SectionRegistry::view($sectionKey)
            : null;

        $sectionView = $sectionView !== null && \Illuminate\Support\Facades\View::exists($sectionView) ? $sectionView : null;

        $leadingHero = $index === 0
            && $sectionKey === 'hero'
            && $headerOverlays
            && (filled(data_get($snapshot, 'media.background_image.url'))
                || filled(data_get($snapshot, 'media.background_video.url'))
                || filled(data_get($snapshot, 'media.video_poster.url')));
    @endphp

    @continue($sectionView === null || $snapshot === [])

    @include($sectionView, [
        'section' => $snapshot,
        'content' => (array) ($snapshot['fields'] ?? []),
        'items' => (array) ($snapshot['items'] ?? []),
        'media' => (array) ($snapshot['media'] ?? []),
        'cta' => $snapshot['cta'] ?? null,
        'menus' => (array) ($snapshot['menus'] ?? []),
        'faqs' => (array) ($snapshot['faqs'] ?? []),
        'index' => $index,
        'overlayHeader' => $leadingHero,
    ])
@endforeach
