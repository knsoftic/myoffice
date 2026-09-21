@extends('layouts.panel')

@section('title', 'Projects')

@section('header')
    <x-ui.page-header title="Projects you referred"
                      :subtitle="$referredCount . ' ' . \Illuminate\Support\Str::plural('project', $referredCount) . ' attributed to you'"
                      icon="folder" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-3">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or code" />
            <x-ui.form.input name="status" label="Status" :value="request('status')" placeholder="Any status" />

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('collaborator.projects.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="$projects->total() . ' ' . \Illuminate\Support\Str::plural('project', $projects->total())">
        <x-ui.table :is-empty="$projects->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Project</th>
                @if ($showClient)
                    <th class="px-4 py-3 text-left font-semibold">Client</th>
                @endif
                @if ($showValue)
                    <th class="px-4 py-3 text-right font-semibold">Value</th>
                @endif
                @if ($showCommission)
                    <th class="px-4 py-3 text-right font-semibold">You earned</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($projects as $project)
                <tr>
                    <td class="px-4 py-3">
                        <span class="block font-medium text-slate-900 dark:text-white">{{ $project->name }}</span>
                        <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->code }}</span>
                    </td>

                    @if ($showClient)
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                            {{ $project->client?->company_name ?? $project->client?->contact_person ?? '—' }}
                        </td>
                    @endif

                    @if ($showValue)
                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            {{ money($project->net_value) }}
                        </td>
                    @endif

                    @if ($showCommission)
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($earnedByProject[$project->id] ?? '0.00') }}
                        </td>
                    @endif

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$project->status->color()" size="xs">{{ $project->status->label() }}</x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="folder"
                                  title="No projects yet"
                                  message="A project appears here once it is attributed to you. Commission on it follows the money the client actually pays." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$projects" label="projects" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>

    @if ($showCommission)
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            “You earned” is commission already posted against each project, net of anything returned. It follows
            payments actually received — not the project's value.
        </p>
    @endif
@endsection
