@extends('layouts.admin')

@section('title', 'Collaborators')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('collaborators.create');
    $canExport = (bool) $user?->can('collaborators.export');
    $showTrashed = request()->boolean('trashed');
@endphp

@section('header')
    <x-ui.page-header title="Collaborators" subtitle="The partners who bring the business students and projects." icon="user-group">
        <x-slot:actions>
            @if ($pendingCount)
                <x-ui.button variant="secondary" :href="route('admin.collaborators.pending')" icon="inbox-arrow-down">
                    {{ $pendingCount }} waiting
                </x-ui.button>
            @endif
            @if ($canExport)
                <x-ui.button variant="secondary" :href="route('admin.collaborators.export', ['format' => 'csv'] + request()->query())" icon="arrow-down-tray">Export</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button :href="route('admin.collaborators.create')" icon="plus">Add collaborator</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="min-w-56 flex-1">
                <x-ui.form.input name="q" label="Search" placeholder="Name, company, code or email" :value="$term" />
            </div>
            <div class="w-44">
                <x-ui.form.select name="status" label="Status" :options="$statuses" :selected="request('status')" placeholder="Every status" />
            </div>
            <div class="w-52">
                <x-ui.form.select name="collaboration_type" label="Type" :options="$types" :selected="request('collaboration_type')" placeholder="Every type" />
            </div>
            <div class="w-40">
                <x-ui.form.checkbox name="trashed" label="Removed only" :checked="$showTrashed" value="1" />
            </div>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.collaborators.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="$collaborators->total() . ' collaborator(s)'">
        <x-ui.table :is-empty="$collaborators->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Collaborator</th>
                <th class="px-4 py-3 text-left font-semibold">Type</th>
                <th class="px-4 py-3 text-left font-semibold">Contact</th>
                <th class="px-4 py-3 text-left font-semibold">Referral code</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3 text-right font-semibold">Skills</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($collaborators as $collaborator)
                <tr class="{{ $collaborator->trashed() ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.collaborators.show', $collaborator) }}"
                           class="block font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $collaborator->displayName() }}
                        </a>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ $collaborator->collaborator_code }}
                            @if ($collaborator->company_name) · {{ $collaborator->name }} @endif
                            @if ($collaborator->trashed()) · removed @endif
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $collaborator->collaboration_type->label() }}</td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $collaborator->email ?? '—' }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $collaborator->phone }}</span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $collaborator->referral_code }}</td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$collaborator->status->color()" size="xs">{{ $collaborator->status->label() }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $collaborator->skills_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <x-ui.icon-button icon="eye" size="sm" label="Open {{ $collaborator->displayName() }}"
                                              :href="route('admin.collaborators.show', $collaborator)" />
                            @if ($canEdit && ! $collaborator->trashed())
                                <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $collaborator->displayName() }}"
                                                  :href="route('admin.collaborators.edit', $collaborator)" />
                            @endif
                            {{-- Only a partner with no commission, payout or referral on record; the reason is
                                 required by the destroy route, which asks the policy again. --}}
                            @if ($canDelete && ! $collaborator->trashed() && ! isset($withMoney[$collaborator->id]))
                                <x-ui.confirm
                                    :action="route('admin.collaborators.destroy', $collaborator)"
                                    :title="'Remove '.$collaborator->displayName().'?'"
                                    message="The record and its history are kept — it only leaves the lists."
                                    confirm-label="Remove collaborator"
                                    id="remove-collaborator-{{ $collaborator->id }}"
                                >
                                    <x-slot:trigger>
                                        <x-ui.icon-button icon="trash" size="sm" variant="danger" label="Delete {{ $collaborator->displayName() }}" />
                                    </x-slot:trigger>
                                    <div class="mt-3">
                                        <label for="remove-collaborator-reason-{{ $collaborator->id }}"
                                               class="block text-xs font-medium text-slate-600 dark:text-slate-300">Why</label>
                                        <textarea id="remove-collaborator-reason-{{ $collaborator->id }}" name="reason"
                                                  form="remove-collaborator-{{ $collaborator->id }}" rows="2" required maxlength="255"
                                                  class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"></textarea>
                                    </div>
                                </x-ui.confirm>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="user-group" title="No collaborators yet"
                    description="A collaborator is anyone who refers students or projects, or works with you on one." />
            </x-slot:empty>
        </x-ui.table>

        @if ($collaborators->hasPages())
            <div class="mt-4">{{ $collaborators->links() }}</div>
        @endif
    </x-ui.card>
@endsection
