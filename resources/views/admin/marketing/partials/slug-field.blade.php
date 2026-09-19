{{--
    The slug input with an "Edit permalink" switch (phase-04 §6.1, §8.2, §8.7, risk R2).

    @include('admin.marketing.partials.slug-field', [
        'value' => $service->slug,          // null on create
        'sourceId' => 'field-name',         // id of the title/name input the slug follows while auto
        'prefix' => url('/services').'/',   // what the preview line shows before the slug
        'published' => $wasPublished,       // a record that has ever been live: editing warns that links break
        'reserved' => $reservedSlugs,       // SlugGenerator::RESERVED, for the in-browser hint only
        'max' => 180,                       // 200 for blog posts
        'readonly' => false,
    ])

    Posts `slug`. On create the field follows the title until the editor types into it; left empty, the
    server generates it (SlugGenerator::make, suffixing past soft-deleted rows). On edit it is locked
    behind "Edit permalink". The regex, uniqueness and reserved-word rules are the Form Request's — the
    hints here only save a round trip.

    Needs `admin.cms.partials.scripts` on the page (cmsSlug).
--}}

@php
    $current = (string) old('slug', $value ?? '');
    $isNew = blank($value ?? null);
    $published = (bool) ($published ?? false);
    $readonly = (bool) ($readonly ?? false);
    $max = (int) ($max ?? 180);
    $prefix = (string) ($prefix ?? url('/').'/');
    $original = (string) ($value ?? '');

    $config = [
        'slug' => $current,
        'auto' => $isNew && $current === '',
        'reserved' => array_values((array) ($reserved ?? [])),
        'locked' => $readonly,
    ];
@endphp

<div
    x-data="cmsSlug(@js($config))"
    x-init="
        const source = document.getElementById(@js($sourceId ?? 'field-name'));
        if (source) { source.addEventListener('input', () => fromTitle(source.value)); }
    "
    class="w-full"
>
<div x-data="{ editing: @js($isNew || $errors->has('slug')) }">
    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
        <x-ui.form.label for="field-slug">Permalink</x-ui.form.label>

        @if (! $readonly && ! $isNew)
            <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300">
                <input type="checkbox" x-model="editing" data-dirty-ignore class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                Edit permalink
            </label>
        @endif
    </div>

    <div @class([
        'flex items-stretch overflow-hidden rounded-lg border bg-white shadow-sm focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 dark:bg-slate-950/40',
        'border-rose-400 dark:border-rose-500/60' => $errors->has('slug'),
        'border-slate-300 dark:border-slate-700' => ! $errors->has('slug'),
    ])>
        <span class="hidden max-w-[45%] items-center truncate border-r border-slate-200 bg-slate-50 px-3 font-mono text-xs text-slate-500 sm:inline-flex dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">{{ $prefix }}</span>
        <input
            id="field-slug"
            type="text"
            name="slug"
            x-model="slug"
            x-on:input="auto = false"
            x-bind:readonly="! editing || locked"
            value="{{ $current }}"
            maxlength="{{ $max }}"
            autocomplete="off"
            spellcheck="false"
            placeholder="generated from the title"
            @readonly(! $isNew || $readonly)
            @if ($errors->has('slug')) aria-invalid="true" aria-describedby="field-slug-error" @endif
            class="block w-full border-0 bg-transparent px-3 py-2 font-mono text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 read-only:text-slate-500 dark:text-white dark:read-only:text-slate-400"
        >
    </div>

    <p class="mt-1 truncate font-mono text-2xs text-slate-400 dark:text-slate-500" aria-live="polite">
        {{ $prefix }}<span class="text-slate-600 dark:text-slate-300" x-text="slug || '…'">{{ $current }}</span>
    </p>

    <p x-show="isReserved" x-cloak class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">This address is reserved for the website and cannot be used.</p>
    <p x-show="isMalformed" x-cloak class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">Use lowercase letters, numbers and single hyphens only.</p>

    @if ($published)
        <p x-show="editing && slug !== @js($original)" x-cloak class="mt-1.5 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25">
            <x-ui.icon name="exclamation-triangle" class="mt-px h-3.5 w-3.5" />
            <span>This has been published. Changing the permalink breaks every existing link and search result that points at the old address, and the change is recorded in the activity log.</span>
        </p>
    @endif

    <x-ui.form.error for="slug" id="field-slug-error" />
</div>
</div>
