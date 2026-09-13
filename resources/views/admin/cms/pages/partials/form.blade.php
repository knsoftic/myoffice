{{--
    The page form body, shared by create and edit (phase-03 §8.10 editor tabs).

    @include('admin.cms.pages.partials.form', [
        'page' => $page,                  // ?Page (null or unsaved on create)
        'formId' => 'page-form',
        'templates' => $templates,        // array<string view, string label> — the template allowlist
        'reservedSlugs' => $reservedSlugs,// list<string> — PageService::reservedSlugs(), for the live hint only
        'seo' => $seo,                    // ?SeoMeta
        'seoInherited' => $seoInherited,  // ?SeoPayload
        'bannerAsset' => $bannerAsset,    // ?MediaAsset
        'ogAsset' => $ogAsset,            // ?MediaAsset
        'sectionsCount' => $sectionsCount,// int, edit only
        'slugLocked' => ! $can['changeSlug'], // optional; derived from pages.change_status when absent
        'readonly' => ! $canEdit,
    ])

    Posted fields: title, slug, auto_slug, layout, excerpt, content, show_banner, banner_media_id,
    banner_heading, banner_subheading, template, sort_order, seo[title|meta_description|meta_keywords|
    canonical_url|robots|og_image_media_id]. Rich text is sanitised server-side (INV-13); the slug is
    validated against every row including trashed ones and the reserved list, with the conflict named.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Enums\Cms\PageLayout;
    use App\Enums\Cms\SectionPlacement;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $page = $page ?? null;
    $exists = $page !== null && $page->exists;
    $readonly = (bool) ($readonly ?? false);
    $templates = $templates ?? ['site.pages.default' => 'Default', 'site.pages.wide' => 'Wide', 'site.pages.legal' => 'Legal'];
    $layoutValue = old('layout', $page?->layout instanceof PageLayout ? $page->layout->value : ($page?->layout ?? PageLayout::Content->value));
    $slugLocked = array_key_exists('slugLocked', get_defined_vars())
        ? (bool) $slugLocked
        : ($exists && $page->is_system && ! (auth()->user()?->can('pages.change_status') ?? false));

    $tabs = [
        ['label' => 'Content', 'key' => 'content', 'icon' => 'document-text'],
        ['label' => 'Banner', 'key' => 'banner', 'icon' => 'photo'],
        ['label' => 'SEO', 'key' => 'seo', 'icon' => 'magnifying-glass'],
        ['label' => 'Settings', 'key' => 'settings', 'icon' => 'cog-6-tooth'],
    ];

    $errorTab = 'content';
    foreach ($errors->keys() as $errorKey) {
        $errorTab = match (true) {
            str_starts_with($errorKey, 'seo.') => 'seo',
            in_array($errorKey, ['show_banner', 'banner_media_id', 'banner_heading', 'banner_subheading'], true) => 'banner',
            in_array($errorKey, ['template', 'sort_order'], true) => 'settings',
            default => 'content',
        };
        break;
    }

    $publicBase = rtrim(url('/'), '/');
@endphp

<div x-data="uiTabs(@js($errorTab))" class="space-y-5">
    <x-ui.tabs :tabs="$tabs" />

    {{-- ── Content ─────────────────────────────────────────────────────────── --}}
    <div x-show="is('content')" class="space-y-5 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
        <div
            x-data="cmsSlug(@js([
                'slug' => (string) old('slug', $page?->slug ?? ''),
                'auto' => (bool) old('auto_slug', ! $exists),
                'reserved' => array_values($reservedSlugs ?? []),
                'locked' => $slugLocked || $readonly,
            ]))"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
        >
            <x-ui.form.input
                name="title"
                label="Title"
                :value="$page?->title"
                required
                maxlength="200"
                :readonly="$readonly"
                x-on:input="fromTitle($event.target.value)"
                class="sm:col-span-2"
            />

            <div class="sm:col-span-2">
                <x-ui.form.label for="field-slug" :required="true" class="mb-1.5">Address</x-ui.form.label>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="flex min-w-0 flex-1 items-stretch overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40">
                        <span class="hidden items-center border-r border-slate-200 bg-slate-50 px-3 font-mono text-xs text-slate-500 sm:flex dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">{{ $publicBase }}/</span>
                        <input
                            id="field-slug"
                            name="slug"
                            x-model="slug"
                            x-on:input="auto = false"
                            x-bind:readonly="locked"
                            required
                            maxlength="200"
                            pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?"
                            class="block w-full min-w-0 border-0 bg-transparent px-3 py-2 font-mono text-sm text-slate-900 focus:ring-0 dark:text-white"
                            @error('slug') aria-invalid="true" @enderror
                        >
                    </div>

                    <label class="inline-flex shrink-0 items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="hidden" name="auto_slug" value="0">
                        <input type="checkbox" name="auto_slug" value="1" x-model="auto" x-bind:disabled="locked" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                        From the title
                    </label>
                </div>

                <p class="mt-1 font-mono text-xs text-slate-500 dark:text-slate-400">
                    <span>{{ $publicBase }}/</span><span x-text="slug || '…'" class="text-slate-900 dark:text-white"></span>
                </p>
                <p x-show="isReserved" x-cloak class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">
                    “<span x-text="slug"></span>” is reserved for the system or a later module and cannot be used.
                </p>
                <p x-show="isMalformed" x-cloak class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                    Use lowercase letters, numbers and single hyphens, not at the start or end.
                </p>
                @if ($slugLocked)
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">This is a system page: changing its address needs the publish permission.</p>
                @elseif ($exists && $page->status === ContentStatus::Published)
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">Changing the address of a live page breaks links from outside the site. Menus update automatically.</p>
                @endif
                <x-ui.form.error for="slug" />
            </div>

            <div class="sm:col-span-2">
                <x-ui.form.textarea name="excerpt" label="Excerpt" :value="$page?->excerpt" :rows="2" maxlength="500" :readonly="$readonly" help="Used as the search and social description when the SEO tab leaves it empty." id="field-excerpt" />
                @include('admin.cms.partials.length-meter', ['for' => 'field-excerpt', 'max' => 500])
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" x-data="{ layout: @js((string) $layoutValue) }">
            <x-ui.form.select
                name="layout"
                label="Layout"
                :options="PageLayout::options()"
                :selected="$layoutValue"
                :disabled="$readonly"
                x-on:change="layout = $event.target.value"
                help="Rich text for policies and simple pages; sections to build it from blocks."
            />

            <div class="sm:col-span-2" x-show="layout === @js(PageLayout::Content->value)">
                @include('admin.cms.partials.richtext', [
                    'name' => 'content',
                    'id' => 'field-content',
                    'label' => 'Body',
                    'value' => $page?->content,
                    'rows' => 16,
                    'readonly' => $readonly,
                ])
            </div>

            <div class="sm:col-span-2" x-show="layout === @js(PageLayout::Sections->value)" x-cloak>
                <div class="flex flex-col gap-3 rounded-lg bg-slate-50 p-4 text-sm text-slate-600 ring-1 ring-slate-200 sm:flex-row sm:items-center dark:bg-slate-800/50 dark:text-slate-300 dark:ring-slate-700">
                    <x-ui.icon name="view-columns" class="h-6 w-6 shrink-0 text-brand-600 dark:text-brand-400" />
                    <p class="flex-1">
                        This page is built from sections.
                        @if ($exists)
                            It has {{ app_number((int) ($sectionsCount ?? 0)) }} {{ \Illuminate\Support\Str::plural('section', (int) ($sectionsCount ?? 0)) }}.
                        @else
                            Save the page first, then add sections.
                        @endif
                    </p>
                    @if ($exists && RouteFacade::has('admin.website.sections.index'))
                        @can('website_sections.view_any')
                            <x-ui.button size="sm" variant="secondary" icon="view-columns" :href="route('admin.website.sections.index', ['placement' => SectionPlacement::Page->value, 'page_id' => $page->id])">Manage sections</x-ui.button>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── Banner ──────────────────────────────────────────────────────────── --}}
    <div x-show="is('banner')" x-cloak class="space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
        <x-ui.form.toggle name="show_banner" label="Show a banner at the top of the page" :checked="(bool) ($page?->show_banner ?? true)" :disabled="$readonly" />

        @include('admin.cms.partials.media-picker', [
            'name' => 'banner_media_id',
            'label' => 'Banner image',
            'help' => 'Optional. Without one the banner uses the brand background. It needs alt text before the page is published.',
            'kind' => 'image',
            'selected' => isset($bannerAsset) && $bannerAsset ? [$bannerAsset] : [],
            'selectedIds' => array_filter([$page?->banner_media_id]),
            'readonly' => $readonly,
            'profile' => 'Banner 21:9',
        ])

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.input name="banner_heading" label="Banner heading" :value="$page?->banner_heading" maxlength="200" placeholder="Defaults to the title" :readonly="$readonly" />
            <x-ui.form.input name="banner_subheading" label="Banner subheading" :value="$page?->banner_subheading" maxlength="300" :readonly="$readonly" />
        </div>
    </div>

    {{-- ── SEO ─────────────────────────────────────────────────────────────── --}}
    <div x-show="is('seo')" x-cloak class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
        @include('admin.cms.partials.seo-fields', [
            'seo' => $seo ?? null,
            'inherited' => $seoInherited ?? null,
            'displayUrl' => $publicBase.'/'.($page?->slug ?? 'your-page'),
            'prefix' => 'seo',
            'readonly' => $readonly,
            'ogSelected' => $ogAsset ?? null,
            'ogSelectedIds' => array_filter([($seo ?? null)?->og_image_media_id]),
        ])
    </div>

    {{-- ── Settings ────────────────────────────────────────────────────────── --}}
    <div x-show="is('settings')" x-cloak class="space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.form.select name="template" label="Template" :options="$templates" :selected="$page?->template" placeholder="Default template" :disabled="$readonly" help="Only these templates exist; anything else is refused." />
            <x-ui.form.input name="sort_order" type="number" label="Sort order" :value="$page?->sort_order ?? 0" min="0" :readonly="$readonly" help="Orders the admin list and the legal menu seeder." />
        </div>

        @if ($exists)
            <dl class="grid grid-cols-1 gap-2 border-t border-slate-100 pt-4 text-xs text-slate-500 sm:grid-cols-2 dark:border-slate-800 dark:text-slate-400">
                <div><dt class="inline">System page:</dt> <dd class="inline font-medium text-slate-700 dark:text-slate-200">{{ $page->is_system ? 'Yes — never deletable' : 'No' }}</dd></div>
                <div><dt class="inline">Created:</dt> <dd class="inline font-medium text-slate-700 dark:text-slate-200">{{ app_datetime($page->created_at) }}</dd></div>
                @if ($page->published_at)
                    <div><dt class="inline">{{ $page->status === ContentStatus::Scheduled ? 'Scheduled for' : 'Published' }}:</dt> <dd class="inline font-medium text-slate-700 dark:text-slate-200">{{ app_datetime($page->published_at) }}</dd></div>
                @endif
                @if (filled($page->unpublished_reason))
                    <div class="sm:col-span-2"><dt class="inline">Last unpublished because:</dt> <dd class="inline font-medium text-slate-700 dark:text-slate-200">{{ $page->unpublished_reason }}</dd></div>
                @endif
            </dl>
        @endif
    </div>
</div>
