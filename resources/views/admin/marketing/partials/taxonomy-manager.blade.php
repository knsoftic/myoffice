{{--
    The one list screen behind the five Phase 4 taxonomies (phase-04 §8.1, §6.2): service categories,
    portfolio categories, blog categories, blog tags and technologies. Each index view sets `$taxonomy`
    and includes this partial, which fills the `title`, `header` and `content` sections.

    Controller variables (the same for all five index actions):
      $terms             LengthAwarePaginator<term> — withCount() of every child relation named in
                         $taxonomy['children'] (services_count, portfolio_items_count, blog_posts_count),
                         the image/logo relation eager-loaded for the three categories and technologies
      $filters           array<string, mixed>   the validated query: search, state (active|inactive)
      $sort              string                 name | slug | sort_order | updated_at   (default sort_order)
      $direction         string                 asc | desc
      $counts            array{all: int, active: int, inactive: int}
      $reassignOptions   array<int, string>     id => name of EVERY live term of this taxonomy (the reassign
                         dialog lists all of them except the row being deleted)
      $mediaLibrary      optional picker library (SectionController::mediaLibrary() shape) — image/logo pickers
      $maxUploadMb       optional int           courtesy hint on the upload control
      $canReorder        optional bool          the controller's own answer; ANDed with the checks below
      $creating          optional bool          ?create=1 — opens the create dialog on load
      $editingId         optional ?int          ?edit={id} — opens that row's edit dialog on load (tags, technologies)

    $taxonomy (set by the index view):
      resource, module, title, subtitle, icon, noun, nounPlural, addLabel, emptyTitle, emptyMessage,
      children   list of [count, singular, plural, route, filter]  — child counts and their filtered lists
      image      null | [column, upload, label, profile, relations]
      description, iconField, color, seo (bool — edit opens the full editor with <x-cms.seo-fields>),
      reassign   bool — delete of a term with children asks for a required target term
      publicRoute  null | route name taking the slug (site.blog.category / site.blog.tag)

    Writes:
      POST   admin.{resource}.store                 the taxonomy fields (create dialog)
      PUT    admin.{resource}.update  {term}        the same fields (edit dialog; tags and technologies)
      GET    admin.{resource}.edit    {term}        full editor with SEO (the three categories)
      DELETE admin.{resource}.destroy {term}        reassign_to (required when the term has children)
      POST   admin.{resource}.toggle  {term}        flips is_active                  (requested, see integration)
      POST   admin.{resource}.reorder               JSON {ids: [...]} in display order (service + portfolio categories)
--}}

@php
    use Illuminate\Support\Facades\Route;

    $t = array_merge([
        'resource' => 'service-categories',
        'module' => 'service_categories',
        'title' => 'Categories',
        'subtitle' => null,
        'icon' => 'folder',
        'noun' => 'category',
        'nounPlural' => 'categories',
        'addLabel' => 'Add category',
        'emptyTitle' => 'Nothing here yet',
        'emptyMessage' => null,
        'children' => [],
        'image' => null,
        'description' => false,
        'iconField' => false,
        'color' => false,
        'seo' => false,
        'reassign' => false,
        'publicRoute' => null,
    ], $taxonomy ?? []);
    $t['maxUploadMb'] = $maxUploadMb ?? null;

    $resource = $t['resource'];
    $module = $t['module'];
    $routeName = static fn (string $action): string => 'admin.'.$resource.'.'.$action;
    $has = static fn (string $action): bool => Route::has('admin.'.$resource.'.'.$action);

    $user = auth()->user();
    $canCreate = $has('store') && (bool) $user?->can($module.'.create');
    $canEdit = (bool) $user?->can($module.'.edit');
    $canDelete = $has('destroy') && (bool) $user?->can($module.'.delete');
    $canToggle = $has('toggle') && (bool) $user?->can($module.'.change_status');

    $filters = $filters ?? [];
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $counts = array_merge(['all' => null, 'active' => null, 'inactive' => null], $counts ?? []);
    $state = (string) ($filters['state'] ?? request('state', ''));
    $searching = filled($filters['search'] ?? request('search'));
    $filtered = $searching || $state !== '';

    $allOnOnePage = method_exists($terms, 'total') ? $terms->total() <= $terms->perPage() : true;
    $canReorder = $canEdit && $has('reorder') && $sort === 'sort_order' && ! $filtered && $allOnOnePage && $terms->count() > 1
        && (bool) ($canReorder ?? true);

    $tabs = [
        ['label' => 'All', 'url' => route($routeName('index')), 'active' => $state === '', 'count' => $counts['all'] !== null ? app_number((int) $counts['all']) : null],
        ['label' => 'Active', 'url' => route($routeName('index'), ['state' => 'active']), 'active' => $state === 'active', 'count' => $counts['active'] !== null ? app_number((int) $counts['active']) : null],
        ['label' => 'Inactive', 'url' => route($routeName('index'), ['state' => 'inactive']), 'active' => $state === 'inactive', 'count' => $counts['inactive'] !== null ? app_number((int) $counts['inactive']) : null],
    ];

    $reassignOptions = collect($reassignOptions ?? [])->map(static fn ($name) => (string) $name)->all();
    $columns = 8;
