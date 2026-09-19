@extends('layouts.admin')

@section('title', 'Careers')

{{--
    Job openings — admin.jobs.index (phase-04 §8.8, §2.18, §7.2). Table job_openings, model JobOpening.

    Controller variables (Admin\JobOpeningController@index):
      $jobs                   LengthAwarePaginator<App\Models\Cms\JobOpening> withCount(['applications as
                              new_applications_count' => status new]); applications_count is the cached column
      $filters                array<string, mixed>
      $sort                   string  sort_order | title | deadline | status | applications_count | updated_at
      $direction              string  asc | desc
      $trashed                bool
      $statusOptions          array<string, string>  JobOpeningStatus::options()
      $employmentTypeOptions  array<string, string>  EmploymentType::options()
      $workModeOptions        array<string, string>  WorkMode::options()
      $departmentOptions      list<string>
      $counts                 array{all: int, open: int, draft: int, closed: int, filled: int, trashed: int}
    Query: search (title, department, location), status, employment_type, work_mode, department,
    deadline (expired|week|month), featured, trashed, sort, direction, page.

    Writes: POST admin.jobs.reorder JSON {ids}; POST admin.jobs.status {job} status (+ reason);
    DELETE admin.jobs.destroy {job}.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canCreate = (bool) $user?->can('jobs.create');
    $canEdit = (bool) $user?->can('jobs.edit');
    $canDelete = (bool) $user?->can('jobs.delete');
    $canStatus = (bool) $user?->can('jobs.change_status') && Route::has('admin.jobs.status');
    $canApplications = (bool) $user?->can('job_applications.view_any') && Route::has('admin.job-applications.index');
    $canRestore = (bool) $user?->can('jobs.restore') && Route::has('admin.jobs.restore');

    $trashed = (bool) ($trashed ?? false);
    $sort = $sort ?? 'sort_order';
    $direction = $direction ?? 'asc';
    $counts = $counts ?? [];
    $filtered = collect(request()->only(['search', 'status', 'employment_type', 'work_mode', 'department', 'deadline', 'featured']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $canReorder = $canEdit && ! $trashed && ! $filtered && $sort === 'sort_order' && $jobs->total() <= $jobs->perPage() && $jobs->count() > 1 && Route::has('admin.jobs.reorder');
    $today = app_date(now(), 'Y-m-d');
    $periodLabels = ['monthly' => '/ month', 'yearly' => '/ year', 'hourly' => '/ hour', 'project' => '/ project'];

    $count = static fn (string $key): ?string => isset($counts[$key]) ? app_number((int) $counts[$key]) : null;
    $tabs = [['label' => 'All', 'url' => route('admin.jobs.index'), 'active' => ! $trashed && ! request()->filled('status'), 'count' => $count('all')]];
    foreach (['open' => 'Open', 'draft' => 'Drafts', 'closed' => 'Closed', 'filled' => 'Filled'] as $statusKey => $statusLabel) {
        $tabs[] = ['label' => $statusLabel, 'url' => route('admin.jobs.index', ['status' => $statusKey]), 'active' => request('status') === $statusKey, 'count' => $count($statusKey)];
    }
    if ((bool) $user?->can('jobs.restore')) {
        $tabs[] = ['label' => 'Trashed', 'url' => route('admin.jobs.index', ['trashed' => 1]), 'active' => $trashed, 'count' => $count('trashed'), 'icon' => 'trash'];
    }
    $departments = collect($departmentOptions ?? [])->filter()->mapWithKeys(fn ($d) => [(string) $d => (string) $d])->all();
@endphp

@section('header')
    <x-ui.page-header title="Careers" subtitle="Job openings, their applications and the public careers page." icon="briefcase">
        <x-slot:actions>
            @if ($canApplications)
                <x-ui.button variant="secondary" icon="document-text" :href="route('admin.job-applications.index')">Applications</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.jobs.create')">Post a job</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search title, department or location…" :reset="route('admin.jobs.index', $trashed ? ['trashed' => 1] : [])">
            @if ($trashed)
                <input type="hidden" name="trashed" value="1">
            @endif
            <x-ui.form.select name="status" :options="$statusOptions ?? []" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="employment_type" :options="$employmentTypeOptions ?? []" :selected="request('employment_type')" placeholder="Any employment type" size="sm" aria-label="Filter by employment type" />
            <x-ui.form.select name="work_mode" :options="$workModeOptions ?? []" :selected="request('work_mode')" placeholder="Any work mode" size="sm" aria-label="Filter by work mode" />
            <x-ui.form.select name="department" :options="$departments" :selected="request('department')" placeholder="Any department" size="sm" aria-label="Filter by department" />
            <x-ui.form.select name="deadline" :options="['expired' => 'Deadline passed', 'week' => 'Closes this week', 'month' => 'Closes this month']" :selected="request('deadline')" placeholder="Any deadline" size="sm" aria-label="Filter by deadline" />
            <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
        </x-ui.filter-bar>

        <div
            x-data="cmsSortable(@js(['url' => Route::has('admin.jobs.reorder') ? route('admin.jobs.reorder') : null, 'key' => 'ids', 'noun' => 'job', 'disabled' => ! $canReorder]))"
            x-init="list = $el.querySelector('tbody') || $el"
        >
            <p class="sr-only" aria-live="polite" x-text="announcement"></p>

            <x-ui.table loading="navigating" :is-empty="$jobs->isEmpty()" :columns="10">
                <x-slot:head>
                    <th scope="col" class="w-16 px-4 py-3"><span class="sr-only">Order</span></th>
                    <x-ui.th-sortable column="title" :sort="$sort" :direction="$direction">Job</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Department</th>
                    <th scope="col" class="px-4 py-3">Location</th>
                    <th scope="col" class="px-4 py-3">Type</th>
                    <th scope="col" class="px-4 py-3 text-right">Salary</th>
                    <x-ui.th-sortable column="deadline" :sort="$sort" :direction="$direction">Deadline</x-ui.th-sortable>
                    <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                    <x-ui.th-sortable column="applications_count" :sort="$sort" :direction="$direction" default="desc" align="right" :numeric="true">Applications</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($jobs as $job)
                    @php
                        $statusValue = $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status;
                        $deadlineKey = $job->deadline ? app_date($job->deadline, 'Y-m-d') : null;
                        $expired = $deadlineKey !== null && $deadlineKey < $today;
                        $newCount = (int) ($job->getAttributes()['new_applications_count'] ?? 0);
                        $applications = (int) ($job->applications_count ?? 0);
                        $period = $periodLabels[(string) $job->salary_period] ?? '';
                    @endphp
                    <tr
                        data-sortable-id="{{ $job->getKey() }}"
                        data-sortable-label="{{ $job->title }}"
                        x-on:dragstart="dragStart($event)"
                        x-on:dragover.prevent="dragOver($event)"
                        x-on:drop.prevent="drop()"
                        x-on:dragend="dragEnd($event)"
                    >
                        @include('admin.marketing.partials.sortable-cell', ['enabled' => $canReorder, 'label' => $job->title])

                        <td class="min-w-[14rem]">
                            <div class="flex items-center gap-1.5">
                                @if ($job->is_featured)
                                    <x-ui.icon name="star" class="h-3.5 w-3.5 shrink-0 text-amber-500 dark:text-amber-300" label="Featured" />
                                @endif
                                @if ($canEdit && ! $trashed)
                                    <a href="{{ route('admin.jobs.edit', $job) }}" class="truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $job->title }}</a>
                                @else
                                    <span class="truncate font-medium text-slate-900 dark:text-white">{{ $job->title }}</span>
                                @endif
                            </div>
                            <span class="block truncate font-mono text-xs text-slate-500 dark:text-slate-400">/careers/{{ $job->slug }}</span>
                        </td>

                        <td class="whitespace-nowrap text-sm">{{ $job->department ?: '—' }}</td>

                        <td class="whitespace-nowrap text-sm">
                            <span class="block">{{ $job->location ?: '—' }}</span>
                            @include('admin.marketing.partials.enum-badge', ['value' => $job->work_mode, 'size' => 'xs', 'dot' => false, 'variant' => 'outline'])
                        </td>

                        <td class="whitespace-nowrap">@include('admin.marketing.partials.enum-badge', ['value' => $job->employment_type, 'dot' => false])</td>

                        <td class="whitespace-nowrap text-right text-sm tabular-nums">
                            @if (! $job->salary_visible)
                                <span class="inline-flex items-center gap-1 text-slate-500 dark:text-slate-400"><x-ui.icon name="lock-closed" class="h-3.5 w-3.5" /> Negotiable</span>
                                @if ($job->salary_min !== null || $job->salary_max !== null)
                                    <span class="block text-2xs text-slate-400 dark:text-slate-500">hidden: {{ $job->salary_min !== null ? money((string) $job->salary_min) : '…' }}–{{ $job->salary_max !== null ? money((string) $job->salary_max) : '…' }}</span>
                                @endif
                            @elseif ($job->salary_min === null && $job->salary_max === null)
                                <span class="text-slate-400">—</span>
                            @else
                                <span class="font-medium text-slate-900 dark:text-white">
                                    {{ $job->salary_min !== null ? money((string) $job->salary_min) : '' }}@if ($job->salary_min !== null && $job->salary_max !== null) – @endif{{ $job->salary_max !== null ? money((string) $job->salary_max) : '' }}
                                </span>
                                <span class="block text-2xs text-slate-400 dark:text-slate-500">{{ $period }}</span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap text-sm">
                            @if ($deadlineKey)
                                <span @class(['font-medium text-rose-600 dark:text-rose-400' => $expired])>{{ app_date($job->deadline) }}</span>
                                @if ($expired && $statusValue === 'open')
                                    <span class="block text-2xs text-rose-500">Closes tonight</span>
                                @elseif ($expired)
                                    <span class="block text-2xs text-slate-400">Passed</span>
                                @endif
                            @else
                                <span class="text-slate-400">No deadline</span>
                            @endif
                        </td>

                        <td>@include('admin.marketing.partials.enum-badge', ['value' => $job->status])</td>

                        <td class="whitespace-nowrap text-right">
                            @if ($canApplications)
                                <a href="{{ route('admin.job-applications.index', ['job' => $job->getKey()]) }}" class="font-semibold tabular-nums text-slate-700 hover:text-brand-700 dark:text-slate-200 dark:hover:text-brand-300">{{ app_number($applications) }}</a>
                            @else
                                <span class="tabular-nums">{{ app_number($applications) }}</span>
                            @endif
                            @if ($newCount > 0)
                                <x-ui.badge color="rose" size="xs" class="ml-1">{{ app_number($newCount) }} new</x-ui.badge>
                            @endif
                        </td>

                        <td>
                            <div class="flex items-center justify-end gap-1">
                                @if ($trashed)
                                    @if ($canRestore)
                                        <form method="POST" action="{{ route('admin.jobs.restore', $job) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    @if ($canEdit)
                                        <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $job->title }}" :href="route('admin.jobs.edit', $job)" />
                                    @endif
                                    @if ($statusValue === 'open' && ! $expired && Route::has('site.careers.show'))
                                        <x-ui.icon-button icon="arrow-top-right-on-square" size="sm" label="View {{ $job->title }} on the careers page" :href="route('site.careers.show', $job->slug)" target="_blank" rel="noopener" />
                                    @endif
                                    @if ($canStatus)
                                        @if ($statusValue !== 'open')
                                            <form method="POST" action="{{ route('admin.jobs.status', $job) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="open">
                                                <x-ui.icon-button type="submit" icon="check-circle" size="sm" label="Open {{ $job->title }} for applications" class="text-emerald-600 dark:text-emerald-400" :disabled="$expired" />
                                            </form>
                                        @else
                                            <x-ui.confirm
                                                :action="route('admin.jobs.status', $job)"
                                                method="POST"
                                                id="close-job-{{ $job->getKey() }}"
                                                :title="'Close '.$job->title.'?'"
                                                message="The job leaves the careers page and stops accepting applications. Existing applications are kept."
                                                confirm-label="Close job"
                                                variant="warning"
                                                icon="lock-closed"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.icon-button icon="lock-closed" size="sm" label="Close {{ $job->title }}" />
                                                </x-slot:trigger>
                                                <div class="mt-4 space-y-2">
                                                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                                        <input type="radio" name="status" value="closed" form="close-job-{{ $job->getKey() }}" checked class="h-4 w-4 border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800"> Closed — no hire yet
                                                    </label>
                                                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                                        <input type="radio" name="status" value="filled" form="close-job-{{ $job->getKey() }}" class="h-4 w-4 border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800"> Filled — the position is taken
                                                    </label>
                                                    <input type="text" name="reason" form="close-job-{{ $job->getKey() }}" maxlength="255" placeholder="Reason (optional, kept in the activity log)" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                                                </div>
                                            </x-ui.confirm>
                                        @endif
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.jobs.destroy', $job)"
                                            :title="'Delete '.$job->title.'?'"
                                            message="The opening moves to the trash and leaves the careers page. Its applications and CVs are kept."
                                            confirm-label="Delete job"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $job->title }}" />
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
                        <x-ui.empty-state icon="briefcase" title="No openings match those filters">
                            <x-slot:action>
                                <x-ui.button variant="secondary" :href="route('admin.jobs.index')">Clear filters</x-ui.button>
                            </x-slot:action>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="briefcase" title="No openings" message="The Careers page will show your “no current openings” message until you post a job.">
                            @if ($canCreate)
                                <x-slot:action>
                                    <x-ui.button icon="plus" :href="route('admin.jobs.create')">Post a job</x-ui.button>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    @endif
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$jobs" label="openings" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
@endsection
