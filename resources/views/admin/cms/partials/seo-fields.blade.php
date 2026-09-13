{{--
    The SEO panel an entity form embeds (phase-03 §6.5 `<x-cms.seo-fields>`, §8.10 SEO tab, D23).

    @include('admin.cms.partials.seo-fields', [
        'seo' => $seo,                 // ?App\Models\Cms\SeoMeta — the stored row, or null (defaults apply)
        'inherited' => $seoInherited,  // ?App\Services\Cms\Data\SeoPayload — what renders if the fields stay empty
        'displayUrl' => url('/about'), // the public URL the SERP preview shows
        'prefix' => 'seo',             // input prefix; SeoService::rules($prefix) validates exactly these keys
        'readonly' => false,
        'ogSelected' => $ogAsset,      // ?MediaAsset currently set as the OG image
    ])

    Posts exactly the six columns of SeoService::rules(): title, meta_description, meta_keywords,
    canonical_url, robots, og_image_media_id — nothing else, so the entity Form Request can merge that
    one rule set and never restate a seo_meta rule (D23, ND-13). Open Graph text and the sitemap three
    are edited on the SEO manager screen.

    Needs `admin.cms.partials.media-library-json` on the page for the OG image picker.
--}}

@php
    use App\Enums\Cms\RobotsDirective;

    $prefix = $prefix ?? 'seo';
    $readonly = (bool) ($readonly ?? false);
    $seo = $seo ?? null;
    $inherited = $inherited ?? null;
    $input = static fn (string $key): string => $prefix.'['.$key.']';
    $old = static fn (string $key, mixed $default = null): mixed => old($prefix.'.'.$key, $default);

    $robotsValue = $seo?->robots instanceof RobotsDirective ? $seo->robots->value : ($seo?->robots ?? RobotsDirective::IndexFollow->value);

    $inheritedTitle = $inherited?->title;
    $inheritedDescription = $inherited?->metaDescription;
    $inheritedCanonical = $inherited?->canonicalUrl;

    $serp = [
        'title' => (string) $old('title', $seo?->title),
        'description' => (string) $old('meta_description', $seo?->meta_description),
        'fallbackTitle' => (string) ($inheritedTitle ?? ''),
        'fallbackDescription' => (string) ($inheritedDescription ?? ''),
        'url' => (string) ($displayUrl ?? url('/')),
    ];
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
    <div class="space-y-4 xl:col-span-3">
        <div>
            <x-ui.form.input
                :name="$input('title')"
                id="seo-title"
                label="SEO title"
                :value="$seo?->title"
                :placeholder="$inheritedTitle ? 'Inherited: '.$inheritedTitle : 'Falls back to the page title'"
                maxlength="180"
                :readonly="$readonly"
            />
            @include('admin.cms.partials.length-meter', ['for' => 'seo-title', 'max' => 180, 'idealMin' => 50, 'idealMax' => 60])
        </div>

        <div>
            <x-ui.form.textarea
                :name="$input('meta_description')"
                id="seo-meta-description"
                label="Meta description"
                :value="$seo?->meta_description"
                :placeholder="$inheritedDescription ? 'Inherited: '.$inheritedDescription : 'Falls back to the excerpt, then the site description'"
                :rows="3"
                :readonly="$readonly"
            />
            @include('admin.cms.partials.length-meter', ['for' => 'seo-meta-description', 'max' => 320, 'idealMin' => 120, 'idealMax' => 160])
        </div>

        <x-ui.form.input
            :name="$input('meta_keywords')"
            id="seo-meta-keywords"
            label="Meta keywords"
            :value="$seo?->meta_keywords"
            placeholder="Comma separated. Most search engines ignore these."
            maxlength="500"
            :readonly="$readonly"
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.input
                :name="$input('canonical_url')"
                id="seo-canonical-url"
                type="url"
                label="Canonical URL"
                :value="$seo?->canonical_url"
                :placeholder="$inheritedCanonical ? 'Self: '.$inheritedCanonical : 'Empty = this page’s own address'"
                maxlength="500"
                :readonly="$readonly"
                help="Only set this when the same content lives at another address."
            />

            <x-ui.form.select
                :name="$input('robots')"
                id="seo-robots"
                label="Search engines"
                :options="RobotsDirective::options()"
                :selected="$robotsValue"
                :disabled="$readonly"
                help="Site-wide noindex, maintenance mode and preview always win over this."
            />
        </div>

        @include('admin.cms.partials.media-picker', [
            'name' => $input('og_image_media_id'),
            'label' => 'Social sharing image',
            'help' => '1200×630 works everywhere. Empty falls back to the page banner, then the site default.',
            'kind' => 'image',
            'multiple' => false,
            'selected' => isset($ogSelected) && $ogSelected ? [$ogSelected] : [],
            'selectedIds' => array_values(array_filter((array) ($ogSelectedIds ?? []))),
            'profile' => 'OG 1200×630',
        ])
    </div>

    {{-- Live SERP preview (x-cms.serp-preview): truncates the way a results page does. --}}
    <div class="xl:col-span-2">
        <div
            x-data="{ serp: @js($serp) }"
            x-on:input.window="
                if ($event.target.id === 'seo-title') serp.title = $event.target.value;
                if ($event.target.id === 'seo-meta-description') serp.description = $event.target.value;
            "
            class="sticky top-20 space-y-4"
        >
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <p class="mb-3 text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Search result preview</p>
                <p class="truncate text-xs text-emerald-700 dark:text-emerald-400" x-text="serp.url"></p>
                <p
                    class="mt-0.5 line-clamp-1 text-lg leading-snug text-indigo-700 dark:text-indigo-300"
                    x-text="(serp.title || serp.fallbackTitle || 'Untitled').slice(0, 60) + ((serp.title || serp.fallbackTitle || '').length > 60 ? '…' : '')"
                ></p>
                <p
                    class="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-400"
                    x-text="(serp.description || serp.fallbackDescription || 'No description yet — search engines will pick text from the page.').slice(0, 160) + ((serp.description || serp.fallbackDescription || '').length > 160 ? '…' : '')"
                ></p>
                <p x-show="! serp.title && serp.fallbackTitle" class="mt-3 text-2xs text-slate-400 dark:text-slate-500">
                    Grey fields are inherited — leaving them empty is already correct.
                </p>
            </div>
        </div>
    </div>
</div>
