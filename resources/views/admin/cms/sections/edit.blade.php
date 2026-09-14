@extends('layouts.admin')

@section('title', 'Edit section')

{{--
    Section editor — admin.website.sections.edit (phase-03 §7.1, §8.5-§8.8; requirement §8, §9, §10).

    Controller variables (Admin\Cms\SectionController@edit):
      $section        App\Models\Cms\WebsiteSection
      $placement      App\Enums\Cms\SectionPlacement
      $orphaned       bool                                   section_key no longer registered (INV-2)
      $type           ?array                                 SectionRegistry::type($key)
      $fields         array<string, array>                   SectionRegistry::fields($key)
      $repeaters      array<string, array>                   SectionRegistry::repeaters($key)
      $mediaRoles     array<string, array>                   SectionRegistry::mediaRoles($key)
      $tabs           array<string, string>                  SectionRegistry::TABS
      $draft          array                                  SectionService::canonicalPayload(): fields, columns,
                                                             items[group] => list of item arrays, media[role] => ids
      $assets         Collection<int, MediaAsset>            every asset the draft references, keyed by id
      $mediaLibrary   list<array{id, name, alt_text, mime_type, kind, url, width, height}>   picker library
      $options        array{cta_blocks: Collection, menus: Collection, faq_categories: Collection, pages: Collection,
                            faqs: Collection}                faqs: the `faq` section's hand-picked questions picker
      $statistics     array<string, ?string>                 StatisticsProvider::all(), metric => value (INV-12)
      $publisher      ?string                                name of the last publisher
      $revisionCount  int
      $previewUrl     ?string                                site.preview.section, when installed
      $can            array{edit: bool, publish: bool, duplicate: bool, delete: bool, revisions: bool}

    Writes:
      PUT  admin.website.sections.update {section}  content[field]…, media[role] (id | '') or media[role][],
                                                    faqs, faqs[] (a `faq` section's hand-picked questions),
                                                    name, anchor, publish (0 = save draft, 1 = save and publish)
      POST admin.website.sections.publish {section}  label (optional)
      Repeater items: see sections/partials/repeater.blade.php and item-form.blade.php.

    Error keys: content.{field}, content.{field}.{part}, media.{role}, faqs, name, anchor, publish, action — the keys
    UpdateSectionRequest and SectionService report, so every refusal lands on its own field.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Enums\Cms\ImageProfile;
    use App\Enums\Cms\SectionPlacement;
    use App\Support\Cms\SectionRegistry;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $key = (string) $section->section_key;
    $orphaned = (bool) ($orphaned ?? ! SectionRegistry::exists($key));
    $can = array_merge(['edit' => false, 'publish' => false, 'duplicate' => false, 'delete' => false, 'revisions' => false], $can ?? []);
    $placement = $placement ?? ($section->placement instanceof SectionPlacement ? $section->placement : SectionPlacement::tryFrom((string) $section->placement));
    $typeLabel = $orphaned ? $key : SectionRegistry::label($key);
    $displayName = $section->name ?: $typeLabel;
    $backUrl = route('admin.website.sections.index', array_filter([
        'placement' => $placement?->value ?? SectionPlacement::Home->value,
        'page_id' => $section->page_id,
    ]));
    $previewUrl = $previewUrl ?? null;
    $isPublished = $section->status === ContentStatus::Published;
    $draft = $draft ?? ['fields' => [], 'columns' => [], 'items' => [], 'media' => []];
    $assets = collect($assets ?? []);
    $options = $options ?? [];
    $statistics = $statistics ?? [];

    $fields = $orphaned ? [] : ($fields ?? SectionRegistry::fields($key));
    $mediaRoles = $orphaned ? [] : ($mediaRoles ?? SectionRegistry::mediaRoles($key));
    $repeaters = $orphaned ? [] : ($repeaters ?? SectionRegistry::repeaters($key));
    $tabLabels = $tabs ?? SectionRegistry::TABS;

    $refs = [
        'menus' => $options['menus'] ?? collect(),
        'ctaBlocks' => $options['cta_blocks'] ?? collect(),
        'faqCategories' => $options['faq_categories'] ?? collect(),
        'pages' => $options['pages'] ?? collect(),
        'assets' => $assets,
    ];

    $contentValues = is_array($draft['fields'] ?? null) ? $draft['fields'] : [];
    $columnValues = is_array($draft['columns'] ?? null) ? $draft['columns'] : [];
    $valueOf = static function (string $name, array $field) use ($contentValues, $columnValues): mixed {
        if ($field['stored'] === SectionRegistry::STORED_COLUMN) {
            return $columnValues[(string) $field['column']] ?? null;
        }

        return array_key_exists($name, $contentValues) ? $contentValues[$name] : $field['default'];
    };

    $editable = array_filter($fields, static fn (array $field): bool => $field['stored'] !== SectionRegistry::STORED_MEDIA);

    // §8.5: more than eight fields -> Content / Media / Buttons / Advanced tabs.
    $useTabs = count($editable) > 8 || ($mediaRoles !== [] && count($editable) > 4);
    $byTab = [];
    foreach ($editable as $name => $field) {
        $byTab[$field['tab']][$name] = $field;
    }
    $tabList = [];
    foreach ($tabLabels as $tabKey => $tabLabel) {
        if (! empty($byTab[$tabKey]) || ($tabKey === SectionRegistry::TAB_MEDIA && $mediaRoles !== [])) {
            $tabList[] = ['label' => $tabLabel, 'key' => $tabKey];
        }
    }
    $useTabs = $useTabs && count($tabList) > 1;
    $firstTab = $tabList[0]['key'] ?? SectionRegistry::TAB_CONTENT;

    // Open the tab holding the first error after a refused save.
    $errorTab = null;
    foreach ($errors->keys() as $errorKey) {
        if (str_starts_with($errorKey, 'media.')) {
            $errorTab = SectionRegistry::TAB_MEDIA;
            break;
        }
        $fieldName = explode('.', (string) preg_replace('/^content\./', '', $errorKey))[0];
        if (isset($editable[$fieldName])) {
            $errorTab = $editable[$fieldName]['tab'];
            break;
        }
    }
    $openTab = $errorTab ?? $firstTab;

    // Section-level errors only; an item form's refusal (`_item` posted) is shown inside that item form.
    $sectionErrors = old('_item') === null ? $errors : new \Illuminate\Support\ViewErrorBag();

    $spanClass = static fn (int $span): string => match (true) {
        $span <= 3 => 'sm:col-span-3',
        $span <= 4 => 'sm:col-span-4',
        $span <= 6 => 'sm:col-span-6',
        $span <= 8 => 'sm:col-span-8',
        default => 'sm:col-span-12',
    };

    $videoWarnBytes = 8 * 1024 * 1024;
    $pageTitle = $section->relationLoaded('page') && $section->page ? ' · '.$section->page->title : '';
@endphp

@section('header')
    <x-ui.page-header :title="$displayName" :subtitle="$typeLabel.' · '.($placement?->label() ?? 'Unknown placement').$pageTitle" :icon="$orphaned ? 'exclamation-triangle' : SectionRegistry::icon($key)" :back="$backUrl">
        <div class="mt-2">
            @include('admin.cms.partials.status-badge', [
                'status' => $section->status,
                'unpublished' => $section->has_unpublished_changes,
                'published' => filled($section->published_hash),
                'enabled' => (bool) $section->is_enabled,
                'orphaned' => $orphaned,
            ])
        </div>

        <x-slot:actions>
            @if ($can['revisions'])
                <x-ui.button variant="secondary" icon="clock" :href="route('admin.website.sections.revisions.index', $section)">
                    Revisions @isset($revisionCount)<span class="ml-1 text-xs text-slate-400">{{ (int) $revisionCount }}</span>@endisset
                </x-ui.button>
            @endif

            @if ($previewUrl && ! $orphaned)
                <x-ui.button variant="secondary" icon="arrow-top-right-on-square" :href="$previewUrl" target="_blank" rel="noopener">Preview</x-ui.button>
            @endif

            @if ($can['duplicate'] && ! $orphaned && ! SectionRegistry::isUnique($key))
                <form method="POST" action="{{ route('admin.website.sections.duplicate', $section) }}">
                    @csrf
                    <x-ui.icon-button type="submit" variant="secondary" icon="clipboard-document" label="Duplicate as a new draft" />
                </form>
            @endif

            @if ($can['publish'] && ! $orphaned && ($section->has_unpublished_changes || ! $isPublished))
                <x-ui.confirm
                    :action="route('admin.website.sections.publish', $section)"
                    method="POST"
                    id="publish-section-form"
                    :title="'Publish '.$displayName.'?'"
                    message="The saved draft replaces the live version for every visitor. Unsaved edits in the form below are not included — save them first, or use Save & publish."
                    confirm-label="Publish"
                    variant="warning"
                    icon="check-circle"
                >
                    <x-slot:trigger>
                        <x-ui.button icon="check-circle">Publish</x-ui.button>
                    </x-slot:trigger>

                    <div class="mt-4">
                        <label for="publish-label" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                            Version label <span class="font-normal text-slate-400">(optional, e.g. “Ramadan campaign”)</span>
                        </label>
                        <input id="publish-label" type="text" name="label" form="publish-section-form" maxlength="150" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                    </div>
                </x-ui.confirm>
            @endif

            @if ($can['delete'] && ! $orphaned && ! SectionRegistry::isRequired($key))
                <x-ui.confirm
                    :action="route('admin.website.sections.destroy', $section)"
                    id="remove-section-form"
                    :title="'Remove '.$displayName.'?'"
                    message="It leaves this page and the public site. The row moves to the trash and its revisions are kept."
                    confirm-label="Remove section"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Remove section" />
                    </x-slot:trigger>

                    <div class="mt-4">
                        <label for="remove-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                        <input id="remove-reason" type="text" name="reason" form="remove-section-form" required minlength="{{ \App\Http\Requests\Cms\CmsFormRequest::REASON_MIN }}" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                    </div>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    @if ($orphaned)
        <x-ui.card>
            <x-ui.empty-state
                icon="exclamation-triangle"
                title="This section type is no longer registered"
                :message="'The type “'.$key.'” is not declared in the section registry, so it never renders on the public site and cannot be edited. Its content and revisions are kept.'"
            >
                <x-slot:action>
                    <x-ui.button variant="secondary" :href="$backUrl">Back to the sections</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            {{-- ── Form pane ─────────────────────────────────────────────────────── --}}
            <div class="space-y-6 lg:col-span-3">
                @if (! $can['edit'])
                    <div class="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <x-ui.icon name="lock-closed" class="h-4 w-4" />
                        You can view this section. Editing needs the website sections edit permission.
                    </div>
                @endif

                @if ($sectionErrors->any())
                    <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
                        <p class="font-semibold">Nothing was saved or published.</p>
                        <p class="mt-0.5">{{ $sectionErrors->first() }}</p>
                    </div>
                @endif

                <div x-data="cmsDirty()" x-on:submit="submitted()">
                    <form id="section-form" method="POST" action="{{ route('admin.website.sections.update', $section) }}" class="space-y-6">
                        @csrf
                        @method('PUT')

                        <div x-data="uiTabs(@js($openTab))" class="space-y-5">
                            @if ($useTabs)
                                <x-ui.tabs :tabs="$tabList" />
                            @endif

                            @foreach ($tabLabels as $tabKey => $tabLabel)
                                @continue(empty($byTab[$tabKey]) && ! ($tabKey === SectionRegistry::TAB_MEDIA && $mediaRoles !== []))

                                <div
                                    @if ($useTabs) x-show="is(@js($tabKey))" @if ($tabKey !== $openTab) x-cloak @endif @endif
                                    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800"
                                >
                                    @unless ($useTabs)
                                        <h3 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">{{ $tabLabel }}</h3>
                                    @endunless

                                    @if (! empty($byTab[$tabKey]))
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-12">
                                            @foreach ($byTab[$tabKey] as $name => $field)
                                                <div class="{{ $spanClass((int) $field['span']) }}">
                                                    @include('admin.cms.partials.field', [
                                                        'field' => $field,
                                                        'name' => 'content['.$name.']',
                                                        'value' => $valueOf($name, $field),
                                                        'refs' => $refs,
                                                        'readonly' => ! $can['edit'],
                                                    ])
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($tabKey === SectionRegistry::TAB_MEDIA && $mediaRoles !== [])
                                        <div @class(['space-y-5', 'mt-6 border-t border-slate-100 pt-5 dark:border-slate-800' => ! empty($byTab[$tabKey])])>
                                            @if (array_key_exists('background_video', $mediaRoles))
                                                <div class="rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800/50 dark:text-slate-300 dark:ring-slate-700">
                                                    <p class="font-semibold text-slate-700 dark:text-slate-200">How the background video behaves</p>
                                                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                                        <li>It always plays muted and looped, with no controls.</li>
                                                        <li>The poster image is shown instead below tablet width, when background video is switched off in Settings → Website &amp; Forms, and for visitors who ask for reduced motion.</li>
                                                        <li>A video needs a poster; publishing without one is refused.</li>
                                                    </ul>
                                                </div>
                                            @endif

                                            @foreach ($mediaRoles as $role => $mediaSlot)
                                                @php
                                                    $roleIds = array_map('intval', (array) ($draft['media'][$role] ?? []));
                                                    $placed = collect($roleIds)->map(fn (int $id) => $assets->get($id) ?? $assets->firstWhere('id', $id))->filter()->values();
                                                    $large = $mediaSlot['kind'] === SectionRegistry::KIND_VIDEO ? $placed->first(fn ($asset) => (int) $asset->size_bytes > $videoWarnBytes) : null;
                                                    $profileLabel = $mediaSlot['profile'] instanceof ImageProfile ? $mediaSlot['profile']->label() : null;
                                                @endphp

                                                <div>
                                                    @if ($can['edit'])
                                                        @include('admin.cms.partials.media-picker', [
                                                            'name' => 'media['.$role.']',
                                                            'label' => $mediaSlot['label'],
                                                            'help' => $mediaSlot['help'],
                                                            'kind' => $mediaSlot['kind'],
                                                            'multiple' => (bool) $mediaSlot['multiple'],
                                                            'selected' => $placed,
                                                            'selectedIds' => $roleIds,
                                                            'required' => (bool) $mediaSlot['required'],
                                                            'profile' => $profileLabel,
                                                        ])
                                                    @else
                                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $mediaSlot['label'] }}</p>
                                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $placed->isEmpty() ? 'Empty' : $placed->map(fn ($asset) => $asset->original_name)->implode(', ') }}</p>
                                                    @endif

                                                    @if ($large)
                                                        <p class="mt-1.5 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                                                            <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                            This video is {{ app_number(((int) $large->size_bytes) / 1048576, 1) }} MB. Visitors on mobile data download all of it — consider a shorter clip or a video CDN.
                                                        </p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            {{-- A FAQ section's hand-picked questions (§2.11): posted with this form as faqs[] --}}
                            @if ($key === 'faq')
                                @include('admin.cms.sections.partials.faq-picker', [
                                    'choices' => $options['faqs'] ?? collect(),
                                    'selectedIds' => $draft['faqs'] ?? [],
                                    'source' => isset($fields['source']) ? $valueOf('source', $fields['source']) : null,
                                    'canEdit' => $can['edit'],
                                ])
                            @endif

                            {{-- Identity: admin label and the public #anchor a menu item can link to --}}
                            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 sm:p-5 dark:bg-slate-900 dark:ring-slate-800">
                                <h3 class="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Label and anchor</h3>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <x-ui.form.input name="name" label="Admin label" :value="$section->name" :placeholder="$typeLabel" maxlength="150" :readonly="! $can['edit']" help="Only shown in the admin." />
                                    <x-ui.form.input name="anchor" label="Anchor" :value="$section->anchor" prefix="#" placeholder="about" maxlength="64" :readonly="! ($can['anchor'] ?? $can['edit'])" :help="($can['edit'] && ! ($can['anchor'] ?? true)) ? 'Visitors’ menu links use it on the live page: changing it needs the publish permission.' : 'Lowercase letters, numbers and hyphens. Menu items can link to it.'" />
                                </div>
                            </div>
                        </div>

                        @if ($can['edit'])
                            <noscript>
                                <div class="flex justify-end">
                                    <button type="submit" name="publish" value="0" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Save draft</button>
                                </div>
                            </noscript>
                        @endif
                    </form>

                    {{-- ── Publish bar (x-cms.publish-bar) ─────────────────────────── --}}
                    @if ($can['edit'])
                        <div class="sticky bottom-0 z-20 -mx-4 mt-6 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:shadow-lg dark:border-slate-800 dark:bg-slate-900/95">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <div class="min-w-0 flex-1 text-xs text-slate-500 dark:text-slate-400">
                                    <p x-show="dirty" x-cloak class="font-semibold text-amber-700 dark:text-amber-400">You have unsaved changes.</p>
                                    <p>
                                        @if ($section->draft_updated_at)
                                            Draft saved {{ app_datetime($section->draft_updated_at) }}.
                                        @endif
                                        @if ($section->published_at)
                                            Live version published {{ app_datetime($section->published_at) }}@if (filled($publisher ?? null)) by {{ $publisher }}@endif.
                                        @else
                                            Never published.
                                        @endif
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="submit" form="section-form" name="publish" value="0" variant="secondary" icon="check">Save draft</x-ui.button>

                                    @if ($can['publish'])
                                        <x-ui.button type="submit" form="section-form" name="publish" value="1" icon="check-circle">Save &amp; publish</x-ui.button>
                                    @else
                                        <span title="You can save drafts; publishing needs the publish permission.">
                                            <x-ui.button icon="check-circle" :disabled="true">Save &amp; publish</x-ui.button>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ── Repeaters (separate forms; never nested in the section form) ── --}}
                @foreach ($repeaters as $group => $repeater)
                    @include('admin.cms.sections.partials.repeater', [
                        'section' => $section,
                        'group' => $group,
                        'repeater' => $repeater,
                        'items' => $draft['items'][$group] ?? [],
                        'refs' => $refs,
                        'statistics' => $statistics,
                        'canEdit' => $can['edit'],
                    ])
                @endforeach
            </div>

            {{-- ── Preview pane (x-cms.preview-frame) ──────────────────────────────── --}}
            <div class="lg:col-span-2">
                <div class="space-y-3 lg:sticky lg:top-20" x-data="{ device: 'desktop', nonce: 0 }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Draft preview</h3>

                        @if ($previewUrl)
                            <div class="flex items-center gap-1">
                                <div class="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="group" aria-label="Preview width">
                                    @foreach (['mobile' => '375 px', 'tablet' => '768 px', 'desktop' => 'Full width'] as $device => $deviceLabel)
                                        <button
                                            type="button"
                                            x-on:click="device = @js($device)"
                                            x-bind:aria-pressed="device === @js($device) ? 'true' : 'false'"
                                            x-bind:class="device === @js($device) ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-900 dark:text-brand-300' : 'text-slate-500 dark:text-slate-400'"
                                            class="rounded-md px-2 py-1 text-xs font-medium"
                                            title="{{ $deviceLabel }}"
                                        >
                                            {{ ucfirst($device) }}
                                        </button>
                                    @endforeach
                                </div>
                                <x-ui.icon-button icon="arrow-path" label="Reload preview" size="sm" x-on:click="nonce++" />
                                <x-ui.icon-button icon="arrow-top-right-on-square" label="Open preview in a new tab" size="sm" :href="$previewUrl" target="_blank" rel="noopener" />
                            </div>
                        @endif
                    </div>

                    @if ($previewUrl)
                        <div class="overflow-x-auto rounded-xl bg-slate-100 p-2 ring-1 ring-slate-200 dark:bg-slate-950 dark:ring-slate-800">
                            <template x-for="key in [nonce]" :key="key">
                                <iframe
                                    src="{{ $previewUrl }}"
                                    title="Draft preview of {{ $displayName }}"
                                    loading="lazy"
                                    class="mx-auto block h-[70vh] rounded-lg bg-white shadow-sm dark:bg-slate-900"
                                    x-bind:style="device === 'mobile' ? 'width: 375px' : (device === 'tablet' ? 'width: 768px' : 'width: 100%')"
                                ></iframe>
                            </template>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Shows the saved draft — never cached, never indexed. Save the form, then reload the preview.
                        </p>
                    @else
                        <x-ui.card>
                            <x-ui.empty-state :compact="true" icon="eye" title="Preview is not available yet" message="The public preview route has not been installed." />
                        </x-ui.card>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection
