@extends('layouts.admin')

@section('title', 'Job applications')

{{--
    Job applications — the six-stage pipeline — admin.job-applications.index (phase-04 §8.9, §2.19, §9.1.3).
    A Kanban board is deliberately NOT built here (§8.9): the stage tabs plus the funnel cover the pipeline.

    Controller variables (Admin\JobApplicationController@index) — every query goes through
    JobApplication::visibleTo($user) (view_any = all; otherwise assigned to me or on an opening I created):
      $applications     LengthAwarePaginator<App\Models\Cms\JobApplication> with jobOpening and assignee
      $stage            string  all (default) | new | reviewing | shortlisted | interview | selected | rejected
      $stages           list<array{value: string, label: string, color: string}>  JobApplicationStatus::cases() in
                        pipeline order (label() / color())
      $counts           array<string, int>  one per stage value plus 'all' — scoped to the selected job when ?job= is set
      $filters          array<string, mixed>
      $sort             string  created_at (default) | applicant_name | experience_years | expected_salary | rating | status
      $direction        string  asc | desc
      $jobOptions       array<int, string>  openings the user can see applications of
      $reviewerOptions  array<int, string>  users holding job_applications.view_any (assign dialog + filter)
      $selectedJob      ?App\Models\Cms\JobOpening  when ?job= is set (funnel label, public share link)
    Query: stage, search (name, email, phone), job, assigned (user id), unassigned (1), rating (1-5), from, to
    (applied date, Y-m-d), sort, direction, page.

    Writes: POST admin.job-applications.status / .assign (dialogs); PUT admin.job-applications.update {application}
    with ONLY `rating` (inline stars — the controller must leave internal_notes untouched when the key is absent);
    DELETE admin.job-applications.destroy {application}; GET admin.job-applications.cv {application};
    GET admin.job-applications.export (current query).
--}}

