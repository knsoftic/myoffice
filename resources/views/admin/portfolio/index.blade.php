@extends('layouts.admin')

@section('title', 'Portfolio')

{{--
    Portfolio — admin.portfolio.index (phase-04 §8.3, §2.7, §7.2).

    Controller variables (Admin\PortfolioItemController@index):
      $items              LengthAwarePaginator<App\Models\Cms\PortfolioItem> with category, technologies, the cover
                          relation (cover) and withCount of the gallery (images_count); only trashed rows when $trashed
      $filters            array<string, mixed>
      $sort               string   sort_order | title | client_name | completion_date | status | updated_at
      $direction          string   asc | desc
      $trashed            bool
      $categoryOptions    array<int, string>
      $technologyOptions  array<int, string>
      $statusOptions      array<string, string>
      $yearOptions        array<int, int>        completion years present in the table, newest first
      $counts             array{all: int, published: int, draft: int, featured: int, trashed: int}
    Query: search (title, client name), category, status, featured, technology, year, trashed, sort, direction, page.

    Writes: POST admin.portfolio.reorder JSON {ids}; POST admin.portfolio.featured {item};
    POST admin.portfolio.status {item} status; DELETE admin.portfolio.destroy {item}; GET admin.portfolio.export.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canCreate = (bool) $user?->can('portfolio.create');
    $canEdit = (bool) $user?->can('portfolio.edit');
    $canDelete = (bool) $user?->can('portfolio.delete');
    $canStatus = (bool) $user?->can('portfolio.change_status');
    $canExport = (bool) $user?->can('portfolio.export') && Route::has('admin.portfolio.export');
    $canRestore = (bool) $user?->can('portfolio.restore') && Route::has('admin.portfolio.restore');

    $trashed = (bool) ($trashed ?? false);
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $counts = $counts ?? [];
    $filtered = collect(request()->only(['search', 'category', 'status', 'featured', 'technology', 'year']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $canReorder = $canEdit && ! $trashed && ! $filtered && $sort === 'sort_order' && $items->total() <= $items->perPage() && $items->count() > 1 && Route::has('admin.portfolio.reorder');
    $hasPublic = Route::has('site.portfolio.show');

    $count = static fn (string $key): ?string => isset($counts[$key]) ? app_number((int) $counts[$key]) : null;
    $tabs = [
        ['label' => 'All', 'url' => route('admin.portfolio.index'), 'active' => ! $trashed && ! request()->filled('status') && ! request()->filled('featured'), 'count' => $count('all')],
        ['label' => 'Published', 'url' => route('admin.portfolio.index', ['status' => ContentStatus::Published->value]), 'active' => request('status') === ContentStatus::Published->value, 'count' => $count('published')],
        ['label' => 'Drafts', 'url' => route('admin.portfolio.index', ['status' => ContentStatus::Draft->value]), 'active' => request('status') === ContentStatus::Draft->value, 'count' => $count('draft')],
        ['label' => 'Featured', 'url' => route('admin.portfolio.index', ['featured' => 1]), 'active' => request('featured') === '1', 'count' => $count('featured'), 'icon' => 'star'],
        ['label' => 'Trashed', 'url' => route('admin.portfolio.index', ['trashed' => 1]), 'active' => $trashed, 'count' => $count('trashed'), 'icon' => 'trash'],
    ];
    $tabs = array_values(array_filter($tabs, static fn (array $tabItem): bool => $tabItem['label'] !== 'Trashed' || (bool) $user?->can('portfolio.restore')));
    $years = collect($yearOptions ?? [])->mapWithKeys(fn ($year) => [(string) $year => (string) $year])->all();
@endphp

@section('header')
    <x-ui.page-header title="Portfolio" subtitle="Case studies with galleries, technologies and client names." icon="photo">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.portfolio.export', request()->query())">Export</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.portfolio.create')">New project</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search project or client…" :reset="route('admin.portfolio.index', $trashed ? ['trashed' => 1] : [])">
            @if ($trashed)
                <input type="hidden" name="trashed" value="1">
            @endif
            <x-ui.form.select name="category" :options="$categoryOptions ?? []" :selected="request('category')" placeholder="Any category" size="sm" aria-label="Filter by category" />
            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
            <x-ui.form.select name="technology" :options="$technologyOptions ?? []" :selected="request('technology')" placeholder="Any technology" size="sm" aria-label="Filter by technology" />
            <x-ui.form.select name="year" :options="$years" :selected="request('year')" placeholder="Any year" size="sm" aria-label="Filter by completion year" />
        </x-ui.filter-bar>

        @if ($canEdit && ! $trashed && ! $canReorder && $items->count() > 1)
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                <x-ui.icon name="information-circle" class="h-4 w-4" />
                Drag to reorder works on the unfiltered list sorted by display order, when every project fits on one page.
            </p>
        @endif

        <div
            x-data="cmsSortable(@js(['url' => Route::has('admin.portfolio.reorder') ? route('admin.portfolio.reorder') : null, 'key' => 'ids', 'noun' => 'project', 'disabled' => ! $canReorder]))"
            x-init="list = $el.querySelector('tbody') || $el"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <x-ui.table loading="navigating" :is-empty="$items->isEmpty()" :columns="10">
                <x-slot:head>
                    <th scope="col" class="w-16 px-4 py-3"><span class="sr-only">Order</span></th>
                    <x-ui.th-sortable column="title" :sort="$sort" :direction="$direction">Project</x-ui.th-sortable>
                    <x-ui.th-sortable column="client_name" :sort="$sort" :direction="$direction">Client</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Category</th>
                    <th scope="col" class="px-4 py-3">Technologies</th>
                    <x-ui.th-sortable column="completion_date" :sort="$sort" :direction="$direction" default="desc">Completed</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right">Images</th>
                    <th scope="col" class="px-4 py-3 text-center">Featured</th>
                    <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($items as $item)
                    @php
                        $statusValue = $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status;
                        $isPublished = $statusValue === ContentStatus::Published->value;
                        $technologies = $item->relationLoaded('technologies') ? $item->technologies : collect();
                        $itemAttributes = $item->getAttributes();
                        $imagesCount = (int) ($itemAttributes['images_count'] ?? $itemAttributes['media_count'] ?? $itemAttributes['gallery_count'] ?? 0);
                    @endphp
                    <tr
                        data-sortable-id="{{ $item->getKey() }}"
                        data-sortable-label="{{ $item->title }}"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                    >
                        @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $item->title])

                        <td class="min-w-[16rem]">
                            <div class="flex items-center gap-3">
                                @include('admin.marketing.partials.thumb', ['model' => $item, 'relations' => ['coverAsset', 'cover', 'coverMedia'], 'column' => 'cover_media_id', 'alt' => $item->title, 'box' => 'h-10 w-16'])
                                <div class="min-w-0">
                                    @if ($canEdit && ! $trashed)
                                        <a href="{{ route('admin.portfolio.edit', $item) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $item->title }}</a>
                                    @else
                                        <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $item->title }}</span>
                                    @endif
                                    <span class="block truncate font-mono text-xs text-slate-500 dark:text-slate-400">/portfolio/{{ $item->slug }}</span>
                                </div>
                            </div>
                        </td>

                        <td class="whitespace-nowrap text-sm">{{ $item->client_name ?: '—' }}</td>

                        <td class="whitespace-nowrap text-sm">{{ $item->relationLoaded('category') ? ($item->category?->name ?? '—') : '—' }}</td>

                        <td>
                            <div class="flex max-w-[14rem] flex-wrap gap-1">
                                @foreach ($technologies->take(3) as $technology)
                                    <x-ui.badge color="slate" size="xs" :pill="false">{{ $technology->name }}</x-ui.badge>
                                @endforeach
                                @if ($technologies->count() > 3)
                                    <x-ui.badge color="slate" variant="outline" size="xs" :pill="false" title="{{ $technologies->slice(3)->pluck('name')->implode(', ') }}">+{{ $technologies->count() - 3 }}</x-ui.badge>
                                @endif
                                @if ($technologies->isEmpty())
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </div>
                        </td>

                        <td class="whitespace-nowrap text-sm">{{ $item->completion_date ? app_date($item->completion_date) : '—' }}</td>

                        <td class="text-right tabular-nums">
                            <span @class(['font-medium', 'text-amber-600 dark:text-amber-400' => $imagesCount === 0, 'text-slate-700 dark:text-slate-200' => $imagesCount > 0])>{{ app_number($imagesCount) }}</span>
                        </td>

                        <td class="text-center">
                            @if ($canStatus && ! $trashed && Route::has('admin.portfolio.featured'))
                                <form method="POST" action="{{ route('admin.portfolio.featured', $item) }}" class="inline">
                                    @csrf
                                    <x-ui.icon-button type="submit" icon="star" size="sm" :label="$item->is_featured ? 'Unfeature '.$item->title : 'Feature '.$item->title" @class(['text-amber-500 dark:text-amber-300' => $item->is_featured]) />
                                </form>
                            @elseif ($item->is_featured)
                                <x-ui.icon name="star" class="mx-auto h-4 w-4 text-amber-500 dark:text-amber-300" label="Featured" />
                            @endif
                        </td>

                        <td>@include('admin.marketing.partials.enum-badge', ['value' => $item->status])</td>

                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($trashed)
                                    @if ($canRestore)
                                        <form method="POST" action="{{ route('admin.portfolio.restore', $item) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    @if ($canEdit)
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $item->title }}" :href="route('admin.portfolio.edit', $item)" />
                                    @endif
                                    @if ($isPublished && $hasPublic)
                                        <x-ui.icon-button icon="arrow-top-right-on-square" size="sm" label="View {{ $item->title }} on the website" :href="route('site.portfolio.show', $item->slug)" target="_blank" rel="noopener" />
                                    @endif
                                    @if ($canStatus && Route::has('admin.portfolio.status'))
                                        @if (! $isPublished)
                                            <form method="POST" action="{{ route('admin.portfolio.status', $item) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ ContentStatus::Published->value }}">
                                                <x-ui.icon-button type="submit" icon="check-circle" size="sm" label="Publish {{ $item->title }}" class="text-emerald-600 dark:text-emerald-400" />
                                            </form>
                                        @else
                                            <x-ui.confirm
                                                :action="route('admin.portfolio.status', $item)"
                                                method="POST"
                                                :title="'Unpublish '.$item->title.'?'"
                                                message="The case study returns 404 and leaves the portfolio. Nothing is deleted."
                                                confirm-label="Move to draft"
                                                variant="warning"
                                                icon="eye-slash"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.icon-button icon="eye-slash" size="sm" label="Unpublish {{ $item->title }}" />
                                                </x-slot:trigger>
                                                <x-slot:fields>
                                                    <input type="hidden" name="status" value="{{ ContentStatus::Draft->value }}">
                                                </x-slot:fields>
                                            </x-ui.confirm>
                                        @endif
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.portfolio.destroy', $item)"
                                            :title="'Delete '.$item->title.'?'"
                                            message="The project moves to the trash. Its images and their files stay in the media library."
                                            confirm-label="Delete project"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $item->title }}" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    @if ($trashed)
                        <x-ui.empty-state icon="trash" title="The trash is empty" />
                    @elseif ($filtered)
                        <x-ui.empty-state icon="photo" title="No projects match those filters">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.portfolio.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="photo" title="No projects published yet" message="Add a case study with a gallery to show the work you are proud of.">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" :href="route('admin.portfolio.create')">Add project</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$items" label="projects" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
@endsection
