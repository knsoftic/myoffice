@extends('layouts.admin')

@section('title', $role->displayName())

{{--
    Role overview (route admin.roles.show) — read-only.

    The same grid the editor renders, with ticks instead of checkboxes, so "what does this role
    actually grant?" can be answered and shared without anyone holding the edit permission.
--}}

@php
    $selectedNames = collect($permissionGroups)
        ->flatMap(fn (array $group) => collect($group['modules'])->flatMap(fn (array $module) => $module['names']))
        ->values()
        ->all();
@endphp

@section('header')
    <x-ui.page-header
        :title="$role->displayName()"
        :subtitle="$role->description ?: 'No description yet.'"
        :icon="$role->is_system ? 'lock-closed' : 'shield-check'"
        :back="route('admin.roles.index')"
        :badge="$role->panelType()->label().' panel'"
        :badge-color="$role->panelType()->color()"
    >
        <x-slot:actions>
            @can('update', $role)
                <x-ui.button icon="adjustments-horizontal" :href="route('admin.roles.edit', $role)">
                    Edit permissions
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-5">

        {{-- ── Key figures ──────────────────────────────────────────────────────────── --}}
        <div class="card-grid">
            <x-ui.stat-card
                label="Permissions"
                :value="app_number($role->permissions_count)"
                icon="key"
                color="brand"
            />

            <x-ui.stat-card
                label="Accounts holding it"
                :value="app_number($role->users_count)"
                icon="users"
                color="sky"
                :href="$role->users_count > 0 ? route('admin.users.index', ['role' => $role->id]) : null"
            />

            <x-ui.stat-card
                label="Level"
                :value="$role->level"
                icon="arrow-trending-up"
                color="amber"
                delta-label="lower is stronger"
            />

            <x-ui.stat-card
                label="Protection"
                :value="$role->is_system ? 'System' : 'Custom'"
                :icon="$role->is_system ? 'lock-closed' : 'lock-open'"
                :color="$role->is_system ? 'rose' : 'slate'"
                :delta-label="$role->is_system ? 'name, panel and level are frozen' : 'fully editable'"
            />
        </div>

        {{-- ── Identity ─────────────────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <x-ui.card title="Details" icon="information-circle">
                <dl class="space-y-3 text-xs">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Name</dt>
                        <dd class="text-right font-mono text-slate-700 dark:text-slate-200">{{ $role->name }}</dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Label</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-200">{{ $role->label ?: '—' }}</dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Panel</dt>
                        <dd class="text-right">
                            <x-ui.badge :color="$role->panelType()->color()" size="xs">
                                {{ $role->panelType()->label() }}
                            </x-ui.badge>
                        </dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Default for new accounts</dt>
                        <dd class="text-right text-slate-700 dark:text-slate-200">{{ $role->is_default ? 'Yes' : 'No' }}</dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Guard</dt>
                        <dd class="text-right font-mono text-slate-700 dark:text-slate-200">{{ $role->guard_name }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Members --}}
            <x-ui.card
                title="Members"
                :subtitle="$role->users_count > 25 ? 'First 25 of '.app_number($role->users_count) : null"
                icon="users"
                class="lg:col-span-2"
            >
                {{--
                    The controller already dropped every member this actor is not allowed to see
                    (UserPolicy::view — the same rule that decides /admin/users/{id}), so `roles.view`
                    on its own no longer hands out a list of names and e-mail addresses. The count in
                    the card header is the role's real total, exactly as the index shows it.
                --}}
                @if ($members->isEmpty())
                    <x-ui.empty-state
                        icon="users"
                        title="{{ ($hiddenMembers ?? 0) > 0 ? 'Members hidden' : 'Nobody holds this role' }}"
                        message="{{ ($hiddenMembers ?? 0) > 0
                            ? 'This role is assigned, but the accounts holding it are outside what you may see.'
                            : 'Assign it from a user\'s edit screen.' }}"
                        :compact="true"
                    />
                @else
                    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($members as $member)
                            <li class="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
                                <x-ui.avatar :src="$member->avatar_url" :name="$member->name" size="sm" />

                                <span class="min-w-0">
                                    <span class="block truncate text-xs font-medium text-slate-800 dark:text-slate-100">
                                        @can('view', $member)
                                            <a
                                                href="{{ route('admin.users.show', $member) }}"
                                                class="hover:text-brand-600 hover:underline dark:hover:text-brand-400"
                                            >{{ $member->name }}</a>
                                        @else
                                            {{ $member->name }}
                                        @endcan
                                    </span>

                                    <span class="block truncate text-2xs text-slate-400 dark:text-slate-500">
                                        {{ $member->email }}
                                    </span>
                                </span>

                                <x-ui.badge :color="$member->status->color()" size="xs" :dot="true" class="ml-auto shrink-0">
                                    {{ $member->status->label() }}
                                </x-ui.badge>
                            </li>
                        @endforeach
                    </ul>

                    @if (($hiddenMembers ?? 0) > 0)
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                            {{ $hiddenMembers }} more {{ Str::plural('account', $hiddenMembers) }} on this page
                            {{ $hiddenMembers === 1 ? 'is' : 'are' }} outside what you may see.
                        </p>
                    @endif
                @endif
            </x-ui.card>
        </div>

        {{-- ── The matrix, read only ────────────────────────────────────────────────── --}}
        <x-ui.section-heading
            title="What this role grants"
            :subtitle="app_number($role->permissions_count).' permissions, grouped the way the editor groups them.'"
            icon="key"
            :divider="true"
        />

        @include('admin.roles.partials.matrix', [
            'permissionGroups' => $permissionGroups,
            'selectedNames' => $selectedNames,
            'readOnly' => true,
        ])
    </div>
@endsection
