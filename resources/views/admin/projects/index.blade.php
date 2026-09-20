@extends('layouts.admin')

@section('title', 'Projects')

{{--
    Projects index — admin.projects.index (phase-06 §8.1).

    Controller variables (Admin\ProjectController@index):
      $projects    LengthAwarePaginator<App\Models\Project\Project> with client and projectManager
      $showMoney   bool  projects.view_financial. When false the money columns are NOT selected by the
                         controller, so they are absent from the response body — not hidden here (§9).
      $statuses / $priorities / $types / $clients / $managers  array<string|int, string>
      $filters     array<string, mixed>
      $sort        string   code | name | status | priority | deadline | progress_percent | created_at
      $direction   string   asc | desc

    Query: q (name, code), status, priority, type, client_id, manager_id, overdue, trashed, sort,
    direction, per_page, page.
--}}

@php
    use App\Enums\ProjectStatus;

    $user = auth()->user();
    $sort = $sort ?? 'created_at';
    $direction = $direction ?? 'desc';
    $isTrash = request()->boolean('trashed');
    $canCreate = (bool) $user?->can('projects.create');
    $columnCount = 7 + ($showMoney ? 1 : 0);
    $today = now()->toDateString();
@endphp

@section('header')
    <x-ui.page-header
        title="Projects"
        subtitle="Every piece of delivery work, what it is worth, who is running it and how far along it is."
        icon="folder">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.projects.create')">New project</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.filter-bar placeholder="Search a project name or code…" :reset="route('admin.projects.index')">
            <x-ui.form.select name="status" :options="$statuses" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            <x-ui.form.select name="priority" :options="$priorities" :selected="request('priority')" placeholder="Any priority" size="sm" aria-label="Filter by priority" />
            <x-ui.form.select name="type" :options="$types" :selected="request('type')" placeholder="Any type" size="sm" aria-label="Filter by engagement type" />
            <x-ui.form.select name="client_id" :options="$clients" :selected="request('client_id')" placeholder="Any client" size="sm" aria-label="Filter by client" />
            <x-ui.form.select name="manager_id" :options="$managers" :selected="request('manager_id')" placeholder="Any manager" size="sm" aria-label="Filter by project manager" />
            <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue')) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                Overdue only
            </label>
            @if ($user?->can('projects.restore'))
                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="trashed" value="1" @checked($isTrash) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                    Archived
                </label>
            @endif
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$projects->isEmpty()" :columns="$columnCount">
            <x-slot:head>
                <x-ui.th-sortable column="code" :sort="$sort" :direction="$direction">Project ID</x-ui.th-sortable>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Project</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Manager</th>
                <x-ui.th-sortable column="priority" :sort="$sort" :direction="$direction">Priority</x-ui.th-sortable>
                <x-ui.th-sortable column="deadline" :sort="$sort" :direction="$direction">Deadline</x-ui.th-sortable>
                <x-ui.th-sortable column="progress_percent" :sort="$sort" :direction="$direction">Progress</x-ui.th-sortable>
                @if ($showMoney)
                    <th scope="col" class="px-4 py-3 text-right">Value</th>
                @endif
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
            </x-slot:head>

            @foreach ($projects as $project)
                @php
                    $client = $project->relationLoaded('client') ? $project->client : null;
                    $manager = $project->relationLoaded('projectManager') ? $project->projectManager : null;
                    $trashed = $project->trashed();
                    $overdue = $project->deadline !== null
                        && $project->deadline->toDateString() < $today
                        && $project->status->isOpen();
                    $percent = (float) $project->progress_percent;
                @endphp
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->code }}</td>
                    <td class="min-w-[16rem]">
                        <a href="{{ route('admin.projects.show', $project) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $project->name }}</a>
                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $client?->company_name ?: $client?->name ?: '—' }}</span>
                        @if ($trashed)
                            <x-ui.badge color="rose" size="xs" variant="outline">Archived</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        @if ($manager)
                            <span class="flex items-center gap-2"><x-ui.avatar :src="$manager->avatar_url ?? null" :name="$manager->name" size="xs" /> <span class="max-w-[8rem] truncate text-sm">{{ $manager->name }}</span></span>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500">Unassigned</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">
                        <x-ui.badge :color="$project->priority->color()" size="xs">{{ $project->priority->label() }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap text-sm">
                        @if ($project->deadline)
                            <span @class(['text-rose-600 dark:text-rose-400 font-medium' => $overdue])>{{ app_date($project->deadline) }}</span>
                            @if ($overdue)
                                <span class="block text-xs text-rose-600 dark:text-rose-400">Overdue</span>
                            @endif
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
                        @endif
                    </td>
                    <td class="min-w-[9rem]">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-full max-w-[6rem] overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ min(100, max(0, $percent)) }}%"></div>
                            </div>
                            <span class="shrink-0 text-xs tabular-nums text-slate-600 dark:text-slate-300">{{ app_number($percent, 0) }}%</span>
                        </div>
                        @if ($project->progress_mode->value === 'manual')
                            <span class="text-[0.65rem] uppercase tracking-wide text-amber-600 dark:text-amber-400">Manual</span>
                        @endif
                    </td>
                    @if ($showMoney)
                        <td class="whitespace-nowrap text-right tabular-nums">{{ money((string) $project->net_value) }}</td>
                    @endif
                    <td class="whitespace-nowrap">
                        <x-ui.badge :color="$project->status->color()" size="xs">{{ $project->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state
                    icon="folder"
                    title="No projects yet"
                    description="A project is a piece of delivery work for one client — its value, its team, its milestones and its hours all hang off it.">
                    @if ($canCreate)
                        <x-ui.button icon="plus" :href="route('admin.projects.create')">New project</x-ui.button>
                    @endif
                </x-ui.empty-state>
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$projects" label="projects" />
    </div>
@endsection
