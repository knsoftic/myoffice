@props([
    'name' => 'image_media_id',
    'upload' => null,
    'label' => 'Image',
    'asset' => null,
    'help' => null,
    'profile' => null,
    'required' => false,
    'readonly' => false,
    'maxMb' => null,
    'idSuffix' => null,
])

{{--
    <x-cms.image-field> — a thin wrapper over Phase 3's media library picker plus an optional direct upload
    (phase-04 §8.13, §6.6, D24).

        <x-cms.image-field
            name="image_media_id"             // the *_media_id column the form posts ('' clears it)
            upload="image"                    // optional: the UploadedFile input the service hands to MediaService::store()
            label="Service image"
            :asset="$service?->image"    // ?App\Models\Cms\MediaAsset currently set
            profile="Card"                    // the ImageProfile label shown as a chip
            :max-mb="$maxUploadMb"            // optional courtesy hint (the server rule is authoritative)
            id-suffix="row-7"                 // optional: unique element ids when several fields share a page
        />

    It never writes a path column and owns no validation rule of its own: the chosen library id is
    re-validated by the Form Request (`nullable, exists:media_assets,id`), and an uploaded file goes through
    `MediaService::store()` — real MIME by content, SVG refused, EXIF stripped, derivatives generated. When
    both are sent, the service uses the upload and the library id is ignored.

    The host form must be `enctype="multipart/form-data"` when `upload` is set, and the page must render
    `admin.cms.partials.media-library-json` and `admin.cms.partials.scripts` once.
--}}

@php
    $selected = $asset instanceof \App\Models\Cms\MediaAsset ? [$asset] : [];
    $uploadId = $upload ? 'upload-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $upload).(filled($idSuffix) ? '-'.$idSuffix : '') : null;
    $currentId = old(str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name));
@endphp

<div {{ $attributes->class('w-full space-y-3') }}>
    @include('admin.cms.partials.media-picker', [
        'name' => $name,
        'label' => $label,
        'help' => $help,
        'kind' => 'image',
        'multiple' => false,
        'selected' => $selected,
        'selectedIds' => $asset === null && filled($currentId) ? [(int) $currentId] : [],
        'required' => (bool) $required,
        'profile' => $profile,
    ])

    @if ($upload && ! $readonly)
        <div x-data="{ fileName: '', tooBig: false }" class="rounded-lg border border-dashed border-slate-300 px-3 py-2.5 dark:border-slate-700">
            <label for="{{ $uploadId }}" class="flex cursor-pointer flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <x-ui.icon name="arrow-up-tray" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                <span class="font-medium text-brand-700 hover:underline dark:text-brand-300">Or upload a new image</span>
                <span class="text-slate-400 dark:text-slate-500">
                    {{ $maxMb ? 'JPG, PNG, WebP or GIF, up to '.(int) $maxMb.' MB' : 'JPG, PNG, WebP or GIF' }} — SVG is not accepted.
                </span>
            </label>
            <input
                id="{{ $uploadId }}"
                type="file"
                name="{{ $upload }}"
                accept="image/jpeg,image/png,image/webp,image/gif"
                class="sr-only"
                x-on:change="
                    const file = $event.target.files[0];
                    fileName = file ? file.name : '';
                    tooBig = !! (file && {{ (int) ($maxMb ?? 0) }} > 0 && file.size > {{ (int) ($maxMb ?? 0) }} * 1048576);
                "
            >
            <p x-show="fileName" x-cloak class="mt-1.5 flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200">
                <x-ui.icon name="photo" class="h-3.5 w-3.5 text-slate-400" />
                <span class="truncate" x-text="fileName"></span>
                <button type="button" class="font-semibold text-slate-500 hover:text-rose-600 dark:text-slate-400 dark:hover:text-rose-400" x-on:click="fileName = ''; tooBig = false; document.getElementById(@js($uploadId)).value = ''">Clear</button>
            </p>
            <p x-show="tooBig" x-cloak class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">This file is larger than the upload limit and will be refused.</p>
            <x-ui.form.error :for="$upload" />
        </div>
    @endif
</div>
