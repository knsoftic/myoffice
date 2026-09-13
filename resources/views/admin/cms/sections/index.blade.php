@extends('layouts.admin')

@section('title', 'Website sections')

{{--
    Section manager — admin.website.sections.index (phase-03 §7.1, §8.4; requirement §7, §100).

    Controller variables (Admin\Cms\SectionController@index):
      $placement      App\Enums\Cms\SectionPlacement
      $page           ?App\Models\Cms\Page                       set when $placement = page (?page_id=, G-4)
      $sections       LengthAwarePaginator<WebsiteSection>        sort_order asc, filtered (one page of 100)
      $types          array<string, string>                       section_key => registry label
      $publishers     array<int, string>                          user id => name, for "published by"
      $placementTabs  list<array{placement: string, page_id: ?int, label: string}>
      $addable        array<string, array{key, label, description, icon, group, unique, required,
                                          disabled: bool, reason: ?string, existing_url: ?string}>
      $canReorder     bool     false while filtered or paginated: reorder posts the placement's EXACT set (INV-5)
      $statusOptions  array<string, string>
      $filters        array<string, mixed>                        the active filters (CmsListRequest)
      $can            array{create: bool, edit: bool, publish: bool, delete: bool, revisions: bool}

    Query string (CmsListRequest): search, status, enabled (enabled|disabled), unpublished (1), page_id.

    Writes (each re-authorised by the route's can: middleware and the policy):
      POST   admin.website.sections.store      {placement}  section_key, page_id (page placement only), name
      POST   admin.website.sections.reorder                 placement, page_id, order[] (JSON, the full set)
      POST   admin.website.sections.toggle     {section}    enabled (0|1)
      POST   admin.website.sections.publish    {section}    label (optional)
      POST   admin.website.sections.unpublish  {section}    reason (required)
      POST   admin.website.sections.duplicate  {section}
      DELETE admin.website.sections.destroy    {section}    reason (required; never offered for a required type)
    Bulk Publish / Enable / Disable call the same per-section routes one by one as JSON, then reload.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Enums\Cms\SectionPlacement;
    use App\Support\Cms\SectionRegistry;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $can = array_merge(['create' => false, 'edit' => false, 'publish' => false, 'delete' => false, 'revisions' => false], $can ?? []);
    $types = $types ?? [];
    $publishers = $publishers ?? [];
    $addable = $addable ?? [];
    $filters = $filters ?? [];
    $pageId = $page?->id;
    $placementLabel = SectionRegistry::placements()[$placement->value]['label_plural'] ?? $placement->label();
    $filtered = collect($filters)->except(['page_id'])->isNotEmpty();
    $sortable = (bool) ($canReorder ?? false) && $can['edit'] && $sections->count() > 1;
    $hasPreview = RouteFacade::has('site.preview.section');
    $total = method_exists($sections, 'total') ? $sections->total() : $sections->count();
    $baseQuery = array_filter(['placement' => $placement->value, 'page_id' => $pageId]);

    $tabs = collect($placementTabs ?? [])->map(fn (array $tab): array => [
        'label' => $tab['label'],
        'url' => route('admin.website.sections.index', array_filter(['placement' => $tab['placement'], 'page_id' => $tab['page_id']])),
        'active' => $tab['placement'] === $placement->value && (int) ($tab['page_id'] ?? 0) === (int) ($pageId ?? 0),
        'icon' => match ($tab['placement']) {
            SectionPlacement::Home->value => 'home',
            SectionPlacement::GlobalHeader->value => 'bars-3',
            SectionPlacement::GlobalFooter->value => 'queue-list',
            default => 'document',
        },
    ])->all();

    $groups = SectionRegistry::groups();
    $addableByGroup = collect($addable)->groupBy(fn (array $type): string => (string) $type['group'], true)
        ->sortBy(fn ($list, string $group): int => $groups[$group]['sort'] ?? 99);

    $bulkModals = [
        'bulk-publish' => ['Publish the selected sections?', 'Each selected section’s current draft goes live immediately. A section with a missing required field or an image without alt text is refused and named.', 'admin.website.sections.publish', [], 'Publish'],
        'bulk-enable' => ['Enable the selected sections?', 'They appear on the public site again with their published content. Their status does not change.', 'admin.website.sections.toggle', ['enabled' => 1], 'Enable'],
        'bulk-disable' => ['Disable the selected sections?', 'They disappear from the public site. Their content and publish status are kept, and they can be enabled again at any time.', 'admin.website.sections.toggle', ['enabled' => 0], 'Disable'],
    ];

    // One numeric placeholder, swapped per row by cmsBulk.
    $placeholder = 987654321;
@endphp

@section('header')
    <x-ui.page-header
        :title="$page ? 'Sections — '.$page->title : $placementLabel"
        subtitle="Enable, disable, reorder and publish. Edits stay drafts until they are published."
        icon="view-columns"
        :badge="app_number($total).' '.\Illuminate\Support\Str::plural('section', $total)"
    >
        <x-slot:actions>
            @if (RouteFacade::has('admin.website.statistics.index'))
                <x-ui.button variant="secondary" icon="chart-bar" :href="route('admin.website.statistics.index')">Statistics</x-ui.button>
            @endif

            @if ($can['create'] && $addable !== [])
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'add-section')">Add section</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >
        @if (count($tabs) > 0)
            <x-ui.tabs :tabs="$tabs" />
        @endif

        <x-ui.filter-bar placeholder="Search name, type or anchor…" :reset="route('admin.website.sections.index', $baseQuery)">
            @if ($pageId)
                <input type="hidden" name="page_id" value="{{ $pageId }}">
            @endif

            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="enabled" :options="['enabled' => 'Enabled', 'disabled' => 'Disabled']" :selected="request('enabled')" placeholder="Enabled or not" size="sm" aria-label="Filter by enabled state" />
            <x-ui.form.select name="unpublished" :options="['1' => 'Has unpublished changes', '0' => 'In sync with the live version']" :selected="request('unpublished')" placeholder="Any draft state" size="sm" aria-label="Filter by unpublished changes" />
        </x-ui.filter-bar>

        @if (! $sortable && $can['edit'] && $sections->count() > 1)
            <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <x-ui.icon name="information-circle" class="h-4 w-4" />
                @if ($filtered)
                    Reordering is off while a filter is active — the order is saved for the whole placement at once.
                @else
                    This placement keeps a fixed order.
                @endif
            </p>
        @endif

        <div x-show="navigating" x-cloak class="space-y-2" aria-hidden="true">
            <x-ui.skeleton variant="card" :count="3" />
        </div>

        <div x-show="! navigating" x-data="cmsBulk()" class="space-y-3">
            @if ($sections->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state
                        icon="view-columns"
                        :title="$filtered ? 'No sections match those filters' : 'This page has no sections yet'"
                        :message="$filtered ? 'Clear the filters to see every section in this placement.' : 'Add the first section to start building it. Nothing shows on the public site until a section is published.'"
                    >
                        <x-slot:action>
                            @if ($filtered)
                                <x-ui.button variant="secondary" :href="route('admin.website.sections.index', $baseQuery)">Clear filters</x-ui.button>
                            @elseif ($can['create'] && $addable !== [])
                                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'add-section')">Add section</x-ui.button>
                            @endif
                        </x-slot:action>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                @if ($can['publish'])
                    <div class="flex flex-wrap items-center gap-3 rounded-xl bg-white px-4 py-2.5 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800"
                                x-on:change="toggleAll($event.target.checked)"
                                x-bind:checked="selected.length > 0 && selected.length === {{ $sections->count() }}"
                                aria-label="Select every section on this page"
                            >
                            <span x-text="selected.length ? selected.length + ' selected' : 'Select'">Select</span>
                        </label>

                        <div x-show="selected.length > 0" x-cloak class="ml-auto flex flex-wrap items-center gap-2">
                            <x-ui.button size="sm" variant="secondary" icon="eye" x-on:click="$dispatch('open-modal', 'bulk-enable')" x-bind:disabled="running">Enable</x-ui.button>
                            <x-ui.button size="sm" variant="secondary" icon="eye-slash" x-on:click="$dispatch('open-modal', 'bulk-disable')" x-bind:disabled="running">Disable</x-ui.button>
                            <x-ui.button size="sm" icon="check-circle" x-on:click="$dispatch('open-modal', 'bulk-publish')" x-bind:disabled="running">Publish</x-ui.button>
                        </div>
                    </div>

                    @foreach ($bulkModals as $modal => [$title, $message, $routeName, $payload, $verb])
                        <x-ui.modal :name="$modal" :title="$title" icon="rectangle-stack" size="md">
                            <p>{{ $message }}</p>
                            <p class="mt-3 font-medium text-slate-900 dark:text-white">
                                <span x-text="selected.length"></span> <span x-text="selected.length === 1 ? 'section' : 'sections'"></span> will be changed.
                            </p>

                            <x-slot:footer>
                                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', {{ \Illuminate\Support\Js::from($modal) }})">Cancel</x-ui.button>
                                <x-ui.button
                                    x-bind:disabled="running"
                                    x-on:click="$dispatch('close-modal', {{ \Illuminate\Support\Js::from($modal) }}); run({{ \Illuminate\Support\Js::from(route($routeName, ['section' => $placeholder])) }}, selected, {{ \Illuminate\Support\Js::from($payload) }}, 'section')"
                                >
                                    {{ $verb }} <span x-text="selected.length" class="ml-1"></span>
                                </x-ui.button>
                            </x-slot:footer>
                        </x-ui.modal>
                    @endforeach
                @endif

                <div
                    x-data="cmsSortable(@js([
                        'url' => route('admin.website.sections.reorder'),
                        'payload' => array_filter(['placement' => $placement->value, 'page_id' => $pageId], fn ($value) => $value !== null),
                        'key' => 'order',
                        'noun' => 'Section',
                        'disabled' => ! $sortable,
                    ]))"
                >
                    <p class="sr-only" aria-live="polite" x-text="announcement"></p>

                    <p x-show="saving" x-cloak class="mb-2 flex items-center gap-2 text-xs font-medium text-brand-600 dark:text-brand-400">
                        <x-ui.icon name="arrow-path" class="h-3.5 w-3.5 animate-spin" /> Saving the new order…
                    </p>

                    <ul data-sortable-list class="space-y-2" aria-label="Sections in display order">
                        @foreach ($sections as $section)
                            @php
                                $key = (string) $section->section_key;
                                $known = SectionRegistry::exists($key);
                                $typeLabel = $types[$key] ?? ($known ? SectionRegistry::label($key) : $key);
                                $displayName = $section->name ?: $typeLabel;
                                $required = $known && SectionRegistry::isRequired($key);
                                $unique = $known && SectionRegistry::isUnique($key);
                                $isPublished = $section->status === ContentStatus::Published;
                                $publisherName = $section->published_by ? ($publishers[(int) $section->published_by] ?? null) : null;
                            @endphp

                            <li
                                data-sortable-id="{{ $section->id }}"
                                data-sortable-label="{{ $displayName }}"
                                x-on:dragstart="dragStart($event)"
                                x-on:dragover.prevent="dragOver($event)"
                                x-on:drop.prevent="drop()"
                                x-on:dragend="dragEnd($event)"
                                @class([
                                    'rounded-xl bg-white p-3 shadow-sm ring-1 transition sm:p-4 dark:bg-slate-900',
                                    'ring-rose-300 dark:ring-rose-500/40' => ! $known,
                                    'ring-slate-200/70 dark:ring-slate-800' => $known,
                                    'opacity-75' => ! $section->is_enabled,
                                ])
                            >
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                                    <div class="flex min-w-0 flex-1 items-start gap-3">
                                        @if ($can['publish'])
                                            <input
                                                type="checkbox"
                                                data-bulk-id
                                                value="{{ $section->id }}"
                                                x-model="selected"
                                                aria-label="Select {{ $displayName }}"
                                                class="mt-2.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800"
                                            >
                                        @endif

                                        {{-- Drag handle: the only draggable surface, so text stays selectable --}}
                                        <button
                                            type="button"
                                            data-sortable-handle
                                            x-on:pointerdown="arm($event)"
                                            @disabled(! $sortable)
                                            aria-roledescription="drag handle"
                                            aria-label="Reorder {{ $displayName }}"
                                            @class([
                                                'mt-1 inline-flex h-8 w-6 shrink-0 items-center justify-center rounded-md text-slate-400 dark:text-slate-500',
                                                'cursor-grab hover:bg-slate-100 hover:text-slate-700 active:cursor-grabbing dark:hover:bg-slate-800 dark:hover:text-slate-200' => $sortable,
                                                'cursor-not-allowed opacity-40' => ! $sortable,
                                            ])
                                        >
                                            <x-ui.icon name="ellipsis-vertical" class="h-5 w-5" />
                                        </button>

                                        <span @class([
                                            'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                                            'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400' => $known,
                                            'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' => ! $known,
                                        ])>
                                            <x-ui.icon :name="$known ? SectionRegistry::icon($key) : 'exclamation-triangle'" class="h-5 w-5" />
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('admin.website.sections.edit', $section) }}" class="truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                                    {{ $displayName }}
                                                </a>

                                                <x-ui.badge color="slate" variant="outline" size="sm">{{ $typeLabel }}</x-ui.badge>

                                                @if (filled($section->anchor))
                                                    <x-ui.badge color="indigo" size="sm">#{{ $section->anchor }}</x-ui.badge>
                                                @endif

                                                @if ($required)
                                                    <x-ui.badge color="slate" size="sm" icon="lock-closed" title="Required: can be disabled, never removed.">Required</x-ui.badge>
                                                @endif
                                            </div>

                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                                @include('admin.cms.partials.status-badge', [
                                                    'status' => $section->status,
                                                    'unpublished' => $section->has_unpublished_changes,
                                                    'published' => filled($section->published_hash),
                                                    'enabled' => (bool) $section->is_enabled,
                                                    'orphaned' => ! $known,
                                                ])

                                                @if ($section->published_at)
                                                    <span>
                                                        Published <time datetime="{{ $section->published_at->toIso8601String() }}">{{ app_datetime($section->published_at) }}</time>@if ($publisherName) by {{ $publisherName }}@endif
                                                    </span>
                                                @elseif ($section->draft_updated_at)
                                                    <span>Draft saved {{ app_datetime($section->draft_updated_at) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-1.5 lg:justify-end">
                                        @if ($can['publish'])
                                            <form method="POST" action="{{ route('admin.website.sections.toggle', $section) }}" class="mr-1">
                                                @csrf
                                                <input type="hidden" name="enabled" value="{{ $section->is_enabled ? 0 : 1 }}">
                                                <button
                                                    type="submit"
                                                    role="switch"
                                                    aria-checked="{{ $section->is_enabled ? 'true' : 'false' }}"
                                                    title="{{ $section->is_enabled ? 'Disable — hide from the public site' : 'Enable — show on the public site' }}"
                                                    class="relative inline-flex items-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950"
                                                >
                                                    <span @class(['h-6 w-11 rounded-full transition-colors', 'bg-brand-600 dark:bg-brand-500' => $section->is_enabled, 'bg-slate-200 dark:bg-slate-700' => ! $section->is_enabled]) aria-hidden="true"></span>
                                                    <span @class(['pointer-events-none absolute left-[3px] h-[1.125rem] w-[1.125rem] rounded-full bg-white shadow-sm transition-transform', 'translate-x-5' => $section->is_enabled]) aria-hidden="true"></span>
                                                    <span class="sr-only">{{ $section->is_enabled ? 'Disable' : 'Enable' }} {{ $displayName }}</span>
                                                </button>
                                            </form>
                                        @endif

                                        @if ($sortable)
                                            <x-ui.icon-button icon="chevron-up" label="Move {{ $displayName }} up" size="sm" x-on:click="move($el, -1)" />
                                            <x-ui.icon-button icon="chevron-down" label="Move {{ $displayName }} down" size="sm" x-on:click="move($el, 1)" />
                                        @endif

                                        <x-ui.icon-button icon="pencil" label="Edit {{ $displayName }}" size="sm" :href="route('admin.website.sections.edit', $section)" />

                                        @if ($hasPreview && $known)
                                            <x-ui.icon-button icon="eye" label="Preview the draft of {{ $displayName }}" size="sm" :href="route('site.preview.section', $section)" target="_blank" rel="noopener" />
                                        @endif

                                        @if ($can['revisions'])
                                            <x-ui.icon-button icon="clock" label="Revisions of {{ $displayName }}" size="sm" :href="route('admin.website.sections.revisions.index', $section)" />
                                        @endif

                                        @if ($can['create'] && $known && ! $unique)
                                            <form method="POST" action="{{ route('admin.website.sections.duplicate', $section) }}">
                                                @csrf
                                                <x-ui.icon-button type="submit" icon="clipboard-document" label="Duplicate {{ $displayName }}" size="sm" />
                                            </form>
                                        @endif

                                        @if ($can['publish'] && $known)
                                            <x-ui.confirm
                                                :action="route('admin.website.sections.publish', $section)"
                                                method="POST"
                                                :title="'Publish '.$displayName.'?'"
                                                message="The current draft replaces the live version for every visitor. A missing required field or an image without alt text stops the publish and is named."
                                                confirm-label="Publish"
                                                variant="warning"
                                                icon="check-circle"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.button size="sm" :variant="$section->has_unpublished_changes || ! $isPublished ? 'primary' : 'secondary'" icon="check-circle">
                                                        {{ $isPublished && ! $section->has_unpublished_changes ? 'Republish' : 'Publish' }}
                                                    </x-ui.button>
                                                </x-slot:trigger>
                                            </x-ui.confirm>

                                            @if ($isPublished)
                                                <x-ui.confirm
                                                    :action="route('admin.website.sections.unpublish', $section)"
                                                    method="POST"
                                                    :id="'unpublish-section-'.$section->id"
                                                    :title="'Unpublish '.$displayName.'?'"
                                                    message="It stops rendering on the public site. The published version is kept so it can be published again without loss."
                                                    confirm-label="Unpublish"
                                                    variant="warning"
                                                    icon="eye-slash"
                                                >
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="eye-slash" label="Unpublish {{ $displayName }}" size="sm" />
                                                    </x-slot:trigger>

                                                    <div class="mt-4">
                                                        <label for="unpublish-reason-{{ $section->id }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                            Reason <span class="text-rose-500">*</span> <span class="font-normal text-slate-400">(recorded in the audit trail)</span>
                                                        </label>
                                                        <input id="unpublish-reason-{{ $section->id }}" type="text" name="reason" form="unpublish-section-{{ $section->id }}" required minlength="{{ \App\Http\Requests\Cms\CmsFormRequest::REASON_MIN }}" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                                    </div>
                                                </x-ui.confirm>
                                            @endif
                                        @endif

                                        @if ($can['delete'])
                                            @if ($required)
                                                <x-ui.icon-button icon="trash" label="Required sections can be disabled but never removed" size="sm" :disabled="true" />
                                            @else
                                                <x-ui.confirm
                                                    :action="route('admin.website.sections.destroy', $section)"
                                                    :id="'remove-section-'.$section->id"
                                                    :title="'Remove '.$displayName.'?'"
                                                    message="It leaves this page and the public site. The row is moved to the trash, not erased, and its revisions are kept."
                                                    confirm-label="Remove section"
                                                >
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="trash" variant="danger" label="Remove {{ $displayName }}" size="sm" />
                                                    </x-slot:trigger>

                                                    <div class="mt-4">
                                                        <label for="remove-reason-{{ $section->id }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                            Reason <span class="text-rose-500">*</span>
                                                        </label>
                                                        <input id="remove-reason-{{ $section->id }}" type="text" name="reason" form="remove-section-{{ $section->id }}" required minlength="{{ \App\Http\Requests\Cms\CmsFormRequest::REASON_MIN }}" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                                    </div>
                                                </x-ui.confirm>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if (method_exists($sections, 'hasPages') && $sections->hasPages())
                    <x-ui.card :compact="true">
                        <x-ui.pagination-summary :paginator="$sections" label="sections" />
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>

    {{-- Add section (§8.4): grouped; a placed unique type is disabled WITH the reason --}}
    @if ($can['create'] && $addable !== [])
        <x-ui.modal name="add-section" title="Add a section" :subtitle="$placementLabel" icon="plus" size="lg" :show="$errors->has('section_key')">
            @include('admin.cms.sections.partials.add-form', [
                'placement' => $placement,
                'pageId' => $pageId,
                'addableByGroup' => $addableByGroup,
                'groups' => $groups,
                'formId' => 'add-section-form',
            ])

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'add-section')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="add-section-form" icon="plus">Add as a draft</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@endsection
