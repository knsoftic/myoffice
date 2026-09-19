@extends('layouts.admin')

@section('title', 'Team')

{{--
    Team members — admin.team.index (phase-04 §8.4, §2.9, §7.2).

    Controller variables (Admin\TeamMemberController@index):
      $members            LengthAwarePaginator<App\Models\Cms\TeamMember> with the photo relation (photo);
                          only trashed rows when $trashed
      $filters            array<string, mixed>
      $sort               string   sort_order | name | designation | department | status | updated_at
      $direction          string   asc | desc
      $trashed            bool
      $departmentOptions  list<string>             distinct department labels
      $statusOptions      array<string, string>    ContentStatus::options()
      $counts             array{all: int, public: int, hidden: int, draft: int, trashed: int}
    Query: search (name, designation), department, status, visibility (public|hidden), trashed, sort, direction, page.

    Writes: POST admin.team.reorder JSON {ids}; POST admin.team.visibility {member} (flips is_public);
    POST admin.team.status {member} status; DELETE admin.team.destroy {member}.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canCreate = (bool) $user?->can('team.create');
    $canEdit = (bool) $user?->can('team.edit');
    $canDelete = (bool) $user?->can('team.delete');
    $canStatus = (bool) $user?->can('team.change_status');
    $canRestore = (bool) $user?->can('team.restore') && Route::has('admin.team.restore');

    $trashed = (bool) ($trashed ?? false);
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $counts = $counts ?? [];
    $filtered = collect(request()->only(['search', 'department', 'status', 'visibility']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $canReorder = $canEdit && ! $trashed && ! $filtered && $sort === 'sort_order' && $members->total() <= $members->perPage() && $members->count() > 1 && Route::has('admin.team.reorder');
    $mediaService = app(\App\Services\Cms\MediaService::class);

    $count = static fn (string $key): ?string => isset($counts[$key]) ? app_number((int) $counts[$key]) : null;
    $tabs = [
        ['label' => 'All', 'url' => route('admin.team.index'), 'active' => ! $trashed && ! request()->filled('visibility') && ! request()->filled('status'), 'count' => $count('all')],
        ['label' => 'On the website', 'url' => route('admin.team.index', ['visibility' => 'public']), 'active' => request('visibility') === 'public', 'count' => $count('public'), 'icon' => 'eye'],
        ['label' => 'Hidden', 'url' => route('admin.team.index', ['visibility' => 'hidden']), 'active' => request('visibility') === 'hidden', 'count' => $count('hidden'), 'icon' => 'eye-slash'],
        ['label' => 'Drafts', 'url' => route('admin.team.index', ['status' => ContentStatus::Draft->value]), 'active' => request('status') === ContentStatus::Draft->value, 'count' => $count('draft')],
        ['label' => 'Trashed', 'url' => route('admin.team.index', ['trashed' => 1]), 'active' => $trashed, 'count' => $count('trashed'), 'icon' => 'trash'],
    ];
    $tabs = array_values(array_filter($tabs, static fn (array $tabItem): bool => $tabItem['label'] !== 'Trashed' || (bool) $user?->can('team.restore')));
    $departments = collect($departmentOptions ?? [])->filter()->mapWithKeys(fn ($d) => [(string) $d => (string) $d])->all();
@endphp

@section('header')
    <x-ui.page-header title="Team" subtitle="The public team page, with per-member visibility." icon="user-group">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.team.create')">New member</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search name or designation…" :reset="route('admin.team.index', $trashed ? ['trashed' => 1] : [])">
            @if ($trashed)
                <input type="hidden" name="trashed" value="1">
            @endif
            <x-ui.form.select name="department" :options="$departments" :selected="request('department')" placeholder="Any department" size="sm" aria-label="Filter by department" />
            <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="visibility" :options="['public' => 'Shown on the website', 'hidden' => 'Hidden from the website']" :selected="request('visibility')" placeholder="Public or hidden" size="sm" aria-label="Filter by visibility" />
        </x-ui.filter-bar>

        @if ($canEdit && ! $trashed && ! $canReorder && $members->count() > 1)
            <p class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <x-ui.icon name="information-circle" class="h-4 w-4" />
                Drag to reorder works on the unfiltered list sorted by display order, when everyone fits on one page.
            </p>
        @endif

        <div
            x-data="cmsSortable(@js(['url' => Route::has('admin.team.reorder') ? route('admin.team.reorder') : null, 'key' => 'ids', 'noun' => 'member', 'disabled' => ! $canReorder]))"
            x-init="list = $el.querySelector('tbody') || $el"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <x-ui.table loading="navigating" :is-empty="$members->isEmpty()" :columns="9">
                <x-slot:head>
                    <th scope="col" class="w-16 px-4 py-3"><span class="sr-only">Order</span></th>
                    <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Member</x-ui.th-sortable>
                    <x-ui.th-sortable column="department" :sort="$sort" :direction="$direction">Department</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Experience</th>
                    <th scope="col" class="px-4 py-3">Skills</th>
                    <th scope="col" class="px-4 py-3">Links</th>
                    <th scope="col" class="px-4 py-3">On website</th>
                    <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($members as $member)
                    @php
                        $photo = null;
                        foreach (['photoAsset', 'photo', 'photoMedia'] as $relationName) {
                            if ($member->relationLoaded($relationName) && $member->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
                                $photo = rescue(fn () => $mediaService->url($member->getRelation($relationName), 192), null, false);
                                break;
                            }
                        }
                        $skills = collect((array) ($member->skills ?? []));
                        $links = collect((array) ($member->social_links ?? []))->filter(fn ($url) => is_string($url) && preg_match('~^https?://~i', $url) === 1);
                        $statusValue = $member->status instanceof \BackedEnum ? $member->status->value : (string) $member->status;
                        $isLive = $statusValue === ContentStatus::Published->value && $member->is_public;
                    @endphp
                    <tr
                        data-sortable-id="{{ $member->getKey() }}"
                        data-sortable-label="{{ $member->name }}"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                        @class(['bg-slate-50/60 text-slate-500 dark:bg-slate-900/40' => ! $member->is_public])
                    >
                        @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $member->name])

                        <td class="min-w-[15rem]">
                            <div class="flex items-center gap-3">
                                <x-ui.avatar :src="$photo" :name="$member->name" size="md" @class(['opacity-60' => ! $member->is_public]) />
                                <div class="min-w-0">
                                    @if ($canEdit && ! $trashed)
                                        <a href="{{ route('admin.team.edit', $member) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $member->name }}</a>
                                    @else
                                        <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $member->name }}</span>
                                    @endif
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $member->designation }}</span>
                                    @unless ($member->is_public)
                                        <x-ui.badge color="slate" variant="outline" size="xs" icon="eye-slash" class="mt-1">Hidden from website</x-ui.badge>
                                    @endunless
                                </div>
                            </div>
                        </td>

                        <td class="whitespace-nowrap text-sm">{{ $member->department ?: '—' }}</td>

                        <td class="whitespace-nowrap text-sm">
                            @if (filled($member->experience_label))
                                {{ $member->experience_label }}
                            @elseif ($member->experience_years !== null)
                                {{ app_number((int) $member->experience_years) }} {{ (int) $member->experience_years === 1 ? 'year' : 'years' }}
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>

                        <td>
                            <div class="flex max-w-[13rem] flex-wrap gap-1">
                                @foreach ($skills->take(3) as $skill)
                                    <x-ui.badge color="slate" size="xs" :pill="false">{{ $skill }}</x-ui.badge>
                                @endforeach
                                @if ($skills->count() > 3)
                                    <x-ui.badge color="slate" variant="outline" size="xs" :pill="false" title="{{ $skills->slice(3)->implode(', ') }}">+{{ $skills->count() - 3 }}</x-ui.badge>
                                @endif
                                @if ($skills->isEmpty())
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </div>
                        </td>

                        <td>
                            <div class="flex items-center gap-1 text-slate-400 dark:text-slate-500">
                                @forelse ($links as $platformValue => $url)
                                    <a href="{{ $url }}" target="_blank" rel="nofollow noopener noreferrer" class="inline-flex h-7 w-7 items-center justify-center rounded hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-200" title="{{ \Illuminate\Support\Str::headline((string) $platformValue) }}">
                                        @include('site.marketing.partials.social-glyph', ['platform' => $platformValue, 'class' => 'h-3.5 w-3.5'])
                                        <span class="sr-only">{{ \Illuminate\Support\Str::headline((string) $platformValue) }} (opens in a new tab)</span>
                                    </a>
                                @empty
                                    <span class="text-xs">—</span>
                                @endforelse
                            </div>
                        </td>

                        <td>
                            @if ($canStatus && ! $trashed && Route::has('admin.team.visibility'))
                                <form method="POST" action="{{ route('admin.team.visibility', $member) }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        role="switch"
                                        aria-checked="{{ $member->is_public ? 'true' : 'false' }}"
                                        aria-label="{{ $member->is_public ? 'Hide '.$member->name.' from the website' : 'Show '.$member->name.' on the website' }}"
                                        @class([
                                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40',
                                            'bg-brand-600 dark:bg-brand-500' => $member->is_public,
                                            'bg-slate-200 dark:bg-slate-700' => ! $member->is_public,
                                        ])
                                    >
                                        <span @class([
                                            'inline-block h-[1.125rem] w-[1.125rem] rounded-full bg-white shadow-sm transition-transform dark:bg-white',
                                            'translate-x-[1.4rem]' => $member->is_public,
                                            'translate-x-[3px]' => ! $member->is_public,
                                        ])></span>
                                    </button>
                                </form>
                            @else
                                <x-ui.badge :color="$member->is_public ? 'emerald' : 'slate'" size="sm">{{ $member->is_public ? 'Public' : 'Hidden' }}</x-ui.badge>
                            @endif
                            @if ($member->is_public && ! $isLive)
                                <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Not live until published</p>
                            @endif
                        </td>

                        <td>@include('admin.marketing.partials.enum-badge', ['value' => $member->status])</td>

                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($trashed)
                                    @if ($canRestore)
                                        <form method="POST" action="{{ route('admin.team.restore', $member) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    @if ($canEdit)
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $member->name }}" :href="route('admin.team.edit', $member)" />
                                    @endif
                                    @if ($canStatus && Route::has('admin.team.status') && $statusValue !== ContentStatus::Published->value)
                                        <form method="POST" action="{{ route('admin.team.status', $member) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ ContentStatus::Published->value }}">
                                            <x-ui.icon-button type="submit" icon="check-circle" size="sm" label="Publish {{ $member->name }}" class="text-emerald-600 dark:text-emerald-400" />
                                        </form>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.team.destroy', $member)"
                                            :title="'Delete '.$member->name.'?'"
                                            message="The profile moves to the trash and leaves the team page. No account or HR record is touched."
                                            confirm-label="Delete member"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $member->name }}" />
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
                        <x-ui.empty-state icon="user-group" title="No team members match those filters">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.team.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="user-group" title="No team members yet" message="The About page team section stays hidden until you add someone.">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" :href="route('admin.team.create')">Add member</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$members" label="team members" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
@endsection
