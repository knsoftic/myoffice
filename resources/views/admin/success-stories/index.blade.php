@extends('layouts.admin')

@section('title', 'Success stories')

{{--
    Success stories — admin.success-stories.index (phase-04 §8.6, §2.12, §7.2).

    Controller variables (Admin\SuccessStoryController@index):
      $stories            LengthAwarePaginator<App\Models\Cms\SuccessStory> with the photo relation (photo)
      $filters            array<string, mixed>
      $sort               string   sort_order | student_name | course_name | status | updated_at
      $direction          string   asc | desc
      $trashed            bool
      $courseOptions      array<string, string>   distinct course_name snapshots
      $platformOptions    array<string, string>   distinct platform values
      $statusOptions      array<string, string>
      $counts             array{all: int, published: int, draft: int, featured: int, trashed: int}
    Query: search (student name, headline, company), course, platform, status, featured, trashed, sort, direction, page.

    Writes: POST admin.success-stories.reorder JSON {ids}; POST admin.success-stories.featured {story};
    POST admin.success-stories.status {story} status; DELETE admin.success-stories.destroy {story}.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canCreate = (bool) $user?->can('success_stories.create');
    $canEdit = (bool) $user?->can('success_stories.edit');
    $canDelete = (bool) $user?->can('success_stories.delete');
    $canStatus = (bool) $user?->can('success_stories.change_status');
    $canRestore = (bool) $user?->can('success_stories.restore') && Route::has('admin.success-stories.restore');

    $trashed = (bool) ($trashed ?? false);
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $counts = $counts ?? [];
    $filtered = collect(request()->only(['search', 'course', 'platform', 'status', 'featured']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $canReorder = $canEdit && ! $trashed && ! $filtered && $sort === 'sort_order' && $stories->total() <= $stories->perPage() && $stories->count() > 1 && Route::has('admin.success-stories.reorder');
    $mediaService = app(\App\Services\Cms\MediaService::class);

    $count = static fn (string $key): ?string => isset($counts[$key]) ? app_number((int) $counts[$key]) : null;
    $tabs = [
        ['label' => 'All', 'url' => route('admin.success-stories.index'), 'active' => ! $trashed && ! request()->filled('status') && ! request()->filled('featured'), 'count' => $count('all')],
        ['label' => 'Published', 'url' => route('admin.success-stories.index', ['status' => ContentStatus::Published->value]), 'active' => request('status') === ContentStatus::Published->value, 'count' => $count('published')],
        ['label' => 'Drafts', 'url' => route('admin.success-stories.index', ['status' => ContentStatus::Draft->value]), 'active' => request('status') === ContentStatus::Draft->value, 'count' => $count('draft')],
        ['label' => 'Featured', 'url' => route('admin.success-stories.index', ['featured' => 1]), 'active' => request('featured') === '1', 'count' => $count('featured'), 'icon' => 'star'],
        ['label' => 'Trashed', 'url' => route('admin.success-stories.index', ['trashed' => 1]), 'active' => $trashed, 'count' => $count('trashed'), 'icon' => 'trash'],
    ];
    $tabs = array_values(array_filter($tabs, static fn (array $tabItem): bool => $tabItem['label'] !== 'Trashed' || (bool) $user?->can('success_stories.restore')));
@endphp

@section('header')
    <x-ui.page-header title="Success stories" subtitle="Staff-written student outcomes. No approval queue: publish when ready." icon="trophy">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.success-stories.create')">New story</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search student, headline or company…" :reset="route('admin.success-stories.index', $trashed ? ['trashed' => 1] : [])">
            @if ($trashed)
                <input type="hidden" name="trashed" value="1">
            @endif
            <x-ui.form.select name="course" :options="$courseOptions ?? []" :selected="request('course')" placeholder="Any course" size="sm" aria-label="Filter by course" />
            <x-ui.form.select name="platform" :options="$platformOptions ?? []" :selected="request('platform')" placeholder="Any platform" size="sm" aria-label="Filter by platform" />
            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
        </x-ui.filter-bar>

        <div
            x-data="cmsSortable(@js(['url' => Route::has('admin.success-stories.reorder') ? route('admin.success-stories.reorder') : null, 'key' => 'ids', 'noun' => 'story', 'disabled' => ! $canReorder]))"
            x-init="list = $el.querySelector('tbody') || $el"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <x-ui.table loading="navigating" :is-empty="$stories->isEmpty()" :columns="8">
                <x-slot:head>
                    <th scope="col" class="w-16 px-4 py-3"><span class="sr-only">Order</span></th>
                    <x-ui.th-sortable column="student_name" :sort="$sort" :direction="$direction">Student</x-ui.th-sortable>
                    <x-ui.th-sortable column="course_name" :sort="$sort" :direction="$direction">Course</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Achievement</th>
                    <th scope="col" class="px-4 py-3">Company / platform</th>
                    <th scope="col" class="px-4 py-3 text-center">Featured</th>
                    <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($stories as $story)
                    @php
                        $photo = null;
                        foreach (['photoAsset', 'photo', 'photoMedia'] as $relationName) {
                            if ($story->relationLoaded($relationName) && $story->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
                                $photo = rescue(fn () => $mediaService->url($story->getRelation($relationName), 192), null, false);
                                break;
                            }
                        }
                        $statusValue = $story->status instanceof \BackedEnum ? $story->status->value : (string) $story->status;
                    @endphp
                    <tr
                        data-sortable-id="{{ $story->getKey() }}"
                        data-sortable-label="{{ $story->student_name }}"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                    >
                        @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $story->student_name])

                        <td class="min-w-[15rem]">
                            <div class="flex items-center gap-3">
                                <x-ui.avatar :src="$photo" :name="$story->student_name" size="md" />
                                <div class="min-w-0">
                                    @if ($canEdit && ! $trashed)
                                        <a href="{{ route('admin.success-stories.edit', $story) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $story->student_name }}</a>
                                    @else
                                        <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $story->student_name }}</span>
                                    @endif
                                    @if (filled($story->headline))
                                        <span class="line-clamp-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">{{ $story->headline }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>

                        <td class="whitespace-nowrap text-sm">{{ $story->course_name ?: '—' }}</td>

                        <td class="max-w-[14rem] text-sm"><span class="line-clamp-2">{{ $story->achievement ?: '—' }}</span></td>

                        <td class="whitespace-nowrap text-sm">
                            <span class="block">{{ $story->company_name ?: '—' }}</span>
                            <span class="flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $story->platform }}
                                @if (filled($story->video_url))
                                    <x-ui.icon name="video-camera" class="h-3.5 w-3.5 text-sky-600 dark:text-sky-400" label="Has a video" />
                                @endif
                            </span>
                        </td>

                        <td class="text-center">
                            @if ($canStatus && ! $trashed && Route::has('admin.success-stories.featured'))
                                <form method="POST" action="{{ route('admin.success-stories.featured', $story) }}" class="inline">
                                    @csrf
                                    <x-ui.icon-button type="submit" icon="star" size="sm" :label="$story->is_featured ? 'Unfeature the story of '.$story->student_name : 'Feature the story of '.$story->student_name" @class(['text-amber-500 dark:text-amber-300' => $story->is_featured]) />
                                </form>
                            @elseif ($story->is_featured)
                                <x-ui.icon name="star" class="mx-auto h-4 w-4 text-amber-500 dark:text-amber-300" label="Featured" />
                            @endif
                        </td>

                        <td>@include('admin.marketing.partials.enum-badge', ['value' => $story->status])</td>

                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($trashed)
                                    @if ($canRestore)
                                        <form method="POST" action="{{ route('admin.success-stories.restore', $story) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    @if ($canEdit)
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit the story of {{ $story->student_name }}" :href="route('admin.success-stories.edit', $story)" />
                                    @endif
                                    @if ($canStatus && Route::has('admin.success-stories.status') && $statusValue !== ContentStatus::Published->value)
                                        <form method="POST" action="{{ route('admin.success-stories.status', $story) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ ContentStatus::Published->value }}">
                                            <x-ui.icon-button type="submit" icon="check-circle" size="sm" label="Publish the story of {{ $story->student_name }}" class="text-emerald-600 dark:text-emerald-400" />
                                        </form>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.success-stories.destroy', $story)"
                                            :title="'Delete the story of '.$story->student_name.'?'"
                                            message="It moves to the trash and leaves the website."
                                            confirm-label="Delete story"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete the story of {{ $story->student_name }}" />
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
                        <x-ui.empty-state icon="trophy" title="No stories match those filters">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.success-stories.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="trophy" title="No success stories yet" message="Share where your students ended up.">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" :href="route('admin.success-stories.create')">Add story</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$stories" label="stories" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
@endsection
