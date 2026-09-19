@extends('layouts.admin')

@section('title', 'Blog posts')

{{--
    Blog posts — admin.blog-posts.index (phase-04 §8.7, §2.16, §9.1.1).

    Controller variables (Admin\BlogPostController@index) — every query goes through BlogPost::visibleTo($user),
    so an author never sees or counts another author's drafts:
      $posts             LengthAwarePaginator<App\Models\Cms\BlogPost> with category, tags, author and the featured
                         image relation (featuredImage); trashed rows only on the trashed tab
      $tab               string  all (default) | published | scheduled | draft | archived | mine | trashed
      $counts            array<string, int>  one per tab
      $filters           array<string, mixed>
      $sort              string  updated_at (default) | title | status | published_at | views_count
      $direction         string  asc | desc
      $categoryOptions   array<int, string>
      $tagOptions        array<int, string>
      $authorOptions     array<int, string>   only for editors (blog_posts.approve); empty for authors
      $statusOptions     array<string, string>  ContentStatus::options()
    Query: tab, search (title, excerpt, content), category, tag, author, status, featured (1|0), from, to (published_at,
    Y-m-d), has_image (1|0), sort, direction, page.

    Writes (policy row rules re-checked on every route): POST admin.blog-posts.publish / .unpublish / .archive {post};
    POST admin.blog-posts.schedule {post} published_at (datetime-local in the display timezone, after now);
    DELETE admin.blog-posts.destroy {post}; GET admin.blog-posts.preview-link {post} → JSON {url} (signed preview).
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    // A row rule is the policy's when one is registered; otherwise the module permission.
    $allows = static function (string $ability, string $permission, $post) use ($user): bool {
        return Gate::getPolicyFor($post) !== null ? Gate::forUser($user)->allows($ability, $post) : (bool) $user?->can($permission);
    };

    $canCreate = (bool) $user?->can('blog_posts.create');
    $canEditor = (bool) $user?->can('blog_posts.approve');
    $canReports = (bool) $user?->can('blog_posts.view_reports') && Route::has('admin.blog-posts.stats');
    $canRestore = (bool) $user?->can('blog_posts.restore') && Route::has('admin.blog-posts.restore');

    $tab = in_array($tab ?? 'all', ['all', 'published', 'scheduled', 'draft', 'archived', 'mine', 'trashed'], true) ? ($tab ?? 'all') : 'all';
    $counts = $counts ?? [];
    $sort = $sort ?? 'updated_at';
    $direction = $direction ?? 'desc';
    $filtered = collect(request()->except(['tab', 'page', 'sort', 'direction']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $isTrash = $tab === 'trashed';
    $timezone = \App\Support\Format::displayTimezone();
    $minSchedule = app_datetime(now()->addMinutes(5), 'Y-m-d\TH:i');

    $tabDefs = ['all' => ['All', null], 'published' => ['Published', 'check-circle'], 'scheduled' => ['Scheduled', 'calendar-days'], 'draft' => ['Drafts', 'pencil'], 'archived' => ['Archived', 'inbox-stack'], 'mine' => ['Mine', 'user'], 'trashed' => ['Trashed', 'trash']];
    $tabs = [];
    foreach ($tabDefs as $key => [$label, $tabIcon]) {
        if ($key === 'trashed' && ! $user?->can('blog_posts.restore')) {
            continue;
        }
        $tabs[] = ['label' => $label, 'url' => route('admin.blog-posts.index', $key === 'all' ? [] : ['tab' => $key]), 'active' => $tab === $key, 'count' => isset($counts[$key]) ? app_number((int) $counts[$key]) : null, 'icon' => $tabIcon];
    }
@endphp

@section('header')
    <x-ui.page-header title="Blog posts" subtitle="Drafts, scheduled posts, tags, authors and honest view counts." icon="newspaper">
        <x-slot:actions>
            @if (Route::has('admin.blog-posts.calendar'))
                <x-ui.button variant="secondary" icon="calendar-days" :href="route('admin.blog-posts.calendar')">Calendar</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.blog-posts.create')">New post</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search title, excerpt or content…" :reset="route('admin.blog-posts.index', $tab === 'all' ? [] : ['tab' => $tab])">
            @if ($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif
            <x-ui.form.select name="category" :options="$categoryOptions ?? []" :selected="request('category')" placeholder="Any category" size="sm" aria-label="Filter by category" />
            <x-ui.form.select name="tag" :options="$tagOptions ?? []" :selected="request('tag')" placeholder="Any tag" size="sm" aria-label="Filter by tag" />
            @if (! empty($authorOptions))
                <x-ui.form.select name="author" :options="$authorOptions" :selected="request('author')" placeholder="Any author" size="sm" aria-label="Filter by author" />
            @endif
            @if (in_array($tab, ['all', 'mine'], true))
                <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            @endif
            <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
            <x-ui.form.select name="has_image" :options="['1' => 'With featured image', '0' => 'Without featured image']" :selected="request('has_image')" placeholder="Image or not" size="sm" aria-label="Filter by featured image" />
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">Published from</span>
                <input type="date" name="from" value="{{ request('from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">to</span>
                <input type="date" name="to" value="{{ request('to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$posts->isEmpty()" :columns="9">
            <x-slot:head>
                <x-ui.th-sortable column="title" :sort="$sort" :direction="$direction">Post</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Category</th>
                <th scope="col" class="px-4 py-3">Tags</th>
                <th scope="col" class="px-4 py-3">Author</th>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <x-ui.th-sortable column="published_at" :sort="$sort" :direction="$direction" default="desc">Published</x-ui.th-sortable>
                <x-ui.th-sortable column="views_count" :sort="$sort" :direction="$direction" default="desc" align="right" :numeric="true">Views</x-ui.th-sortable>
                <x-ui.th-sortable column="updated_at" :sort="$sort" :direction="$direction" default="desc">Updated</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($posts as $post)
                @php
                    $statusValue = $post->status instanceof \BackedEnum ? $post->status->value : (string) $post->status;
                    $tags = $post->relationLoaded('tags') ? $post->tags : collect();
                    $author = $post->relationLoaded('author') ? $post->author : null;
                    $canUpdate = ! $isTrash && $allows('update', 'blog_posts.edit', $post);
                    $canChange = ! $isTrash && $allows('changeStatus', 'blog_posts.change_status', $post);
                    $canDelete = ! $isTrash && $allows('delete', 'blog_posts.delete', $post);
                    $isScheduled = $statusValue === ContentStatus::Scheduled->value;
                    $isPublished = $statusValue === ContentStatus::Published->value;
                    $views = (int) ($post->views_count ?? 0);
                @endphp
                <tr>
                    <td class="min-w-[18rem]">
                        <div class="flex items-center gap-3">
                            @include('admin.marketing.partials.thumb', ['model' => $post, 'relations' => ['featuredImageAsset', 'featuredImage', 'featuredImageMedia'], 'column' => 'featured_image_media_id', 'alt' => $post->title, 'box' => 'h-10 w-16', 'icon' => 'newspaper'])
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    @if ($post->is_featured)
                                        <x-ui.icon name="star" class="h-3.5 w-3.5 shrink-0 text-amber-500 dark:text-amber-300" label="Featured" />
                                    @endif
                                    @if ($canUpdate)
                                        <a href="{{ route('admin.blog-posts.edit', $post) }}" class="line-clamp-1 font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $post->title }}</a>
                                    @else
                                        <span class="line-clamp-1 font-medium text-slate-900 dark:text-white">{{ $post->title }}</span>
                                    @endif
                                </div>
                                <span class="block truncate font-mono text-xs text-slate-500 dark:text-slate-400">/blog/{{ $post->slug }}</span>
                                @if ($isScheduled && $post->published_at)
                                    <span class="mt-0.5 flex items-center gap-1 text-2xs font-medium text-amber-700 dark:text-amber-400">
                                        <x-ui.icon name="clock" class="h-3 w-3" />
                                        Scheduled for {{ app_datetime($post->published_at) }} — {{ \App\Support\Format::forHumans($post->published_at) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </td>

                    <td class="whitespace-nowrap text-sm">{{ $post->relationLoaded('category') ? ($post->category?->name ?? '—') : '—' }}</td>

                    <td>
                        <div class="flex max-w-[12rem] flex-wrap gap-1">
                            @foreach ($tags->take(3) as $postTag)
                                <x-ui.badge color="slate" size="xs" :pill="false">{{ $postTag->name }}</x-ui.badge>
                            @endforeach
                            @if ($tags->count() > 3)
                                <x-ui.badge color="slate" variant="outline" size="xs" :pill="false" title="{{ $tags->slice(3)->pluck('name')->implode(', ') }}">+{{ $tags->count() - 3 }}</x-ui.badge>
                            @endif
                            @if ($tags->isEmpty())
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </div>
                    </td>

                    <td class="whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <x-ui.avatar :src="$author?->avatar_url ?? null" :name="$author?->name ?? 'Company'" size="xs" />
                            <span class="text-sm">{{ $author?->name ?? 'Company' }}</span>
                        </div>
                    </td>

                    <td>@include('admin.marketing.partials.enum-badge', ['value' => $post->status])</td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ $post->published_at ? app_datetime($post->published_at) : '—' }}</td>

                    <td class="whitespace-nowrap text-right tabular-nums">
                        @if ($canReports && ! $isTrash)
                            <a href="{{ route('admin.blog-posts.stats', $post) }}" class="font-medium text-slate-700 hover:text-brand-700 dark:text-slate-200 dark:hover:text-brand-300" title="Views of {{ $post->title }}">{{ app_number($views) }}</a>
                        @else
                            {{ app_number($views) }}
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($isTrash ? $post->deleted_at : $post->updated_at) }}</td>

                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($isTrash)
                                @if ($canRestore)
                                    <form method="POST" action="{{ route('admin.blog-posts.restore', $post) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                    </form>
                                @endif
                            @else
                                @if ($canUpdate)
                                    <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $post->title }}" :href="route('admin.blog-posts.edit', $post)" />
                                @endif

                                @if ($isPublished && Route::has('site.blog.show'))
                                    <x-ui.icon-button icon="arrow-top-right-on-square" size="sm" label="View {{ $post->title }} on the website" :href="route('site.blog.show', $post->slug)" target="_blank" rel="noopener" />
                                @elseif (Route::has('admin.blog-posts.preview-link'))
                                    <div
                                        x-data="{
                                            async open() {
                                                const tab = window.open('about:blank', '_blank');
                                                try {
                                                    const response = await fetch(@js(route('admin.blog-posts.preview-link', $post)), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                                                    const body = await response.json();
                                                    if (! response.ok || ! body.url) throw new Error(body.message || 'No preview link');
                                                    if (tab) { tab.location = body.url; } else { window.location = body.url; }
                                                } catch (error) {
                                                    if (tab) tab.close();
                                                    window.Alpine?.store('toasts')?.push({ type: 'error', message: 'The preview link could not be created.' });
                                                }
                                            },
                                        }"
                                    >
                                        <x-ui.icon-button icon="eye" size="sm" label="Preview {{ $post->title }}" x-on:click="open()" />
                                    </div>
                                @endif

                                @if ($canChange)
                                    @if (! $isPublished)
                                        <x-ui.confirm
                                            :action="route('admin.blog-posts.publish', $post)"
                                            method="POST"
                                            :title="'Publish '.$post->title.' now?'"
                                            :message="$isScheduled ? 'The schedule is replaced: the post goes live immediately.' : 'The post goes live at /blog/'.$post->slug.' for every visitor.'"
                                            confirm-label="Publish now"
                                            variant="warning"
                                            icon="check-circle"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="check-circle" size="sm" label="Publish {{ $post->title }}" class="text-emerald-600 dark:text-emerald-400" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>

                                        <x-ui.confirm
                                            :action="route('admin.blog-posts.schedule', $post)"
                                            method="POST"
                                            id="schedule-post-{{ $post->getKey() }}"
                                            :title="($isScheduled ? 'Reschedule ' : 'Schedule ').$post->title"
                                            message="The post publishes itself at the chosen time and stays invisible until then."
                                            :confirm-label="$isScheduled ? 'Reschedule' : 'Schedule'"
                                            variant="warning"
                                            icon="calendar-days"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="calendar-days" size="sm" label="Schedule {{ $post->title }}" />
                                            </x-slot:trigger>
                                            <div class="mt-4">
                                                <label for="schedule-at-{{ $post->getKey() }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                    Publish at <span class="text-rose-500">*</span> <span class="font-normal text-slate-400">({{ $timezone }})</span>
                                                </label>
                                                <input
                                                    id="schedule-at-{{ $post->getKey() }}"
                                                    type="datetime-local"
                                                    name="published_at"
                                                    form="schedule-post-{{ $post->getKey() }}"
                                                    min="{{ $minSchedule }}"
                                                    value="{{ $isScheduled && $post->published_at ? app_datetime($post->published_at, 'Y-m-d\TH:i') : '' }}"
                                                    required
                                                    class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                                                >
                                            </div>
                                        </x-ui.confirm>
                                    @else
                                        <x-ui.confirm
                                            :action="route('admin.blog-posts.unpublish', $post)"
                                            method="POST"
                                            :title="'Unpublish '.$post->title.'?'"
                                            message="The post returns to draft and its address returns 404. Its first publish date is kept."
                                            confirm-label="Unpublish"
                                            variant="warning"
                                            icon="eye-slash"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="eye-slash" size="sm" label="Unpublish {{ $post->title }}" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif

                                    @if ($statusValue !== ContentStatus::Archived->value)
                                        <x-ui.confirm
                                            :action="route('admin.blog-posts.archive', $post)"
                                            method="POST"
                                            :title="'Archive '.$post->title.'?'"
                                            message="Archiving retires the post from the website without deleting anything. It can be moved back to draft later."
                                            confirm-label="Archive"
                                            variant="warning"
                                            icon="inbox-stack"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="inbox-stack" size="sm" label="Archive {{ $post->title }}" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                @endif

                                @if ($canDelete)
                                    <x-ui.confirm
                                        :action="route('admin.blog-posts.destroy', $post)"
                                        :title="'Delete '.$post->title.'?'"
                                        message="The post moves to the trash. Its view history is kept and its address stays reserved."
                                        confirm-label="Delete post"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $post->title }}" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state icon="newspaper" title="No posts match those filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.blog-posts.index', $tab === 'all' ? [] : ['tab' => $tab])">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @elseif ($tab === 'trashed')
                    <x-ui.empty-state icon="trash" title="The trash is empty" />
                @elseif ($tab === 'scheduled')
                    <x-ui.empty-state icon="calendar-days" title="Nothing is scheduled" message="Schedule a post from its editor or the calendar." :compact="true" />
                @elseif ($tab !== 'all' && $tab !== 'mine')
                    <x-ui.empty-state icon="newspaper" :title="'No '.strtolower($tabDefs[$tab][0]).' posts'" :compact="true" />
                @else
                    <x-ui.empty-state icon="newspaper" title="No posts yet — write the first one">
                        @if ($canCreate)
                            <x-slot:action>
                                <x-ui.button icon="plus" :href="route('admin.blog-posts.create')">New post</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$posts" label="posts" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