@endphp

@section('title', $t['title'])

@section('header')
    <x-ui.page-header :title="$t['title']" :subtitle="$t['subtitle']" :icon="$t['icon']">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'term-create')">{{ $t['addLabel'] }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @if ($t['image'])
        @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])
    @endif

    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search by name or slug…" :reset="route($routeName('index'))">
            @if ($state !== '')
                <input type="hidden" name="state" value="{{ $state }}">
            @endif
        </x-ui.filter-bar>

        @if ($canEdit && $has('reorder') && ! $canReorder && $terms->count() > 1)
            <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <x-ui.icon name="information-circle" class="h-4 w-4" />
                Drag to reorder is available when the list is sorted by display order, unfiltered, and fits on one page.
                @if ($sort !== 'sort_order')
                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'sort_order', 'direction' => 'asc', 'page' => null]) }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Sort by display order</a>
                @endif
            </p>
        @endif

        <div
            x-data="cmsSortable(@js(['url' => $has('reorder') ? route($routeName('reorder')) : null, 'key' => 'ids', 'noun' => $t['noun'], 'disabled' => ! $canReorder]))"
            x-init="list = $el.querySelector('tbody') || $el"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <x-ui.table loading="navigating" :is-empty="$terms->isEmpty()" :columns="$columns">
                <x-slot:head>
                    <th scope="col" class="w-16 px-4 py-3"><span class="sr-only">Order</span></th>
                    <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Name</x-ui.th-sortable>
                    <x-ui.th-sortable column="slug" :sort="$sort" :direction="$direction">Slug</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">In use</th>
                    <x-ui.th-sortable column="sort_order" :sort="$sort" :direction="$direction" align="right" :numeric="true">Order</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Active</th>
                    <x-ui.th-sortable column="updated_at" :sort="$sort" :direction="$direction" default="desc">Updated</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($terms as $term)
                    @php
                        $termAttributes = $term->getAttributes();
                        $childTotal = 0;
                        $childParts = [];
                        foreach ($t['children'] as $child) {
                            $n = (int) ($termAttributes[$child['count']] ?? 0);
                            $childTotal += $n;
                            $childParts[] = [
                                'n' => $n,
                                'label' => $n === 1 ? $child['singular'] : $child['plural'],
                                'url' => ! empty($child['route']) && Route::has($child['route']) && $n > 0
                                    ? route($child['route'], [$child['filter'] ?? 'category' => $term->getKey()])
                                    : null,
                            ];
                        }
                        $isActive = (bool) $term->getAttribute('is_active');
                        $publicUrl = $t['publicRoute'] && $isActive && Route::has($t['publicRoute']) ? route($t['publicRoute'], $term->slug) : null;
                        $color = (string) ($termAttributes['color'] ?? '');

                        $childSummary = collect($childParts)
                            ->filter(static fn (array $part): bool => $part['n'] > 0)
                            ->map(static fn (array $part): string => app_number($part['n']).' '.$part['label'])
                            ->implode(' and ');

                        $deleteMessage = match (true) {
                            $childTotal > 0 && $t['reassign'] => 'It still holds '.$childSummary.'. Choose where they move before it goes to the trash, so nothing is left without a '.$t['noun'].'.',
                            $childTotal > 0 => 'It is detached from '.$childSummary.'. Nothing else is changed.',
                            default => 'It moves to the trash and is hidden everywhere. Its slug stays reserved.',
                        };
                    @endphp
                    <tr
                        data-sortable-id="{{ $term->getKey() }}"
                        data-sortable-label="{{ $term->name }}"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                        @class(['opacity-70' => ! $isActive])
                    >
                        @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $term->name])

                        <td class="min-w-[14rem]">
                            <div class="flex items-center gap-3">
                                @if ($t['image'])
                                    @include('admin.marketing.partials.thumb', [
                                        'model' => $term,
                                        'relations' => $t['image']['relations'] ?? [],
                                        'column' => $t['image']['column'],
                                        'alt' => $term->name,
                                        'box' => 'h-9 w-9',
                                        'icon' => filled($termAttributes['icon'] ?? null) ? $termAttributes['icon'] : $t['icon'],
                                    ])
                                @elseif ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1)
                                    <span class="inline-block h-4 w-4 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $color }}" aria-hidden="true"></span>
                                @endif
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900 dark:text-white">{{ $term->name }}</p>
                                    @if (filled($termAttributes['description'] ?? null))
                                        <p class="line-clamp-1 max-w-md text-xs text-slate-500 dark:text-slate-400">{{ $termAttributes['description'] }}</p>
                                    @endif
                                </div>
                            </div>
                        </td>

                        <td class="whitespace-nowrap">
                            @if ($publicUrl)
                                <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="font-mono text-xs text-brand-600 hover:underline dark:text-brand-400">{{ $term->slug }}</a>
                            @else
                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $term->slug }}</span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap text-xs">
                            @forelse ($childParts as $part)
                                <span class="mr-2 inline-flex items-center gap-1">
                                    @if ($part['url'])
                                        <a href="{{ $part['url'] }}" class="font-semibold tabular-nums text-slate-700 hover:text-brand-700 dark:text-slate-200 dark:hover:text-brand-300">{{ app_number($part['n']) }} {{ $part['label'] }}</a>
                                    @else
                                        <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ app_number($part['n']) }} {{ $part['label'] }}</span>
                                    @endif
                                </span>
                            @empty
                                <span class="text-slate-400">—</span>
                            @endforelse
                        </td>

                        <td class="text-right tabular-nums text-slate-500 dark:text-slate-400">{{ app_number((int) ($termAttributes['sort_order'] ?? 0)) }}</td>

                        <td>
                            @if ($canToggle)
                                <form method="POST" action="{{ route($routeName('toggle'), $term) }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        role="switch"
                                        aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                        aria-label="{{ $isActive ? 'Deactivate' : 'Activate' }} {{ $term->name }}"
                                        title="{{ $isActive ? 'Active — click to hide from the website' : 'Inactive — click to show on the website' }}"
                                        @class([
                                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40',
                                            'bg-brand-600 dark:bg-brand-500' => $isActive,
                                            'bg-slate-200 dark:bg-slate-700' => ! $isActive,
                                        ])
                                    >
                                        <span @class([
                                            'inline-block h-[1.125rem] w-[1.125rem] rounded-full bg-white shadow-sm transition-transform dark:bg-white',
                                            'translate-x-[1.4rem]' => $isActive,
                                            'translate-x-[3px]' => ! $isActive,
                                        ])></span>
                                    </button>
                                </form>
                            @else
                                <x-ui.badge :color="$isActive ? 'emerald' : 'slate'" size="sm" :dot="true">{{ $isActive ? 'Active' : 'Inactive' }}</x-ui.badge>
                            @endif
                        </td>

                        <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($term->updated_at) }}</td>

                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($canEdit)
                                    @if ($t['seo'] && $has('edit'))
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $term->name }} (details and SEO)" :href="route($routeName('edit'), $term)" />
                                    @elseif ($has('update'))
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $term->name }}" x-on:click="$dispatch('open-modal', 'term-edit-{{ $term->getKey() }}')" />
                                    @endif
                                @endif

                                @if ($canDelete)
                                    <x-ui.confirm
                                        :action="route($routeName('destroy'), $term)"
                                        id="term-delete-{{ $term->getKey() }}"
                                        :title="'Delete '.$term->name.'?'"
                                        :message="$deleteMessage"
                                        :confirm-label="'Delete '.$t['noun']"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $term->name }}" />
                                        </x-slot:trigger>

                                        @if ($childTotal > 0 && $t['reassign'])
                                            <div class="mt-4">
                                                <label for="reassign-{{ $term->getKey() }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                    Move them to <span class="text-rose-500">*</span>
                                                </label>
                                                <select
                                                    id="reassign-{{ $term->getKey() }}"
                                                    name="reassign_to"
                                                    form="term-delete-{{ $term->getKey() }}"
                                                    required
                                                    class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                                                >
                                                    <option value="">Choose a {{ $t['noun'] }}…</option>
                                                    @foreach ($reassignOptions as $optionId => $optionName)
                                                        @continue((int) $optionId === (int) $term->getKey())
                                                        <option value="{{ $optionId }}">{{ $optionName }}</option>
                                                    @endforeach
                                                </select>
                                                @if (count($reassignOptions) <= 1)
                                                    <p class="mt-1.5 text-xs text-amber-700 dark:text-amber-400">There is no other {{ $t['noun'] }} to move them to. Create one first.</p>
                                                @endif
                                            </div>
                                        @endif
                                    </x-ui.confirm>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    @if ($filtered)
                        <x-ui.empty-state :icon="$t['icon']" :title="'No '.$t['nounPlural'].' match those filters'">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route($routeName('index'))">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state :icon="$t['icon']" :title="$t['emptyTitle']" :message="$t['emptyMessage']">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'term-create')">{{ $t['addLabel'] }}</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    @if (method_exists($terms, 'links'))
                        <x-ui.pagination-summary :paginator="$terms" :label="$t['nounPlural']" />
                    @endif
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>

    {{-- Create dialog --}}
    @if ($canCreate)
        @php $reopenCreate = ($errors->any() && old('_term_form') === 'create') || (! $errors->any() && (bool) ($creating ?? false)); @endphp
        <x-ui.modal name="term-create" :title="$t['addLabel']" :icon="$t['icon']" size="lg" :show="$reopenCreate">
            <form id="term-create-form" method="POST" action="{{ route($routeName('store')) }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_term_form" value="create">
                @include('admin.marketing.partials.taxonomy-fields', ['taxonomy' => $t, 'term' => null, 'idSuffix' => 'new', 'errorsFor' => $reopenCreate || ! $errors->any()])
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'term-create')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="term-create-form" icon="check">Save {{ $t['noun'] }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Edit dialogs (tags and technologies; the three categories edit on their own page because of SEO) --}}
    @if ($canEdit && ! $t['seo'] && $has('update'))
        @foreach ($terms as $term)
            @php $reopenEdit = ($errors->any() && (string) old('_term_form') === 'edit-'.$term->getKey()) || (! $errors->any() && (int) ($editingId ?? 0) === (int) $term->getKey()); @endphp
            <x-ui.modal :name="'term-edit-'.$term->getKey()" :title="'Edit '.$term->name" :icon="$t['icon']" size="lg" :show="$reopenEdit">
                <form id="term-edit-form-{{ $term->getKey() }}" method="POST" action="{{ route($routeName('update'), $term) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_term_form" value="edit-{{ $term->getKey() }}">
                    @include('admin.marketing.partials.taxonomy-fields', ['taxonomy' => $t, 'term' => $term, 'idSuffix' => 'edit-'.$term->getKey(), 'errorsFor' => $reopenEdit])
                </form>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'term-edit-{{ $term->getKey() }}')">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="term-edit-form-{{ $term->getKey() }}" icon="check">Save changes</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
