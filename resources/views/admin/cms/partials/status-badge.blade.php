{{--
    The draft / live state of a snapshot-published row (phase-03 §8.4 `x-cms.status-badge`).

    @include('admin.cms.partials.status-badge', [
        'status' => $section->status,                      // ContentStatus|string|null
        'unpublished' => $section->has_unpublished_changes, // the STORED generated column (INV-4)
        'published' => filled($section->published_hash),   // has a live snapshot ever been written?
        'enabled' => $section->is_enabled,                  // null = the row has no enable switch
        'orphaned' => ! SectionRegistry::exists($section->section_key),
        'size' => 'sm',
    ])

    One row can carry several facts at once (a disabled section with unpublished changes), so the
    badges stack rather than one hiding another. The "Unpublished changes" badge is read from the
    generated column and nothing else: it can never lie (INV-4).
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $status = $status ?? null;
    $status = $status instanceof ContentStatus ? $status : ContentStatus::tryFrom((string) $status);
    $unpublished = (bool) ($unpublished ?? false);
    $published = (bool) ($published ?? false);
    $enabled = $enabled ?? null;
    $orphaned = (bool) ($orphaned ?? false);
    $size = $size ?? 'sm';
@endphp

<span class="inline-flex flex-wrap items-center gap-1.5">
    @if ($orphaned)
        <x-ui.badge color="rose" :size="$size" icon="exclamation-triangle" title="This section type is no longer registered. It never renders on the public site.">
            Orphaned type
        </x-ui.badge>
    @endif

    @if ($status === ContentStatus::Published)
        <x-ui.badge color="emerald" :size="$size" :dot="true">Published</x-ui.badge>
    @elseif ($status === ContentStatus::Scheduled)
        <x-ui.badge color="amber" :size="$size" :dot="true">Scheduled</x-ui.badge>
    @elseif ($status === ContentStatus::Archived)
        <x-ui.badge color="rose" :size="$size" :dot="true">Archived</x-ui.badge>
    @elseif ($published)
        {{-- status = draft but a snapshot exists: it was unpublished, and the snapshot is kept. --}}
        <x-ui.badge color="slate" :size="$size" :dot="true" title="Taken off the site. The last published version is kept and can be published again.">
            Unpublished
        </x-ui.badge>
    @else
        <x-ui.badge color="slate" :size="$size" :dot="true">Draft</x-ui.badge>
    @endif

    @if ($unpublished && ($status === ContentStatus::Published || $status === ContentStatus::Scheduled || $published))
        <x-ui.badge color="amber" :size="$size" icon="pencil" title="The draft differs from the live version. Visitors see the live version until it is published.">
            Unpublished changes
        </x-ui.badge>
    @endif

    @if ($enabled === false)
        <x-ui.badge color="slate" variant="outline" :size="$size" icon="eye-slash">Disabled</x-ui.badge>
    @endif
</span>
