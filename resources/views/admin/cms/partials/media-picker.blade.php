{{--
    Chooses media_assets ids for one slot (phase-03 §8.1 `x-cms.image-picker`).

    @include('admin.cms.partials.media-picker', [
        'name' => 'media[hero_image]',        // posts an id, '' to clear; `multiple` posts name[] ids
        'label' => 'Hero image',
        'help' => 'Rendered eagerly…',
        'kind' => 'image',                    // image | video
        'multiple' => false,
        'selected' => $assets,                // iterable of MediaAsset currently placed (may be empty)
        'required' => false,
        'profile' => 'Hero',                  // optional profile label, shown as a chip
    ])

    Needs `admin.cms.partials.media-library-json` rendered once on the page and
    `admin.cms.partials.scripts` included. The ids are re-validated server-side (a live asset of the
    right kind, alt text required at publish) — the picker only offers.
--}}

@php
    $mediaService = app(\App\Services\Cms\MediaService::class);
    $kind = ($kind ?? 'image') === 'video' ? 'video' : 'image';
    $multiple = (bool) ($multiple ?? false);
    $errorKey = str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name);

    $initial = collect($selected ?? [])
        ->filter(static fn ($asset): bool => $asset instanceof \App\Models\Cms\MediaAsset)
        ->map(static fn ($asset): array => [
            'id' => (int) $asset->id,
            'label' => (string) ($asset->title ?: $asset->original_name),
            'alt' => $asset->alt_text,
            'url' => $asset->isVideo() ? null : $mediaService->url($asset, 192),
            'is_video' => $asset->isVideo(),
            'needs_alt' => ! $asset->isVideo() && ! $asset->hasAltText(),
        ])
        ->values()
        ->all();

    // An id the page holds without its asset row (a stored reference the controller did not load) is
    // kept as a placeholder chip, so saving the form never silently clears the slot.
    $knownIds = array_column($initial, 'id');
    foreach ((array) ($selectedIds ?? []) as $selectedId) {
        if (is_numeric($selectedId) && (int) $selectedId > 0 && ! in_array((int) $selectedId, $knownIds, true)) {
            $initial[] = ['id' => (int) $selectedId, 'label' => 'Media #'.(int) $selectedId, 'alt' => null, 'url' => null, 'is_video' => $kind === 'video', 'needs_alt' => false];
            $knownIds[] = (int) $selectedId;
        }
    }

    $old = old($errorKey);
    $config = [
        'name' => (string) $name,
        'multiple' => $multiple,
        'kind' => $kind,
        'selected' => $initial,
    ];

    if ($old !== null) {
        $config['oldIds'] = array_values(array_filter(array_map('intval', (array) $old)));
    }

    $pickerId = 'picker-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name);
    $libraryUrl = \Illuminate\Support\Facades\Route::has('admin.website.media.index') ? route('admin.website.media.index') : null;
@endphp

