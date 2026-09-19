{{--
    The core fields of one taxonomy term (phase-04 §8.1, §2.2, §2.4, §2.6, §2.13, §2.14): used by the
    create dialog, the per-row edit dialog and the full category editor.

    @include('admin.marketing.partials.taxonomy-fields', [
        'taxonomy' => $taxonomy,     // the config array of admin.marketing.partials.taxonomy-manager
        'term' => $term,             // ?Model — null on create
        'idSuffix' => 'new',         // keeps element ids unique when several dialogs share a page
        'errorsFor' => true,         // false: do not re-print old() input / errors (a dialog for another row)
    ])

    Posts what StoreTaxonomyRequest / UpdateTaxonomyRequest validate: name, slug, description (categories),
    icon (categories), color (technologies, #RRGGBB), is_active, and the image slot through
    <x-cms.image-field> (image_media_id / logo_media_id plus the optional upload). Tags carry name, slug and
    is_active only.
--}}

@php
    $term = $term ?? null;
    $suffix = (string) ($idSuffix ?? 'new');
    $useOld = (bool) ($errorsFor ?? true);
    $pick = static fn (string $key, mixed $default = null): mixed => $useOld ? old($key, $term?->getAttribute($key) ?? $default) : ($term?->getAttribute($key) ?? $default);

    $image = $taxonomy['image'] ?? null;
    $imageAsset = null;

    if ($image && $term !== null) {
        foreach ((array) ($image['relations'] ?? []) as $relationName) {
            if ($term->relationLoaded($relationName) && $term->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
                $imageAsset = $term->getRelation($relationName);
                break;
            }
        }
    }

    $active = $term === null ? true : (bool) $term->getAttribute('is_active');
    $activeOld = $useOld ? old('is_active') : null;
    $activeChecked = $activeOld !== null ? (string) $activeOld === '1' : $active;
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="term-name-{{ $suffix }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Name <span class="text-rose-500">*</span></label>
            <input
                id="term-name-{{ $suffix }}"
                type="text"
                name="name"
                value="{{ $pick('name') }}"
                required
                maxlength="{{ ($taxonomy['nameMax'] ?? 150) }}"
                class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
            >
            @if ($useOld)
                <x-ui.form.error for="name" />
            @endif
        </div>

        <div>
            <label for="term-slug-{{ $suffix }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Slug</label>
            <input
                id="term-slug-{{ $suffix }}"
                type="text"
                name="slug"
                value="{{ $pick('slug') }}"
                maxlength="180"
                autocomplete="off"
                spellcheck="false"
                placeholder="generated from the name"
                class="block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
            >
            <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Lowercase letters, numbers and hyphens. Leave empty to generate it.</p>
            @if ($useOld)
                <x-ui.form.error for="slug" />
            @endif
        </div>
    </div>

    @if ($taxonomy['description'] ?? false)
        <div>
            <label for="term-description-{{ $suffix }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Description <span class="font-normal text-slate-400">(optional)</span></label>
            <textarea
                id="term-description-{{ $suffix }}"
                name="description"
                rows="3"
                maxlength="2000"
                class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
            >{{ $pick('description') }}</textarea>
            <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Plain text, shown on the category strip of the website.</p>
            @if ($useOld)
                <x-ui.form.error for="description" />
            @endif
        </div>
    @endif

    @if (($taxonomy['iconField'] ?? false) || ($taxonomy['color'] ?? false))
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($taxonomy['iconField'] ?? false)
                <div>
                    <label for="term-icon-{{ $suffix }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Icon <span class="font-normal text-slate-400">(optional)</span></label>
                    <input
                        id="term-icon-{{ $suffix }}"
                        type="text"
                        name="icon"
                        value="{{ $pick('icon') }}"
                        maxlength="64"
                        placeholder="e.g. code-bracket"
                        class="block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                    >
                    <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">An icon name, never an uploaded SVG.</p>
                    @if ($useOld)
                        <x-ui.form.error for="icon" />
                    @endif
                </div>
            @endif

            @if ($taxonomy['color'] ?? false)
                <div x-data="{ color: @js((string) ($pick('color') ?? '')) }">
                    <label for="term-color-{{ $suffix }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Colour <span class="font-normal text-slate-400">(optional)</span></label>
                    <div class="flex items-center gap-2">
                        <input type="color" x-bind:value="/^#[0-9a-fA-F]{6}$/.test(color) ? color : '#6366f1'" x-on:input="color = $event.target.value" aria-label="Pick a colour" data-dirty-ignore class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">
                        <input
                            id="term-color-{{ $suffix }}"
                            type="text"
                            name="color"
                            x-model="color"
                            value="{{ $pick('color') }}"
                            maxlength="7"
                            pattern="#[0-9a-fA-F]{6}"
                            placeholder="#RRGGBB"
                            class="block w-full rounded-lg border-slate-300 font-mono text-sm uppercase shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                        >
                    </div>
                    @if ($useOld)
                        <x-ui.form.error for="color" />
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ($image)
        <x-cms.image-field
            :name="$image['column']"
            :upload="$image['upload'] ?? null"
            :label="$image['label'] ?? 'Image'"
            :asset="$imageAsset"
            :profile="$image['profile'] ?? null"
            :max-mb="$taxonomy['maxUploadMb'] ?? null"
            :id-suffix="$suffix"
        />
    @endif

    <div class="rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
        <input type="hidden" name="is_active" value="0">
        <label class="flex cursor-pointer items-start gap-3">
            <input type="checkbox" name="is_active" value="1" @checked($activeChecked) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
            <span class="text-sm">
                <span class="font-medium text-slate-700 dark:text-slate-200">Active</span>
                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $taxonomy['activeHelp'] ?? 'Inactive terms are hidden from the public website and kept here.' }}</span>
            </span>
        </label>
    </div>
</div>
