@extends('layouts.admin')

@section('title', 'Pages')

{{--
    Pages — admin.website.pages.index (phase-03 §7.3, §8.10; requirement §101).

    Controller variables (Admin\Cms\PageController@index):
      $pages          LengthAwarePaginator<App\Models\Cms\Page>  with `seo`, withCount('menuItems') (menu_items_count);
                                                                 only trashed rows when $trashed
      $completeness   array<int, ?int>                           page id => SEO completeness 0-100 (null = no seo_meta row)
      $trashed        bool                                       ?trashed=1, needs pages.restore
      $sort           string   title | slug | status | updated_at | sort_order
      $direction      string   asc | desc
      $filters        array<string, mixed>
      $statusOptions  array<string, string>
      $layoutOptions  array<string, string>
      $counts         array<string, int>                         status value => live page count
      $can            array{create, edit, publish, delete, restore, export, revisions: bool}
    Query string (CmsListRequest): search (title, slug, body), status, layout, system (system|custom),
    unpublished (1), missing_seo (1), trashed (1), sort, direction, page.

    Writes: POST publish {page}; POST duplicate {page}; DELETE destroy {page} (never offered for a system page —
    PagePolicy::delete refuses it too); POST restore {page}. Export: GET pages.export with the current query.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Enums\Cms\PageLayout;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $can = array_merge(['create' => false, 'edit' => false, 'publish' => false, 'delete' => false, 'restore' => false, 'export' => false, 'revisions' => false], $can ?? []);
    $trashed = (bool) ($trashed ?? false);
    $counts = $counts ?? [];
    $completeness = $completeness ?? [];
    $filters = $filters ?? [];
    $filtered = collect($filters)->except(['trashed'])->isNotEmpty();
    $hasPreview = RouteFacade::has('site.preview.page');
    $hasPublicPage = RouteFacade::has('site.page');
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $totalLive = array_sum(array_map('intval', $counts));

    $tabs = [
        ['label' => 'Pages', 'url' => route('admin.website.pages.index'), 'active' => ! $trashed, 'count' => $totalLive],
    ];
    if ($can['restore']) {
        $tabs[] = ['label' => 'Trash', 'url' => route('admin.website.pages.index', ['trashed' => 1]), 'active' => $trashed, 'icon' => 'trash'];
    }
@endphp

@section('header')
    <x-ui.page-header title="Pages" subtitle="Privacy policy, terms, refund and course policies, and any page with its own address." icon="document">
        <x-slot:actions>
            @if ($can['export'])
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.website.pages.export', request()->query())">Export</x-ui.button>
            @endif
            @if ($can['create'])
                <x-ui.button icon="plus" :href="route('admin.website.pages.create')">New page</x-ui.button>
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
        @unless ($trashed)
            <div class="card-grid">
                <x-ui.stat-card label="Published" :value="app_number((int) ($counts[ContentStatus::Published->value] ?? 0))" icon="check-circle" color="emerald" :href="route('admin.website.pages.index', ['status' => ContentStatus::Published->value])" />
                <x-ui.stat-card label="Drafts" :value="app_number((int) ($counts[ContentStatus::Draft->value] ?? 0))" icon="pencil" color="slate" :href="route('admin.website.pages.index', ['status' => ContentStatus::Draft->value])" />
                <x-ui.stat-card label="Scheduled" :value="app_number((int) ($counts[ContentStatus::Scheduled->value] ?? 0))" icon="calendar-days" color="amber" :href="route('admin.website.pages.index', ['status' => ContentStatus::Scheduled->value])" />
                <x-ui.stat-card label="All pages" :value="app_number($totalLive)" icon="document" color="brand" />
            </div>
        @endunless

        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search title, address or text…" :reset="route('admin.website.pages.index', $trashed ? ['trashed' => 1] : [])">
            @if ($trashed)
                <input type="hidden" name="trashed" value="1">
            @endif
            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="layout" :options="$layoutOptions ?? PageLayout::options()" :selected="request('layout')" placeholder="Any layout" size="sm" aria-label="Filter by layout" />
            <x-ui.form.select name="system" :options="['system' => 'System pages', 'custom' => 'Custom pages']" :selected="request('system')" placeholder="System or custom" size="sm" aria-label="Filter by kind" />
            <x-ui.form.select name="unpublished" :options="['1' => 'Has unpublished changes']" :selected="request('unpublished')" placeholder="Any draft state" size="sm" aria-label="Filter by unpublished changes" />
            <x-ui.form.select name="missing_seo" :options="['1' => 'Missing SEO']" :selected="request('missing_seo')" placeholder="Any SEO state" size="sm" aria-label="Filter by SEO" />
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$pages->isEmpty()" :columns="7">
            <x-slot:head>
                <x-ui.th-sortable column="title" :sort="$sort" :direction="$direction">Title</x-ui.th-sortable>
                <x-ui.th-sortable column="slug" :sort="$sort" :direction="$direction">Address</x-ui.th-sortable>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">SEO</th>
                <th scope="col" class="px-4 py-3 text-right">In menus</th>
                <x-ui.th-sortable column="updated_at" :sort="$sort" :direction="$direction" default="desc">Updated</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($pages as $page)
                @php
                    $layout = $page->layout instanceof PageLayout ? $page->layout : PageLayout::tryFrom((string) $page->layout);
                    $score = $completeness[(int) $page->id] ?? null;
                    $isPublished = $page->status === ContentStatus::Published;
                    $publicUrl = $hasPublicPage ? route('site.page', ['slug' => $page->slug]) : url('/'.$page->slug);
                @endphp
                <tr>
                    <td class="min-w-[14rem]">
                        <div class="flex items-center gap-2">
                            @if ($page->is_system)
                                <x-ui.icon name="lock-closed" class="h-4 w-4 shrink-0 text-slate-400" label="System page: editable, never deletable" />
                            @endif
                            @if ($trashed)
                                <span class="font-medium text-slate-900 dark:text-white">{{ $page->title }}</span>
                            @else
                                <a href="{{ route('admin.website.pages.edit', $page) }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $page->title }}</a>
                            @endif
                        </div>
                        @if ($layout)
                            <x-ui.badge :color="$layout->color()" variant="outline" size="sm" class="mt-1">{{ $layout->label() }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        <div class="flex items-center gap-1" x-data="cmsCopy(@js(['value' => $publicUrl]))">
                            @if ($isPublished && ! $trashed)
                                <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="font-mono text-xs text-brand-600 hover:underline dark:text-brand-400">/{{ $page->slug }}</a>
                            @else
                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">/{{ $page->slug }}</span>
                            @endif
                            <x-ui.icon-button icon="clipboard" size="xs" label="Copy the address of {{ $page->title }}" x-on:click="copy()" />
                        </div>
                    </td>
                    <td>
                        @include('admin.cms.partials.status-badge', [
                            'status' => $page->status,
                            'unpublished' => $page->has_unpublished_changes,
                            'published' => filled($page->published_hash),
                        ])
                        @if ($page->status === ContentStatus::Scheduled && $page->published_at)
                            <p class="mt-1 text-2xs text-slate-500 dark:text-slate-400">Goes live {{ app_datetime($page->published_at) }}</p>
                        @endif
                    </td>
                    <td class="w-32">
                        @if ($score !== null)
                            <div class="flex items-center gap-2" title="SEO completeness {{ $score }}%">
                                <div class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800" aria-hidden="true">
                                    <div @class([
                                        'h-full rounded-full',
                                        'bg-emerald-500' => $score >= 80,
                                        'bg-amber-500' => $score >= 50 && $score < 80,
                                        'bg-rose-500' => $score < 50,
                                    ]) style="width: {{ max(0, min(100, (int) $score)) }}%"></div>
                                </div>
                                <span class="text-xs tabular-nums text-slate-600 dark:text-slate-300">{{ $score }}%</span>
                            </div>
                        @else
                            <x-ui.badge color="amber" variant="outline" size="sm">Not set</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ app_number((int) ($page->menu_items_count ?? 0)) }}</td>
                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        {{ app_datetime($trashed ? $page->deleted_at : $page->updated_at) }}
                    </td>
                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($trashed)
                                @if ($can['restore'])
                                    <form method="POST" action="{{ route('admin.website.pages.restore', $page) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                    </form>
                                @endif
                            @else
                                <x-ui.icon-button :icon="$can['edit'] ? 'pencil' : 'eye'" size="sm" label="Open {{ $page->title }}" :href="route('admin.website.pages.edit', $page)" />

                                @if ($hasPreview)
                                    <x-ui.icon-button icon="eye" size="sm" label="Preview the draft of {{ $page->title }}" :href="route('site.preview.page', $page)" target="_blank" rel="noopener" />
                                @endif

                                @if ($can['revisions'])
                                    <x-ui.icon-button icon="clock" size="sm" label="Revisions of {{ $page->title }}" :href="route('admin.website.pages.revisions.index', $page)" />
                                @endif

                                @if ($can['create'])
                                    <form method="POST" action="{{ route('admin.website.pages.duplicate', $page) }}">
                                        @csrf
                                        <x-ui.icon-button type="submit" icon="clipboard-document" size="sm" label="Duplicate {{ $page->title }}" />
                                    </form>
                                @endif

                                @if ($can['publish'] && ($page->has_unpublished_changes || ! $isPublished))
                                    <x-ui.confirm
                                        :action="route('admin.website.pages.publish', $page)"
                                        method="POST"
                                        :title="'Publish '.$page->title.'?'"
                                        :message="'The current draft goes live at /'.$page->slug.' for every visitor.'"
                                        confirm-label="Publish"
                                        variant="warning"
                                        icon="check-circle"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="check-circle" size="sm" label="Publish {{ $page->title }}" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif

                                @if ($can['delete'])
                                    @if ($page->is_system)
                                        <x-ui.icon-button icon="trash" size="sm" label="System pages can be edited but never deleted" :disabled="true" />
                                    @else
                                        <x-ui.confirm
                                            :action="route('admin.website.pages.destroy', $page)"
                                            :title="'Delete '.$page->title.'?'"
                                            message="The page moves to the trash and returns 404 to visitors. Menu items pointing at it are disabled, not deleted, and you are told which. It keeps its address while in the trash."
                                            confirm-label="Delete page"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $page->title }}" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
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
                    <x-ui.empty-state icon="document" title="No pages match those filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.website.pages.index')">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="document" title="No custom pages yet" message="Create a page for anything that needs its own address.">
                        @if ($can['create'])
                            <x-slot:action>
                                <x-ui.button icon="plus" :href="route('admin.website.pages.create')">Create page</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$pages" label="pages" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
