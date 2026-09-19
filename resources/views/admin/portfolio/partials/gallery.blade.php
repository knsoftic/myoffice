{{--
    The gallery manager of a portfolio item (phase-04 §8.3, §2.8, §6.3). Rendered on the edit screen OUTSIDE
    the item form — every action here has its own endpoint and its own form.

    @include('admin.portfolio.partials.gallery', ['item' => $item, 'gallery' => $gallery])

    Variables:
      $item        App\Models\Cms\PortfolioItem
      $gallery     Collection<App\Models\Cms\MediaAsset> attached through portfolio_item_media, ordered by the
                   pivot sort_order, each with ->pivot->caption (withPivot('caption', 'sort_order'))
      $maxImages   optional int, default 20 (§6.3 invariant 3)
      $maxUploadMb optional int

    Writes (phase-04 §7.2):
      POST   admin.portfolio.images.store   {item}          images[] (one file per request, so each file has its own
                                                          progress bar and its own refusal reason), or
                                                          media_asset_ids[] when chosen from the library
      POST   admin.portfolio.images.reorder {item}          JSON {ids: [media_asset_id, ...]} in gallery order
      POST   admin.portfolio.images.cover   {item, image}   sets cover_media_id
      DELETE admin.portfolio.images.destroy {item, image}   detaches (the file stays in the media library)
      PUT    admin.portfolio.images.update  {item, image}   caption          (requested — rendered only when the route exists)
    Alt text is one value per image and lives on media_assets: "Edit alt text" opens the media library record.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $gallery = collect($gallery ?? []);
    $maxImages = (int) ($maxImages ?? 20);
    $count = $gallery->count();
    $user = auth()->user();
    $canUpload = (bool) $user?->can('portfolio.upload') && Route::has('admin.portfolio.images.store');
    $canEdit = (bool) $user?->can('portfolio.edit');
    $canReorder = $canEdit && Route::has('admin.portfolio.images.reorder') && $count > 1;
    $canCaption = $canEdit && Route::has('admin.portfolio.images.update');
    $mediaShow = Route::has('admin.website.media.show');
    $mediaService = app(\App\Services\Cms\MediaService::class);
    $coverId = (int) ($item->cover_media_id ?? 0);
    $maxBytes = (int) (($maxUploadMb ?? 0) * 1048576);
@endphp

<x-ui.card title="Gallery" subtitle="Drag to reorder. The cover leads the case study and the portfolio card." icon="photo" :padded="false">
    <x-slot:actions>
        <x-ui.badge :color="$count >= $maxImages ? 'rose' : 'slate'" size="sm">{{ app_number($count) }} / {{ app_number($maxImages) }} images</x-ui.badge>
    </x-slot:actions>

    <div class="space-y-5 p-4 sm:p-5">
        @if ($canUpload && $count < $maxImages)
            <div
                x-data="{
                    url: @js(route('admin.portfolio.images.store', $item)),
                    maxBytes: {{ $maxBytes }},
                    remaining: {{ max(0, $maxImages - $count) }},
                    files: [],
                    dragging: false,
                    busy: false,
                    queue(list) {
                        Array.from(list || []).forEach((file) => {
                            const entry = { name: file.name, progress: 0, state: 'queued', error: null, file };
                            if (this.files.filter((f) => f.state !== 'failed').length >= this.remaining) {
                                entry.state = 'failed';
                                entry.error = 'The gallery is full ({{ $maxImages }} images).';
                            } else if (this.maxBytes > 0 && file.size > this.maxBytes) {
                                entry.state = 'failed';
                                entry.error = 'Larger than the upload limit.';
                            }
                            this.files.push(entry);
                        });
                        this.run();
                    },
                    async run() {
                        if (this.busy) return;
                        this.busy = true;
                        for (const entry of this.files) {
                            if (entry.state === 'queued') { await this.send(entry); }
                        }
                        this.busy = false;
                        if (this.files.some((f) => f.state === 'done') && this.files.every((f) => f.state !== 'queued')) {
                            window.Alpine?.store('toasts')?.push({ type: 'success', message: 'Images attached. Refreshing the gallery…' });
                            setTimeout(() => window.location.reload(), 900);
                        }
                    },
                    send(entry) {
                        return new Promise((resolve) => {
                            const form = new FormData();
                            form.append('images[]', entry.file);
                            const request = new XMLHttpRequest();
                            request.open('POST', this.url);
                            request.setRequestHeader('Accept', 'application/json');
                            request.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]')?.content || '');
                            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                            entry.state = 'uploading';
                            request.upload.addEventListener('progress', (event) => { if (event.lengthComputable) entry.progress = Math.round((event.loaded / event.total) * 100); });
                            request.addEventListener('load', () => {
                                if (request.status >= 200 && request.status < 300) {
                                    entry.state = 'done';
                                    entry.progress = 100;
                                } else {
                                    entry.state = 'failed';
                                    try {
                                        const body = JSON.parse(request.responseText || '{}');
                                        entry.error = (body.errors ? Object.values(body.errors).flat()[0] : null) || body.message || 'The image was refused.';
                                    } catch (error) {
                                        entry.error = request.status === 413 ? 'The file is larger than the server accepts.' : 'The image was refused.';
                                    }
                                }
                                entry.file = null;
                                resolve();
                            });
                            request.addEventListener('error', () => { entry.state = 'failed'; entry.error = 'The connection dropped before the upload finished.'; resolve(); });
                            request.send(form);
                        });
                    },
                }"
            >
                <label
                    for="gallery-upload"
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="dragging = false; queue($event.dataTransfer.files)"
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center text-sm transition"
                    x-bind:class="dragging ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' : 'border-slate-300 text-slate-500 hover:border-brand-400 hover:text-brand-700 dark:border-slate-700 dark:text-slate-400 dark:hover:border-brand-500 dark:hover:text-brand-300'"
                >
                    <x-ui.icon name="arrow-up-tray" class="h-6 w-6" />
                    <span>{{ $count === 0 ? 'Drag images here or browse' : 'Drag more images here or browse' }}</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">JPG, PNG, WebP or GIF{{ ($maxUploadMb ?? null) ? ', up to '.(int) $maxUploadMb.' MB each' : '' }} — {{ app_number(max(0, $maxImages - $count)) }} more allowed</span>
                </label>
                <input id="gallery-upload" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only" x-on:change="queue($event.target.files); $event.target.value = ''">

                <ul x-show="files.length" x-cloak class="mt-3 space-y-2" aria-live="polite">
                    <template x-for="(entry, index) in files" :key="'upload-' + index">
                        <li class="rounded-lg bg-slate-50 px-3 py-2 text-xs ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <span class="truncate font-medium text-slate-700 dark:text-slate-200" x-text="entry.name"></span>
                                <span class="shrink-0 tabular-nums" x-bind:class="entry.state === 'failed' ? 'text-rose-600 dark:text-rose-400' : (entry.state === 'done' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500')" x-text="entry.state === 'failed' ? 'Refused' : (entry.state === 'done' ? 'Attached' : entry.progress + '%')"></span>
                            </div>
                            <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" x-show="entry.state !== 'failed'">
                                <div class="h-full rounded-full bg-brand-500 transition-all" x-bind:style="'width:' + entry.progress + '%'"></div>
                            </div>
                            <p x-show="entry.error" class="mt-1 font-medium text-rose-600 dark:text-rose-400" x-text="entry.error"></p>
                        </li>
                    </template>
                </ul>
            </div>

            <form method="POST" action="{{ route('admin.portfolio.images.store', $item) }}" class="rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                @csrf
                @include('admin.cms.partials.media-picker', [
                    'name' => 'media_asset_ids',
                    'label' => 'Choose from library',
                    'help' => 'Pick images already in the media library, then attach them. An image already in this gallery is not attached twice.',
                    'kind' => 'image',
                    'multiple' => true,
                    'selected' => [],
                    'profile' => 'Card',
                ])
                <div class="mt-3 flex justify-end">
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="plus">Attach selected</x-ui.button>
                </div>
            </form>
        @elseif ($canUpload)
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25">
                The gallery is full ({{ app_number($maxImages) }} images). Remove one from this project to add another.
            </p>
        @endif

        @if ($errors->has('images') || $errors->has('media_asset_ids') || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'images.') || str_starts_with($key, 'ids')))
            <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
                <p class="font-semibold">The gallery was not changed.</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach (collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'images') || str_starts_with($key, 'media_asset_ids') || str_starts_with($key, 'ids'))->flatten() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($gallery->isEmpty())
            <x-ui.empty-state icon="photo" title="No images in this project yet" message="Upload images or choose them from the library. The first image becomes the cover." :compact="true" />
        @else
            <div x-data="cmsSortable(@js(['url' => $canReorder ? route('admin.portfolio.images.reorder', $item) : null, 'key' => 'ids', 'noun' => 'image', 'disabled' => ! $canReorder]))">
                <p class="sr-only" aria-live="polite" x-text="announcement"></p>

                <ul data-sortable-list role="list" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($gallery as $asset)
                        @php
                            $isCover = (int) $asset->getKey() === $coverId;
                            $thumb = $asset->isImage() ? rescue(static fn () => $mediaService->url($asset, 480), null, false) : null;
                            $caption = (string) ($asset->pivot?->caption ?? '');
                            $label = (string) ($asset->title ?: $asset->original_name ?: 'Image #'.$asset->getKey());
                        @endphp
                        <li
                            data-sortable-id="{{ $asset->getKey() }}"
                            data-sortable-label="{{ $label }}"
                            x-on:dragstart="dragStart($event)"
                            x-on:dragover.prevent="dragOver($event)"
                            x-on:drop.prevent="drop()"
                            x-on:dragend="dragEnd($event)"
                            @class([
                                'group flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 dark:bg-slate-900',
                                'ring-2 ring-brand-500 dark:ring-brand-400' => $isCover,
                                'ring-slate-200 dark:ring-slate-800' => ! $isCover,
                            ])
                        >
                            <div class="relative aspect-[4/3] bg-slate-100 dark:bg-slate-800">
                                @if ($thumb)
                                    <img src="{{ $thumb }}" alt="{{ $asset->alt_text ?? '' }}" loading="lazy" decoding="async" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-slate-400"><x-ui.icon name="photo" class="h-8 w-8" /></div>
                                @endif

                                @if ($isCover)
                                    <span class="absolute left-2 top-2"><x-ui.badge color="brand" variant="solid" size="sm" icon="star">Cover</x-ui.badge></span>
                                @endif

                                @if ($canReorder)
                                    <div class="absolute right-2 top-2 flex items-center gap-1 rounded-lg bg-white/90 p-0.5 shadow ring-1 ring-slate-200 dark:bg-slate-900/90 dark:ring-slate-700">
                                        <button type="button" data-sortable-handle x-on:pointerdown="arm($event)" class="inline-flex h-7 w-7 cursor-grab items-center justify-center rounded text-slate-500 hover:text-slate-900 active:cursor-grabbing dark:text-slate-400 dark:hover:text-white" aria-label="Drag {{ $label }}">
                                            <x-ui.icon name="bars-3" class="h-4 w-4" />
                                        </button>
                                        <button type="button" x-on:click="move($el, -1)" class="inline-flex h-7 w-7 items-center justify-center rounded text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" aria-label="Move {{ $label }} earlier">
                                            <x-ui.icon name="chevron-left" class="h-4 w-4" />
                                        </button>
                                        <button type="button" x-on:click="move($el, 1)" class="inline-flex h-7 w-7 items-center justify-center rounded text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" aria-label="Move {{ $label }} later">
                                            <x-ui.icon name="chevron-right" class="h-4 w-4" />
                                        </button>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col gap-2 p-3">
                                <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200" title="{{ $label }}">{{ $label }}</p>

                                @if (blank($asset->alt_text))
                                    <p class="flex items-center gap-1 text-2xs font-semibold text-amber-600 dark:text-amber-400">
                                        <x-ui.icon name="exclamation-triangle" class="h-3.5 w-3.5" /> No alt text — required before publishing
                                    </p>
                                @else
                                    <p class="line-clamp-2 text-2xs text-slate-500 dark:text-slate-400">Alt: {{ $asset->alt_text }}</p>
                                @endif

                                @if ($canCaption)
                                    <form method="POST" action="{{ route('admin.portfolio.images.update', [$item, $asset]) }}" class="flex items-center gap-1.5">
                                        @csrf
                                        @method('PUT')
                                        <input type="text" name="caption" value="{{ $caption }}" maxlength="255" placeholder="Caption on this project" aria-label="Caption for {{ $label }}" class="block w-full rounded-md border-slate-300 px-2 py-1 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                        <x-ui.icon-button type="submit" icon="check" size="xs" label="Save caption" />
                                    </form>
                                @elseif ($caption !== '')
                                    <p class="text-xs italic text-slate-600 dark:text-slate-300">{{ $caption }}</p>
                                @endif

                                <div class="mt-auto flex flex-wrap items-center justify-between gap-1 border-t border-slate-100 pt-2 dark:border-slate-800">
                                    <div class="flex items-center gap-1">
                                        @if ($canEdit && Route::has('admin.portfolio.images.cover'))
                                            @if ($isCover)
                                                <span class="inline-flex items-center gap-1 px-1.5 text-2xs font-semibold text-brand-700 dark:text-brand-300" role="status">
                                                    <span class="inline-block h-3 w-3 rounded-full border-4 border-brand-600 dark:border-brand-400" aria-hidden="true"></span> Cover
                                                </span>
                                            @else
                                                <form method="POST" action="{{ route('admin.portfolio.images.cover', [$item, $asset]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex items-center gap-1 rounded px-1.5 py-1 text-2xs font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                                                        <span class="inline-block h-3 w-3 rounded-full border border-slate-400 dark:border-slate-500" aria-hidden="true"></span> Set cover
                                                    </button>
                                                </form>
                                            @endif
                                        @endif

                                        @if ($mediaShow)
                                            <a href="{{ route('admin.website.media.show', $asset) }}" class="rounded px-1.5 py-1 text-2xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit alt text</a>
                                        @endif
                                    </div>

                                    @if ($canEdit && Route::has('admin.portfolio.images.destroy'))
                                        <x-ui.confirm
                                            :action="route('admin.portfolio.images.destroy', [$item, $asset])"
                                            title="Remove this image from the project?"
                                            :message="$isCover ? 'It is the cover: the next image in the gallery becomes the cover. The file stays in the media library.' : 'The file stays in the media library and can be attached again.'"
                                            confirm-label="Remove from project"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="x-mark" variant="danger" size="xs" label="Remove {{ $label }} from this project" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-ui.card>
