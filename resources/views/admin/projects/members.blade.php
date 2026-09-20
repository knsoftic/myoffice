@extends('layouts.admin')

@section('title', 'Team — ' . $project->code)

{{--
    The team tab — admin.projects.members.index (phase-06 §8.6).

    Removing somebody is a soft delete (INV-P11), so "who was on this project in March" stays answerable,
    and the controller reports back any open task still assigned to them rather than unassigning it
    quietly.
--}}

@section('header')
    <x-ui.page-header :title="'Team — ' . $project->name" :subtitle="$project->code" icon="user-group">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.projects.show', $project)">Back to project</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card title="On this project">
                @php $active = $members->whereNull('deleted_at'); @endphp

                @if ($active->isEmpty())
                    <x-ui.empty-state icon="user-group" title="Nobody on the team yet" description="Add the people who will deliver this project; only active members can be assigned work." />
                @else
                    <ul class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($active as $member)
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <x-ui.avatar :name="$member->user?->name ?? 'Collaborator'" size="sm" />
                                    <div class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-slate-900 dark:text-white">{{ $member->user?->name ?? ('Collaborator #' . $member->collaborator_id) }}</span>
                                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $member->user?->email }}</span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <x-ui.badge :color="$member->role->color()" size="xs">{{ $member->role->label() }}</x-ui.badge>
                                    @if ($canAssign)
                                        <form method="POST" action="{{ route('admin.projects.members.destroy', [$project, $member]) }}" onsubmit="return confirm('Remove this person from the team?');">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" size="sm" variant="ghost" icon="trash">Remove</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        @if ($canAssign)
            <x-ui.card title="Add somebody">
                <form method="POST" action="{{ route('admin.projects.members.store', $project) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.select name="user_id" label="Staff member" :options="$staff" placeholder="Choose somebody" />
                    <x-ui.form.select name="role" label="Role on this project" :options="$roles" :selected="old('role', 'member')" required help="A collaborator is never given manager or lead." />
                    <x-ui.form.input name="notes" label="Notes" maxlength="255" :value="old('notes')" />
                    <x-ui.button type="submit" class="w-full" icon="plus">Add to team</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