@php
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $policyOr = static function (string $ability, string $permission, $record) use ($user): bool {
        return Gate::getPolicyFor($record) !== null ? Gate::forUser($user)->allows($ability, $record) : (bool) $user?->can($permission);
    };

    $canStage = (bool) $user?->can('job_applications.change_status') && Route::has('admin.job-applications.status');
    $canAssign = (bool) $user?->can('job_applications.assign') && Route::has('admin.job-applications.assign');
    $canExport = (bool) $user?->can('job_applications.export') && Route::has('admin.job-applications.export');

    $stages = collect($stages ?? [
        ['value' => 'new', 'label' => 'New', 'color' => 'sky'],
        ['value' => 'reviewing', 'label' => 'Reviewing', 'color' => 'indigo'],
        ['value' => 'shortlisted', 'label' => 'Shortlisted', 'color' => 'violet'],
        ['value' => 'interview', 'label' => 'Interview', 'color' => 'amber'],
        ['value' => 'selected', 'label' => 'Selected', 'color' => 'emerald'],
        ['value' => 'rejected', 'label' => 'Rejected', 'color' => 'rose'],
    ]);
    $stage = (string) ($stage ?? request('stage', 'all'));
    $stage = $stage === 'all' || $stages->contains('value', $stage) ? $stage : 'all';
    $counts = $counts ?? [];
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $filtered = collect(request()->except(['stage', 'page', 'sort', 'direction', 'job']))->filter(fn ($v) => filled($v))->isNotEmpty();
    $jobId = request('job');
    $publicJobUrl = isset($selectedJob) && $selectedJob && Route::has('site.careers.show') ? route('site.careers.show', $selectedJob->slug) : null;
    $mediaIcon = ['application/pdf' => 'PDF', 'application/msword' => 'DOC', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX'];
    $stageUrl = static fn (string $value): string => route('admin.job-applications.index', array_filter(array_merge(request()->except(['stage', 'page']), ['stage' => $value === 'all' ? null : $value]), fn ($v) => filled($v)));
@endphp

@section('header')
    <x-ui.page-header title="Job applications" :subtitle="isset($selectedJob) && $selectedJob ? 'For '.$selectedJob->title : 'Every candidate, stage by stage. CVs never leave the private disk.'" icon="document-text" :back="isset($selectedJob) && $selectedJob && Route::has('admin.jobs.index') ? route('admin.jobs.index') : null">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.job-applications.export', request()->query())">Export</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        {{-- Funnel --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($stages as $stageDef)
                <x-ui.stat-card
                    :label="$stageDef['label']"
                    :value="app_number((int) ($counts[$stageDef['value']] ?? 0))"
                    :color="in_array($stageDef['color'], ['brand', 'emerald', 'amber', 'rose', 'sky', 'violet', 'slate'], true) ? $stageDef['color'] : 'slate'"
                    :href="$stageUrl($stageDef['value'])"
                />
            @endforeach
        </div>

        {{-- Pipeline tabs, in pipeline order, each count coloured by its stage --}}
        <div class="border-b border-slate-200 dark:border-slate-800">
            <nav class="no-scrollbar -mb-px flex gap-1 overflow-x-auto" role="tablist" aria-label="Pipeline stages">
                @foreach (collect([['value' => 'all', 'label' => 'All', 'color' => 'slate']])->concat($stages) as $stageDef)
                    @php $active = $stage === $stageDef['value']; @endphp
                    <a
                        href="{{ $stageUrl($stageDef['value']) }}"
                        role="tab"
                        aria-selected="{{ $active ? 'true' : 'false' }}"
                        @class([
                            'inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                            'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $active,
                            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:border-slate-700 dark:hover:text-slate-200' => ! $active,
                        ])
                    >
                        {{ $stageDef['label'] }}
                        <x-ui.badge :color="$stageDef['color']" size="xs">{{ app_number((int) ($counts[$stageDef['value']] ?? 0)) }}</x-ui.badge>
                    </a>
                @endforeach
            </nav>
        </div>

        <x-ui.filter-bar placeholder="Search name, email or phone…" :reset="route('admin.job-applications.index', array_filter(['stage' => $stage === 'all' ? null : $stage]))">
            @if ($stage !== 'all')
                <input type="hidden" name="stage" value="{{ $stage }}">
            @endif
            <x-ui.form.select name="job" :options="$jobOptions ?? []" :selected="$jobId" placeholder="Any opening" size="sm" aria-label="Filter by job opening" />
            <x-ui.form.select name="assigned" :options="$reviewerOptions ?? []" :selected="request('assigned')" placeholder="Any reviewer" size="sm" aria-label="Filter by reviewer" />
            <x-ui.form.select name="rating" :options="['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star']" :selected="request('rating')" placeholder="Any rating" size="sm" aria-label="Filter by rating" />
            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="unassigned" value="1" @checked(request('unassigned') === '1') class="h-4 w-4 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800">
                Unassigned only
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">Applied from</span>
                <input type="date" name="from" value="{{ request('from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">to</span>
                <input type="date" name="to" value="{{ request('to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$applications->isEmpty()" :columns="9">
            <x-slot:head>
                <x-ui.th-sortable column="applicant_name" :sort="$sort" :direction="$direction">Applicant</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Opening</th>
                <x-ui.th-sortable column="experience_years" :sort="$sort" :direction="$direction" align="right" :numeric="true">Experience</x-ui.th-sortable>
                <x-ui.th-sortable column="expected_salary" :sort="$sort" :direction="$direction" align="right" :numeric="true">Expected salary</x-ui.th-sortable>
                <x-ui.th-sortable column="rating" :sort="$sort" :direction="$direction" default="desc">Rating</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Reviewer</th>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Stage</x-ui.th-sortable>
                <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc">Applied</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($applications as $application)
                @php
                    $job = $application->relationLoaded('jobOpening') ? $application->jobOpening : null;
                    $assignee = null;
                    foreach (['assignee', 'assignedTo', 'reviewer'] as $relationName) {
                        if ($application->relationLoaded($relationName)) {
                            $assignee = $application->getRelation($relationName);
                            break;
                        }
                    }
                    $statusEnum = $application->status;
                    $statusValue = $statusEnum instanceof \BackedEnum ? $statusEnum->value : (string) $statusEnum;
                    $nextOptions = $statusEnum instanceof \BackedEnum && method_exists($statusEnum, 'allowedNext')
                        ? collect($statusEnum->allowedNext())->map(fn ($case) => ['value' => $case instanceof \BackedEnum ? $case->value : (string) $case, 'label' => $case instanceof \BackedEnum && method_exists($case, 'label') ? $case->label() : \Illuminate\Support\Str::headline((string) $case)])->values()->all()
                        : [];
                    $currentLabel = $statusEnum instanceof \BackedEnum && method_exists($statusEnum, 'label') ? $statusEnum->label() : \Illuminate\Support\Str::headline($statusValue);
                    $canView = $policyOr('view', 'job_applications.view', $application);
                    $canUpdate = $policyOr('update', 'job_applications.edit', $application) && Route::has('admin.job-applications.update');
                    $canDownload = $policyOr('download', 'job_applications.download', $application) && Route::has('admin.job-applications.cv');
                    $canDelete = $policyOr('delete', 'job_applications.delete', $application);
                @endphp
                <tr>
                    <td class="min-w-[15rem]">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$application->applicant_name" size="md" />
                            <div class="min-w-0">
                                @if ($canView)
                                    <a href="{{ route('admin.job-applications.show', $application) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $application->applicant_name }}</a>
                                @else
                                    <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $application->applicant_name }}</span>
                                @endif
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $application->email }}</span>
                            </div>
                        </div>
                    </td>

                    <td class="max-w-[14rem] text-sm"><span class="line-clamp-2">{{ $job?->title ?? '—' }}</span></td>

                    <td class="whitespace-nowrap text-right text-sm tabular-nums">{{ $application->experience_years !== null ? app_number((int) $application->experience_years).' yrs' : '—' }}</td>

                    <td class="whitespace-nowrap text-right text-sm tabular-nums">{{ $application->expected_salary !== null ? money((string) $application->expected_salary) : '—' }}</td>

                    <td class="whitespace-nowrap">
                        @if ($canUpdate)
                            <form method="POST" action="{{ route('admin.job-applications.update', $application) }}" class="inline-flex items-center gap-0.5" aria-label="Rate {{ $application->applicant_name }}">
                                @csrf
                                @method('PUT')
                                @for ($star = 1; $star <= 5; $star++)
                                    <button type="submit" name="rating" value="{{ $star }}" class="rounded p-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40" title="{{ $star }} of 5" aria-label="Rate {{ $star }} out of 5">
                                        <svg class="h-4 w-4 {{ (int) $application->rating >= $star ? 'text-amber-400 dark:text-amber-300' : 'text-slate-200 hover:text-amber-200 dark:text-slate-700 dark:hover:text-amber-500/50' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" /></svg>
                                    </button>
                                @endfor
                            </form>
                        @elseif ($canView && $application->rating)
                            @include('admin.marketing.partials.stars', ['rating' => $application->rating])
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($assignee)
                            <div class="flex items-center gap-2">
                                <x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="xs" />
                                <span class="text-sm">{{ $assignee->name }}</span>
                            </div>
                        @else
                            <span class="text-xs text-slate-400">Unassigned</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">@include('admin.marketing.partials.enum-badge', ['value' => $application->status])</td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ app_datetime($application->created_at) }}</td>

                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($canView)
                                <x-ui.icon-button icon="eye" size="sm" label="Open {{ $application->applicant_name }}" :href="route('admin.job-applications.show', $application)" />
                            @endif

                            @if ($canDownload)
                                <x-ui.icon-button icon="arrow-down-tray" size="sm" label="Download the CV of {{ $application->applicant_name }}" :href="route('admin.job-applications.cv', $application)" />
                            @else
                                <span title="Downloading CVs needs the job applications download permission.">
                                    <x-ui.icon-button icon="arrow-down-tray" size="sm" label="CV download not permitted" :disabled="true" />
                                </span>
                            @endif

                            @if ($canStage && $nextOptions !== [])
                                <x-ui.icon-button
                                    icon="arrow-path"
                                    size="sm"
                                    label="Change the stage of {{ $application->applicant_name }}"
                                    x-on:click="$dispatch('open-modal', { name: 'application-stage', url: @js(route('admin.job-applications.status', $application)), label: @js($application->applicant_name), current: @js($currentLabel), options: @js($nextOptions) })"
                                />
                            @endif

                            @if ($canAssign)
                                <x-ui.icon-button
                                    icon="user-plus"
                                    size="sm"
                                    label="Assign a reviewer to {{ $application->applicant_name }}"
                                    x-on:click="$dispatch('open-modal', { name: 'application-assign', url: @js(route('admin.job-applications.assign', $application)), label: @js($application->applicant_name), current: @js($application->assigned_to) })"
                                />
                            @endif

                            @if ($canDelete)
                                <x-ui.confirm
                                    :action="route('admin.job-applications.destroy', $application)"
                                    :title="'Delete the application of '.$application->applicant_name.'?'"
                                    message="It moves to the trash. The CV file is kept on the private disk so a restore loses nothing."
                                    confirm-label="Delete application"
                                >
                                    <x-slot:trigger>
                                        <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete the application of {{ $application->applicant_name }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state icon="document-text" title="No applications match those filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.job-applications.index', array_filter(['stage' => $stage === 'all' ? null : $stage, 'job' => $jobId]))">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @elseif ($stage !== 'all')
                    <x-ui.empty-state icon="document-text" :title="'No applications in '.($stages->firstWhere('value', $stage)['label'] ?? $stage)" :compact="true" />
                @else
                    <x-ui.empty-state icon="document-text" title="No applications yet" message="Share the public job page to start receiving applications.">
                        @if ($publicJobUrl)
                            <x-slot:action>
                                <div x-data="cmsCopy(@js(['value' => $publicJobUrl]))">
                                    <x-ui.button variant="secondary" icon="clipboard" x-on:click="copy()">
                                        <span x-text="copied ? 'Link copied' : 'Copy the job link'">Copy the job link</span>
                                    </x-ui.button>
                                </div>
                            </x-slot:action>
                        @elseif (Route::has('site.careers.index'))
                            <x-slot:action>
                                <div x-data="cmsCopy(@js(['value' => route('site.careers.index')]))">
                                    <x-ui.button variant="secondary" icon="clipboard" x-on:click="copy()">
                                        <span x-text="copied ? 'Link copied' : 'Copy the careers page link'">Copy the careers page link</span>
                                    </x-ui.button>
                                </div>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$applications" label="applications" />
            </x-slot:footer>
        </x-ui.table>
    </div>

    @include('admin.cms.partials.scripts')
    @include('admin.job-applications.partials.dialogs')
@endsection