<div x-data="cmsMediaPicker(@js($config))" class="w-full" id="{{ $pickerId }}">
    <div class="mb-1.5 flex flex-wrap items-center gap-2">
        <x-ui.form.label :required="(bool) ($required ?? false)">{{ $label ?? 'Image' }}</x-ui.form.label>

        @if (filled($profile ?? null))
            <x-ui.badge color="slate" variant="outline" size="sm">{{ $profile }}</x-ui.badge>
        @endif

        @if ($multiple)
            <x-ui.badge color="slate" variant="outline" size="sm">Several files</x-ui.badge>
        @endif
    </div>

    {{-- What posts --}}
    @if ($multiple)
        <template x-for="asset in selected" :key="asset.id">
            <input type="hidden" x-bind:name="name + '[]'" x-bind:value="asset.id">
        </template>
        <template x-if="selected.length === 0">
            <input type="hidden" x-bind:name="name" value="">
        </template>
    @else
        <input type="hidden" name="{{ $name }}" x-bind:value="selected.length ? selected[0].id : ''" value="{{ $initial[0]['id'] ?? '' }}">
    @endif

    <div class="flex flex-wrap items-start gap-3">
        <template x-for="asset in selected" :key="'chip-' + asset.id">
            <div class="group relative w-28 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                <template x-if="asset.url">
                    <img x-bind:src="asset.url" x-bind:alt="asset.alt || ''" class="h-20 w-28 object-cover" loading="lazy">
                </template>
                <template x-if="! asset.url">
                    <div class="flex h-20 w-28 items-center justify-center text-slate-400 dark:text-slate-500">
                        <x-ui.icon name="video-camera" class="h-6 w-6" />
                    </div>
                </template>

                <div class="px-2 py-1">
                    <p class="truncate text-2xs font-medium text-slate-700 dark:text-slate-200" x-text="asset.label"></p>
                    <p x-show="asset.needs_alt" class="text-2xs font-medium text-amber-600 dark:text-amber-400">No alt text</p>
                </div>

                <button
                    type="button"
                    x-on:click="remove(asset.id)"
                    class="absolute right-1 top-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-slate-600 shadow ring-1 ring-slate-200 transition hover:text-rose-600 dark:bg-slate-900/90 dark:text-slate-300 dark:ring-slate-700 dark:hover:text-rose-400"
                    x-bind:aria-label="'Remove ' + asset.label"
                >
                    <x-ui.icon name="x-mark" class="h-3.5 w-3.5" />
                </button>
            </div>
        </template>

        <button
            type="button"
            x-on:click="open = true"
            class="flex h-[5.75rem] w-28 flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-slate-300 text-xs font-medium text-slate-500 transition hover:border-brand-400 hover:text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:border-slate-700 dark:text-slate-400 dark:hover:border-brand-500 dark:hover:text-brand-300"
        >
            <x-ui.icon :name="$kind === 'video' ? 'video-camera' : 'photo'" class="h-5 w-5" />
            <span x-text="selected.length && ! multiple ? 'Replace' : 'Choose'">Choose</span>
        </button>
    </div>

    @if (filled($help ?? null))
        <x-ui.form.help>{{ $help }}</x-ui.form.help>
    @endif

    <x-ui.form.error :for="$name" />

    {{-- Chooser --}}
    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="open = false"
        class="fixed inset-0 z-modal overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-label="Choose from the media library"
        style="display: none"
    >
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm dark:bg-slate-950/70" x-on:click="open = false" aria-hidden="true"></div>

        <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
            <div x-trap.noscroll="open" class="relative w-full overflow-hidden rounded-xl bg-white shadow-modal ring-1 ring-slate-200 sm:max-w-3xl dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h2 class="flex-1 text-base font-semibold text-slate-900 dark:text-white">
                        Choose {{ $kind === 'video' ? 'a video' : ($multiple ? 'images' : 'an image') }}
                    </h2>
                    <x-ui.icon-button icon="x-mark" label="Close" size="sm" x-on:click="open = false" />
                </div>

                <div class="space-y-3 px-5 py-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <x-ui.icon name="magnifying-glass" class="h-4 w-4" />
                            </span>
                            <input
                                type="search"
                                x-model="search"
                                placeholder="Search by name or alt text…"
                                aria-label="Search the media library"
                                data-dirty-ignore
                                class="block w-full rounded-lg border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-900 focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                            >
                        </div>

                        @if ($libraryUrl)
                            <x-ui.button variant="secondary" size="sm" icon="arrow-up-tray" :href="$libraryUrl" target="_blank" rel="noopener">
                                Upload in the library
                            </x-ui.button>
                        @endif
                    </div>

                    <div class="grid max-h-[55vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-4">
                        <template x-for="asset in choices" :key="'choice-' + asset.id">
                            <button
                                type="button"
                                x-on:click="choose(asset)"
                                class="overflow-hidden rounded-lg text-left ring-1 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                x-bind:class="isSelected(asset.id)
                                    ? 'ring-2 ring-brand-500 dark:ring-brand-400'
                                    : 'ring-slate-200 hover:ring-brand-300 dark:ring-slate-700 dark:hover:ring-brand-500/60'"
                                x-bind:aria-pressed="isSelected(asset.id) ? 'true' : 'false'"
                            >
                                <template x-if="asset.url">
                                    <img x-bind:src="asset.url" x-bind:alt="asset.alt || ''" class="h-24 w-full bg-slate-100 object-cover dark:bg-slate-800" loading="lazy">
                                </template>
                                <template x-if="! asset.url">
                                    <div class="flex h-24 w-full items-center justify-center bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                                        <x-ui.icon name="video-camera" class="h-6 w-6" />
                                    </div>
                                </template>
                                <span class="block truncate px-2 py-1.5 text-2xs font-medium text-slate-700 dark:text-slate-200" x-text="asset.label"></span>
                            </button>
                        </template>
                    </div>

                    <p x-show="choices.length === 0" class="py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                        Nothing in the library matches. Upload the file in the media library, then reload this page.
                    </p>
                </div>

                @if ($multiple)
                    <div class="flex justify-end border-t border-slate-200 bg-slate-50/70 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60">
                        <x-ui.button size="sm" x-on:click="open = false; $dispatch('input')">Done</x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
