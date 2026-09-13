@extends('layouts.admin')

@section('title', 'Media library')

{{--
    Media library — admin.website.media.index (phase-03 §7.5, §8.13, §6.8; D24).

    Controller variables (Admin\Cms\MediaController@index):
      $assets             LengthAwarePaginator<App\Models\Cms\MediaAsset>   newest first, filtered (24 per page)
      $cards              array<int, array{id, name, original_name, alt_text, caption, mime_type, kind, width, height,
                                         size_bytes, usage_count, derivatives_status, url, thumbnail_url, show_url}>
      $collectionOptions  array<string, string>
      $derivativeOptions  array<string, string>
      $profileOptions     array<string, string>
      $maxUploadMb        int
      $filters            array<string, mixed>
      $can                array{upload: bool, edit: bool, delete: bool}
    Query string (CmsListRequest): search (original name, alt text, caption), collection, type (image|video),
    derivatives (MediaProcessingStatus value), unused (1), page.

    Writes:
      POST admin.website.media.store   file, collection, profile — one file per request, sent by the uploader
                                       with Accept: application/json. Success: 201/200 JSON (any body).
                                       Refusal: 422 JSON {"errors": {"file": ["that is a .php file renamed to .jpg"]}}
                                       — the uploader prints the first message beside the file name.
    Detail, alt text, regenerate and delete live on admin.website.media.show.
--}}

@php
    use App\Enums\Cms\MediaCollection;
    use App\Enums\Cms\MediaProcessingStatus;

    $mediaService = app(\App\Services\Cms\MediaService::class);
    $can = array_merge(['upload' => false, 'edit' => false, 'delete' => false], $can ?? []);
    $cards = $cards ?? [];
    $canUpload = (bool) $can['upload'];
    $filtered = ! empty($filters ?? []);
    $maxMb = (int) ($maxUploadMb ?? 0);
    $capabilities = $capabilities ?? ['engine' => 'gd', 'webp' => true, 'avif' => false, 'max_upload_bytes' => $maxMb * 1048576];
    $humanSize = static fn (int $bytes): string => $bytes >= 1048576 ? app_number($bytes / 1048576, 1).' MB' : app_number(max(1, (int) round($bytes / 1024))).' KB';
@endphp

