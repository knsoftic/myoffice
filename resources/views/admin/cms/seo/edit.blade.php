@extends('layouts.admin')

@section('title', 'Edit SEO')

{{--
    SEO editor — admin.website.seo.edit (phase-03 §7.5, §8.12 edit drawer, §6.5; D23).

    Controller variables (Admin\Cms\SeoController@edit, query ?target=page:{id} | route:{name}):
      $target             App\Models\Cms\Page|string      the resolved target
      $targetKey          string                          "page:7" or "route:site.home", posted back as `target`
      $targetName         string
      $meta               ?App\Models\Cms\SeoMeta          the stored row (null = defaults)
      $inherited          App\Services\Cms\Data\SeoPayload  SeoService::for($target) — what renders now, shown in grey
      $completeness       int
      $ogImage            ?App\Models\Cms\MediaAsset
      $robotsOptions      array<string, string>
      $changefreqOptions  array<string, string>
      $ogTypes            list<string>
      $canEdit            bool
      $mediaLibrary       optional picker library (SectionController::mediaLibrary() shape)

    Writes: PUT admin.website.seo.update — target, seo[title|meta_description|meta_keywords|canonical_url|
    robots|og_image_media_id|og_title|og_description|og_type|sitemap_include|sitemap_priority|
    sitemap_changefreq], validated by SeoService::editorRules() (the one SEO rule set). SEO is live on
    save (§2.15); holders of seo.view without seo.edit see the form read-only.
--}}

@php
    use App\Enums\Cms\RobotsDirective;
    use App\Enums\Cms\SitemapChangeFrequency;
    use App\Services\Cms\SeoService;

    $canEdit = (bool) ($canEdit ?? false);
    $readonly = ! $canEdit;
    $token = (string) $targetKey;
    $targetLabel = (string) $targetName;
    $seo = $meta ?? null;
    $resolved = $inherited ?? null;
    $ogAsset = $ogImage ?? null;
    $targetUrl = $target instanceof \App\Models\Cms\Page
        ? (\Illuminate\Support\Facades\Route::has('site.page') ? route('site.page', ['slug' => $target->slug]) : url('/'.$target->slug))
        : (\Illuminate\Support\Facades\Route::has((string) $target) ? rescue(fn () => route((string) $target), null, false) : null);
    $targetUrl = $targetUrl ?: $resolved?->canonicalUrl;
    $enumValue = static fn (mixed $value, string $fallback): string => $value instanceof \BackedEnum ? (string) $value->value : (string) ($value ?? $fallback);

    $social = [
        'ogTitle' => (string) old('seo.og_title', $seo?->og_title ?? ''),
        'ogDescription' => (string) old('seo.og_description', $seo?->og_description ?? ''),
        'title' => (string) old('seo.title', $seo?->title ?? ''),
        'description' => (string) old('seo.meta_description', $seo?->meta_description ?? ''),
        'fallbackTitle' => (string) ($resolved?->title ?? $targetLabel),
        'fallbackDescription' => (string) ($resolved?->metaDescription ?? ''),
        'image' => $resolved?->ogImageUrl,
        'host' => (string) parse_url((string) ($targetUrl ?? url('/')), PHP_URL_HOST),
        'url' => (string) ($targetUrl ?? url('/')),
    ];

    $ogTypes = collect($ogTypes ?? SeoService::OG_TYPES)->mapWithKeys(fn (string $type): array => [$type => \Illuminate\Support\Str::headline(str_replace('.', ' ', $type))])->all();
@endphp

