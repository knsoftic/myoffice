@extends('layouts.admin')

@section('title', 'Services')

{{--
    Services — admin.services.index (phase-04 §8.2, §2.3, §7.2).

    Controller variables (Admin\ServiceController@index):
      $services           LengthAwarePaginator<App\Models\Cms\Service> with category, technologies and the image
                          relation (image); only trashed rows when $trashed
      $filters            array<string, mixed>   validated query
      $sort               string   sort_order | name | starting_price | status | updated_at   (default sort_order)
      $direction          string   asc | desc
      $trashed            bool     ?trashed=1
      $categoryOptions    array<int, string>
      $technologyOptions  array<int, string>
      $statusOptions      array<string, string>  ContentStatus::options()
      $counts             array{all: int, published: int, draft: int, featured: int, trashed: int}
    Query string: search (name, slug, short description), category, status, featured (1|0), technology,
    trashed (1), sort, direction, page.

    Writes: POST admin.services.reorder JSON {ids}; POST admin.services.featured {service};
    POST admin.services.status {service} status=published|draft|archived; DELETE admin.services.destroy {service};
    GET admin.services.export (current query). Restore only when admin.services.restore exists.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canCreate = (bool) $user?->can('services.create');
    $canEdit = (bool) $user?->can('services.edit');
    $canDelete = (bool) $user?->can('services.delete');
    $canStatus = (bool) $user?->can('services.change_status');
    $canExport = (bool) $user?->can('services.export') && Route::has('admin.services.export');
    $canRestore = (bool) $user?->can('services.restore') && Route::has('admin.services.restore');

    $trashed = (bool) ($trashed ?? false);
    $filters = $filters ?? [];
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $counts = $counts ?? [];
    $filtered = collect(request()->only(['search', 'category', 'status', 'featured', 'technology']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $allOnOnePage = $services->total() <= $services->perPage();
    $canReorder = $canEdit && ! $trashed && ! $filtered && $sort === 'sort_order' && $allOnOnePage && $services->count() > 1 && Route::has('admin.services.reorder');
    $hasPublic = Route::has('site.services.show');

    $count = static fn (string $key): ?string => isset($counts[$key]) ? app_number((int) $counts[$key]) : null;
    $tabs = [
        ['label' => 'All', 'url' => route('admin.services.index'), 'active' => ! $trashed && ! request()->filled('status') && ! request()->filled('featured'), 'count' => $count('all')],
        ['label' => 'Published', 'url' => route('admin.services.index', ['status' => ContentStatus::Published->value]), 'active' => request('status') === ContentStatus::Published->value, 'count' => $count('published')],
        ['label' => 'Drafts', 'url' => route('admin.services.index', ['status' => ContentStatus::Draft->value]), 'active' => request('status') === ContentStatus::Draft->value, 'count' => $count('draft')],
        ['label' => 'Featured', 'url' => route('admin.services.index', ['featured' => 1]), 'active' => request('featured') === '1', 'count' => $count('featured'), 'icon' => 'star'],
        ['label' => 'Trashed', 'url' => route('admin.services.index', ['trashed' => 1]), 'active' => $trashed, 'count' => $count('trashed'), 'icon' => 'trash'],
    ];
    // The trashed view is read-only and needs services.restore (the controller ignores ?trashed=1 otherwise).
    $tabs = array_values(array_filter($tabs, static fn (array $item): bool => $item['label'] !== 'Trashed' || (bool) $user?->can('services.restore')));
@endphp

@section('header')
    <x-ui.page-header title="Services" subtitle="The public service catalogue: prices, technologies, features and SEO." icon="wrench-screwdriver">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.services.export', request()->query())">Export</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.services.create')">New service</x-ui.button>
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
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search name, slug or description…" :reset="route('admin.services.index', $trashed ? ['trashed' => 1] : [])">
            @if ($trashed)
                <input type="hidden" name="trashed" value="1">
            @endif
            <x-ui.form.select name="category" :options="$categoryOptions ?? []" :selected="request('category')" placeholder="Any category" size="sm" aria-label="Filter by category" />
            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
            <x-ui.form.select name="technology" :options="$technologyOptions ?? []" :selected="request('technology')" placeholder="Any technology" size="sm" aria-label="Filter by technology" />
        </x-ui.filter-bar>

        @if ($canEdit && ! $trashed && ! $canReorder && $services->count() > 1)
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                <x-ui.icon name="information-circle" class="h-4 w-4" />
                Drag to reorder works on the unfiltered list sorted by display order, when every service fits on one page.
                @if ($sort !== 'sort_order')
                    <a href="{{ request()->fullUrlWithQuery(['sort' => 'sort_order', 'direction' => 'asc', 'page' => null]) }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Sort by display order</a>
                @endif
            </p>
        @endif

        <div
            x-data="cmsSortable(@js(['url' => Route::has('admin.services.reorder') ? route('admin.services.reorder') : null, 'key' => 'ids', 'noun' => 'service', 'disabled' => ! $canReorder]))"
            x-init="list = $el.querySelector('tbody') || $el"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <x-ui.table loading="navigating" :is-empty="$services->isEmpty()" :columns="10">
                <x-slot:head>
                    <th scope="col" class="w-16 px-4 py-3"><span class="sr-only">Order</span></th>
                    <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Service</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Category</th>
                    <x-ui.th-sortable column="starting_price" :sort="$sort" :direction="$direction" align="right" :numeric="true">Starting price</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Technologies</th>
                    <th scope="col" class="px-4 py-3 text-center">Featured</th>
                    <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                    <x-ui.th-sortable column="sort_order" :sort="$sort" :direction="$direction" align="right" :numeric="true">Order</x-ui.th-sortable>
                    <x-ui.th-sortable column="updated_at" :sort="$sort" :direction="$direction" default="desc">Updated</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($services as $service)
                    @php
                        $statusValue = $service->status instanceof \BackedEnum ? $service->status->value : (string) $service->status;
                        $isPublished = $statusValue === ContentStatus::Published->value;
                        $technologies = $service->relationLoaded('technologies') ? $service->technologies : collect();
                        $category = $service->relationLoaded('category') ? $service->category : null;
                    @endphp
                    <tr
                        data-sortable-id="{{ $service->getKey() }}"
                        data-sortable-label="{{ $service->name }}"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                    >
                        @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $service->name])

                        <td class="min-w-[16rem]">
                            <div class="flex items-center gap-3">
                                @include('admin.marketing.partials.thumb', ['model' => $service, 'relations' => ['imageAsset', 'image', 'imageMedia'], 'column' => 'image_media_id', 'alt' => $service->name, 'icon' => filled($service->icon) ? $service->icon : 'wrench-screwdriver'])
                                <div class="min-w-0">
                                    @if ($canEdit && ! $trashed)
                                        <a href="{{ route('admin.services.edit', $service) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $service->name }}</a>
                                    @else
                                        <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $service->name }}</span>
                                    @endif
                                    <span class="block truncate font-mono text-xs text-slate-500 dark:text-slate-400">/services/{{ $service->slug }}</span>
                                </div>
                            </div>
                        </td>

                        <td class="whitespace-nowrap text-sm">{{ $category?->name ?? '—' }}</td>

                        <td class="whitespace-nowrap text-right tabular-nums">
                            <span class="inline-flex items-center justify-end gap-1.5">
                                @if (! $service->price_visible)
                                    <x-ui.icon name="lock-closed" class="h-3.5 w-3.5 text-slate-400" label="Hidden on the website" />
                                @endif
                                @if ($service->starting_price !== null)
                                    <span class="font-medium text-slate-900 dark:text-white">{{ money((string) $service->starting_price) }}</span>
                                @else
                                    <span class="text-xs text-slate-500 dark:text-slate-400">On request</span>
                                @endif
                            </span>
                            @if (filled($service->price_note))
                                <span class="block text-2xs text-slate-400 dark:text-slate-500">{{ $service->price_note }}</span>
                            @endif
                        </td>

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

                        <td class="text-center">
                            @if ($canStatus && ! $trashed && Route::has('admin.services.featured'))
                                <form method="POST" action="{{ route('admin.services.featured', $service) }}" class="inline">
                                    @csrf
                                    <x-ui.icon-button type="submit" icon="star" size="sm" :label="$service->is_featured ? 'Unfeature '.$service->name : 'Feature '.$service->name" @class(['text-amber-500 dark:text-amber-300' => $service->is_featured]) />
                                </form>
                            @elseif ($service->is_featured)
                                <x-ui.icon name="star" class="mx-auto h-4 w-4 text-amber-500 dark:text-amber-300" label="Featured" />
                            @endif
                        </td>

                        <td>@include('admin.marketing.partials.enum-badge', ['value' => $service->status])</td>

                        <td class="text-right tabular-nums text-slate-500 dark:text-slate-400">{{ app_number((int) $service->sort_order) }}</td>

                        <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($trashed ? $service->deleted_at : $service->updated_at) }}</td>

                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($trashed)
                                    @if ($canRestore)
                                        <form method="POST" action="{{ route('admin.services.restore', $service) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    @if ($canEdit)
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $service->name }}" :href="route('admin.services.edit', $service)" />
                                    @endif

                                    @if ($isPublished && $hasPublic)
                                        <x-ui.icon-button icon="arrow-top-right-on-square" size="sm" label="View {{ $service->name }} on the website" :href="route('site.services.show', $service->slug)" target="_blank" rel="noopener" />
                                    @endif

                                    @if ($canStatus && Route::has('admin.services.status'))
                                        @if (! $isPublished)
                                            <form method="POST" action="{{ route('admin.services.status', $service) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ ContentStatus::Published->value }}">
                                                <x-ui.icon-button type="submit" icon="check-circle" size="sm" label="Publish {{ $service->name }}" class="text-emerald-600 dark:text-emerald-400" />
                                            </form>
                                        @else
                                            <x-ui.confirm
                                                :action="route('admin.services.status', $service)"
                                                method="POST"
                                                :title="'Unpublish '.$service->name.'?'"
                                                message="The service page returns 404 and the service leaves the catalogue. Nothing is deleted; publish it again at any time."
                                                confirm-label="Move to draft"
                                                variant="warning"
                                                icon="eye-slash"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.icon-button icon="eye-slash" size="sm" label="Unpublish {{ $service->name }}" />
                                                </x-slot:trigger>
                                                <x-slot:fields>
                                                    <input type="hidden" name="status" value="{{ ContentStatus::Draft->value }}">
                                                </x-slot:fields>
                                            </x-ui.confirm>
                                        @endif
                                    @endif

                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.services.destroy', $service)"
                                            :title="'Delete '.$service->name.'?'"
                                            message="The service moves to the trash and disappears from the website. Inquiries that named it keep their record. Its address stays reserved."
                                            confirm-label="Delete service"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $service->name }}" />
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
                        <x-ui.empty-state icon="wrench-screwdriver" title="No services match those filters">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.services.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="wrench-screwdriver" title="Your service catalogue is empty" message="The website Services section will be hidden until you add one.">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" :href="route('admin.services.create')">Add service</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$services" label="services" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
@endsection