@section('header')
    <x-ui.page-header
        title="Media library"
        subtitle="Every image and video the public site uses. Files are checked by their content, resized, and served in modern formats."
        icon="photo"
        :badge="app_number((int) $assets->total()).' files'"
    />
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        {{-- ── Upload dropzone ─────────────────────────────────────────────────── --}}
        @if ($canUpload)
            <div x-data="cmsUploader(@js(['url' => route('admin.website.media.store'), 'maxBytes' => (int) ($capabilities['max_upload_bytes'] ?? 0)]))" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                <form x-ref="form" method="post" x-on:submit.prevent class="flex flex-col gap-4 lg:flex-row">
                    <label
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="dropped($event)"
                        x-bind:class="dragging ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10' : 'border-slate-300 dark:border-slate-700'"
                        class="flex flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-8 text-center transition"
                    >
                        <x-ui.icon name="arrow-up-tray" class="h-7 w-7 text-slate-400" />
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Drop images or videos here, or click to choose</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            JPEG, PNG, WebP, GIF{{ ($capabilities['avif'] ?? false) ? ', AVIF' : '' }}, MP4 or WebM{{ $maxMb > 0 ? ' · up to '.$maxMb.' MB each' : '' }} · SVG is refused
                        </span>
                        <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/avif,video/mp4,video/webm" class="sr-only" x-on:change="pick($event)">
                    </label>

                    <div class="w-full space-y-3 lg:w-64">
                        <x-ui.form.select name="collection" id="upload-collection" label="Collection" :options="$collectionOptions ?? MediaCollection::options()" :selected="MediaCollection::General->value" size="sm" />
                        @if (! empty($profileOptions))
                            <x-ui.form.select name="profile" id="upload-profile" label="Image sizes" :options="$profileOptions" placeholder="Collection default" size="sm" />
                        @endif
                        @if (empty($capabilities['engine']))
                            <p class="text-xs text-amber-700 dark:text-amber-400">The image engine is unavailable on this server: uploads are refused until GD is enabled.</p>
                        @elseif (! ($capabilities['webp'] ?? false))
                            <p class="text-xs text-slate-500 dark:text-slate-400">WebP encoding is unavailable here; images are served in their original format only.</p>
                        @endif
                    </div>
                </form>

                <ul x-show="files.length" x-cloak class="mt-4 divide-y divide-slate-100 dark:divide-slate-800" aria-live="polite">
                    <template x-for="(entry, index) in files" :key="index">
                        <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:gap-3">
                            <span class="min-w-0 flex-1 truncate text-sm text-slate-700 dark:text-slate-200" x-text="entry.name"></span>
                            <span class="text-xs tabular-nums text-slate-500" x-text="humanSize(entry.size)"></span>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 sm:w-40 dark:bg-slate-800">
                                <div class="h-full rounded-full transition-all" x-bind:style="`width: ${entry.progress}%`" x-bind:class="entry.state === 'failed' ? 'bg-rose-500' : (entry.state === 'done' ? 'bg-emerald-500' : 'bg-brand-500')"></div>
                            </div>
                            <span class="text-xs font-medium" x-bind:class="entry.state === 'failed' ? 'text-rose-600 dark:text-rose-400' : (entry.state === 'done' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500')" x-text="entry.state === 'failed' ? entry.error : (entry.state === 'done' ? 'Uploaded' : (entry.state === 'uploading' ? entry.progress + '%' : 'Waiting'))"></span>
                        </li>
                    </template>
                </ul>
            </div>
        @endif

        <x-ui.filter-bar placeholder="Search file names, alt text, captions…" :reset="route('admin.website.media.index')">
            <x-ui.form.select name="collection" :options="$collectionOptions ?? MediaCollection::options()" :selected="request('collection')" placeholder="Any collection" size="sm" aria-label="Filter by collection" />
            <x-ui.form.select name="type" :options="['image' => 'Images', 'video' => 'Videos']" :selected="request('type')" placeholder="Any type" size="sm" aria-label="Filter by type" />
            <x-ui.form.select name="derivatives" :options="$derivativeOptions ?? MediaProcessingStatus::options()" :selected="request('derivatives')" placeholder="Any processing state" size="sm" aria-label="Filter by processing state" />
            <x-ui.form.select name="unused" :options="['1' => 'Unused only']" :selected="request('unused')" placeholder="Used or not" size="sm" aria-label="Filter unused" />
        </x-ui.filter-bar>

        <div x-show="navigating" x-cloak class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" aria-hidden="true">
            <x-ui.skeleton variant="card" :count="5" />
        </div>

        <div x-show="! navigating">
            @if ($assets->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state
                        icon="photo"
                        :title="$filtered ? 'No files match those filters' : 'No images yet'"
                        :message="$filtered ? 'Clear the filters to see the whole library.' : 'Drop the first image into the upload area above.'"
                    >
                        @if ($filtered)
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.website.media.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    @foreach ($assets as $asset)
                        @php
                            $isVideo = $asset->isVideo();
                            $status = $asset->derivatives_status instanceof MediaProcessingStatus ? $asset->derivatives_status : MediaProcessingStatus::tryFrom((string) $asset->derivatives_status);
                            $thumb = $isVideo ? null : ($cards[(int) $asset->id]['thumbnail_url'] ?? $mediaService->url($asset, 384));
                        @endphp
                        <li>
                            <a href="{{ route('admin.website.media.show', $asset) }}" class="group block overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 transition hover:ring-brand-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-900 dark:ring-slate-800 dark:hover:ring-brand-500/60">
                                <div class="relative aspect-[4/3] bg-slate-100 dark:bg-slate-800">
                                    @if ($thumb)
                                        <img src="{{ $thumb }}" alt="{{ $asset->alt_text }}" loading="lazy" class="h-full w-full object-cover">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center text-slate-400 dark:text-slate-500">
                                            <x-ui.icon name="video-camera" class="h-8 w-8" />
                                        </div>
                                    @endif

                                    <div class="absolute left-2 top-2 flex flex-wrap gap-1">
                                        @if ((int) $asset->usage_count > 0)
                                            <x-ui.badge color="indigo" variant="solid" size="sm">Used {{ (int) $asset->usage_count }}×</x-ui.badge>
                                        @endif
                                        @if ($status && ! in_array($status, [MediaProcessingStatus::Ready, MediaProcessingStatus::Skipped], true))
                                            <x-ui.badge :color="$status->color()" variant="solid" size="sm">{{ $status->label() }}</x-ui.badge>
                                        @endif
                                    </div>
                                </div>
                                <div class="px-3 py-2">
                                    <p class="truncate text-xs font-medium text-slate-800 dark:text-slate-100" title="{{ $asset->original_name }}">{{ $asset->title ?: $asset->original_name }}</p>
                                    <p class="text-2xs text-slate-500 dark:text-slate-400">
                                        {{ $humanSize((int) $asset->size_bytes) }}@if ($asset->width) · {{ $asset->width }}×{{ $asset->height }}@endif
                                    </p>
                                    @if (! $isVideo && ! $asset->hasAltText())
                                        <p class="text-2xs font-medium text-amber-600 dark:text-amber-400">No alt text</p>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <x-ui.card :compact="true" class="mt-4">
                    <x-ui.pagination-summary :paginator="$assets" label="files" />
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