@section('header')
    <x-ui.page-header
        :title="'SEO — '.$targetLabel"
        :subtitle="$targetUrl"
        icon="magnifying-glass"
        :back="route('admin.website.seo.index')"
        :badge="(int) ($completeness ?? 0).'% complete'"
        :badge-color="(int) ($completeness ?? 0) >= 80 ? 'emerald' : ((int) ($completeness ?? 0) >= 50 ? 'amber' : 'rose')"
    >
        <x-slot:actions>
            @if ($targetUrl)
                <x-ui.button variant="secondary" icon="arrow-top-right-on-square" :href="$targetUrl" target="_blank" rel="noopener">Open page</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div
        x-data="{ s: @js($social) }"
        x-on:input="
            const map = { 'seo[og_title]': 'ogTitle', 'seo[og_description]': 'ogDescription', 'seo[title]': 'title', 'seo[meta_description]': 'description' };
            if ($event.target.name && map[$event.target.name]) s[map[$event.target.name]] = $event.target.value;
        "
        class="grid grid-cols-1 gap-6 xl:grid-cols-5"
    >
        <div class="xl:col-span-3" x-data="cmsDirty()" x-on:submit="submitted()">
            <form method="POST" action="{{ route('admin.website.seo.update') }}" class="space-y-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="target" value="{{ $token }}">

                @if ($readonly)
                    <div class="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <x-ui.icon name="lock-closed" class="h-4 w-4" /> Read-only: changing SEO needs the SEO edit permission.
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">{{ $errors->first() }}</div>
                @endif

                <x-ui.card title="Search" icon="magnifying-glass">
                    <div class="space-y-4">
                        <div>
                            <x-ui.form.input name="seo[title]" id="seo-title" label="SEO title" :value="$seo?->title" maxlength="180" :readonly="$readonly" :placeholder="$resolved ? 'Inherited: '.$resolved->title : null" />
                            @include('admin.cms.partials.length-meter', ['for' => 'seo-title', 'max' => 180, 'idealMin' => 50, 'idealMax' => 60])
                        </div>
                        <div>
                            <x-ui.form.textarea name="seo[meta_description]" id="seo-meta-description" label="Meta description" :value="$seo?->meta_description" :rows="3" :readonly="$readonly" :placeholder="$resolved?->metaDescription ? 'Inherited: '.$resolved->metaDescription : 'No description anywhere in the fallback chain'" />
                            @include('admin.cms.partials.length-meter', ['for' => 'seo-meta-description', 'max' => 320, 'idealMin' => 120, 'idealMax' => 160])
                        </div>
                        <x-ui.form.input name="seo[meta_keywords]" label="Meta keywords" :value="$seo?->meta_keywords" maxlength="500" :readonly="$readonly" :placeholder="$resolved?->metaKeywords ? 'Inherited: '.$resolved->metaKeywords : 'Comma separated'" />
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.form.input name="seo[canonical_url]" type="url" label="Canonical URL" :value="$seo?->canonical_url" maxlength="500" :readonly="$readonly" :placeholder="$resolved ? 'Self: '.$resolved->canonicalUrl : null" help="Leave empty unless this content lives at another address." />
                            <x-ui.form.select name="seo[robots]" label="Search engines" :options="$robotsOptions ?? RobotsDirective::options()" :selected="$enumValue($seo?->robots, RobotsDirective::IndexFollow->value)" :disabled="$readonly" :help="$resolved && $resolved->robots->value !== $enumValue($seo?->robots, RobotsDirective::IndexFollow->value) ? 'Currently forced to “'.$resolved->robots->toHeader().'” by a site-wide rule.' : 'Site-wide noindex and maintenance always win.'" />
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Social sharing" icon="share">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.form.input name="seo[og_title]" id="seo-og-title" label="Social title" :value="$seo?->og_title" maxlength="180" :readonly="$readonly" placeholder="Falls back to the SEO title" />
                            <x-ui.form.select name="seo[og_type]" label="Type" :options="$ogTypes" :selected="$seo?->og_type ?? 'website'" :disabled="$readonly" />
                        </div>
                        <x-ui.form.textarea name="seo[og_description]" label="Social description" :value="$seo?->og_description" :rows="2" :readonly="$readonly" placeholder="Falls back to the meta description" />
                        @if ($canEdit)
                            @include('admin.cms.partials.media-picker', [
                                'name' => 'seo[og_image_media_id]',
                                'label' => 'Social image',
                                'help' => '1200×630. Empty falls back to the page banner, then the site default image.',
                                'kind' => 'image',
                                'selected' => $ogAsset ? [$ogAsset] : [],
                                'selectedIds' => array_filter([$seo?->og_image_media_id]),
                                'profile' => 'OG 1200×630',
                            ])
                        @endif
                    </div>
                </x-ui.card>

                @if ($canEdit)
                    <x-ui.card title="Why" icon="pencil">
                        <x-ui.form.input name="reason" label="Reason for the change" maxlength="255" optional help="Recorded with the old and new values in the audit trail." />
                    </x-ui.card>
                @endif

                <x-ui.card title="Sitemap" icon="globe-alt">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="pt-1 sm:col-span-3">
                            <x-ui.form.toggle name="seo[sitemap_include]" label="Include in sitemap.xml" description="Only published, indexable targets are ever listed." :checked="(bool) ($seo?->sitemap_include ?? true)" :disabled="$readonly" />
                        </div>
                        <x-ui.form.input name="seo[sitemap_priority]" type="number" step="0.1" min="0" max="1" label="Priority" :value="$seo?->sitemap_priority ?? '0.5'" :readonly="$readonly" help="0.0 – 1.0" />
                        <x-ui.form.select name="seo[sitemap_changefreq]" label="Change frequency" :options="$changefreqOptions ?? SitemapChangeFrequency::options()" :selected="$enumValue($seo?->sitemap_changefreq, SitemapChangeFrequency::Weekly->value)" :disabled="$readonly" class="sm:col-span-2" />
                    </div>
                </x-ui.card>

                @if ($canEdit)
                    <div class="sticky bottom-0 z-20 -mx-4 flex flex-col-reverse gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:flex-row sm:items-center sm:justify-end sm:rounded-xl sm:border sm:shadow-lg dark:border-slate-800 dark:bg-slate-900/95">
                        <p class="text-xs text-slate-500 sm:mr-auto dark:text-slate-400">
                            <span x-show="dirty" x-cloak class="font-semibold text-amber-700 dark:text-amber-400">Unsaved changes. </span>
                            SEO goes live when you save — it is not part of a page draft.
                        </p>
                        <x-ui.button variant="secondary" :href="route('admin.website.seo.index')">Cancel</x-ui.button>
                        <x-ui.button type="submit" icon="check">Save SEO</x-ui.button>
                    </div>
                @endif
            </form>
        </div>

        {{-- ── Live previews ───────────────────────────────────────────────────── --}}
        <div class="space-y-4 xl:col-span-2">
            <div class="space-y-4 xl:sticky xl:top-20">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <p class="mb-3 text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Search result</p>
                    <p class="truncate text-xs text-emerald-700 dark:text-emerald-400" x-text="s.url"></p>
                    <p class="mt-0.5 text-lg leading-snug text-indigo-700 dark:text-indigo-300" x-text="(() => { const t = s.title || s.fallbackTitle; return t.length > 60 ? t.slice(0, 60) + '…' : t; })()"></p>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400" x-text="(() => { const d = s.description || s.fallbackDescription || 'Search engines will choose text from the page.'; return d.length > 160 ? d.slice(0, 160) + '…' : d; })()"></p>
                </div>

                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <p class="px-4 pt-3 text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">Social card</p>
                    <div class="mt-2 aspect-[1200/630] bg-slate-100 dark:bg-slate-800">
                        <template x-if="s.image">
                            <img x-bind:src="s.image" alt="" class="h-full w-full object-cover">
                        </template>
                        <template x-if="! s.image">
                            <div class="flex h-full items-center justify-center text-xs text-slate-400 dark:text-slate-500">No image in the fallback chain</div>
                        </template>
                    </div>
                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/60">
                        <p class="text-2xs uppercase text-slate-500 dark:text-slate-400" x-text="s.host"></p>
                        <p class="line-clamp-1 text-sm font-semibold text-slate-900 dark:text-white" x-text="s.ogTitle || s.title || s.fallbackTitle"></p>
                        <p class="line-clamp-2 text-xs text-slate-600 dark:text-slate-400" x-text="s.ogDescription || s.description || s.fallbackDescription"></p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">The image shown is the one currently resolved; a newly chosen image appears after saving.</p>
            </div>
        </div>
    </div>
@endsection
