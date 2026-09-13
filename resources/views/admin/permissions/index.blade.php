@extends('layouts.admin')

@section('title', 'Permissions')

{{--
    Permission catalogue (route admin.permissions.index) — read only.

    Permissions are generated from App\Support\PermissionRegistry and written by the seeder, so
    there is nothing to create or edit here. This screen answers two questions: what abilities
    exist, and which roles currently hold each one. Grants are made on the role editor.

    Why this screen has no sortable headers, unlike every other index: it is not a table. The rows
    are grouped module group → module → ability and ordered by `Permission::ordered()`, which is
    the registry's own order — the same order as the role editor's matrix, so the two screens read
    the same way round. Sorting the page by name or by holder count would break that pairing while
    only ever sorting the 60 rows of the current page. The filters (group, module, ability) and the
    search are the way to narrow it; `ability` is effectively the "sort by ability" a table header
    would have given.
--}}

@php
    use App\Enums\Ability;
    use App\Services\Core\PermissionMatrix;
@endphp

@section('header')
    <x-ui.page-header
        title="Permissions"
        subtitle="Every ability the system knows about, grouped by module, and who holds it."
        icon="key"
        :badge="app_number($stats['permissions']).' permissions'"
        badge-color="slate"
    >
        <x-slot:actions>
            @can('roles.'.Ability::ViewAny->value)
                <x-ui.button variant="secondary" icon="shield-check" :href="route('admin.roles.index')">
                    Roles
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        The catalogue is rendered as cards rather than one table, so the skeleton here is the card
        variant instead of x-ui.table's `loading` prop — same contract, same component: while a
        filter change is in flight the 787-row catalogue stops pretending to be current. GET
        submissions only, exactly as on the other index screens.
    --}}
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >

        {{-- ── Figures ──────────────────────────────────────────────────────────────── --}}
        <div class="card-grid">
            <x-ui.stat-card
                label="Permissions"
                :value="app_number($stats['permissions'])"
                icon="key"
                color="brand"
            />

            <x-ui.stat-card
                label="Modules covered"
                :value="app_number($stats['modules'])"
                icon="puzzle-piece"
                color="sky"
            />

            <x-ui.stat-card
                label="Roles"
                :value="app_number($stats['roles'])"
                icon="shield-check"
                color="violet"
                :href="Route::has('admin.roles.index') ? route('admin.roles.index') : null"
            />

            <x-ui.stat-card
                label="Matching the filters"
                :value="app_number($stats['matching'])"
                icon="funnel"
                color="slate"
            />
        </div>

        {{-- ── Filters ──────────────────────────────────────────────────────────────── --}}
        <x-ui.filter-bar
            placeholder="Search permission name, label or module…"
            :reset="route('admin.permissions.index')"
        >
            <x-ui.form.select
                name="group"
                :options="$groupOptions"
                :selected="request('group')"
                placeholder="Any group"
                size="sm"
                icon="rectangle-stack"
                aria-label="Filter by module group"
            />

            <x-ui.form.select
                name="module"
                :options="$moduleOptions"
                :selected="request('module')"
                placeholder="Any module"
                size="sm"
                icon="puzzle-piece"
                aria-label="Filter by module"
            />

            <x-ui.form.select
                name="ability"
                :options="$abilityOptions"
                :selected="request('ability')"
                placeholder="Any ability"
                size="sm"
                icon="key"
                aria-label="Filter by ability"
            />
        </x-ui.filter-bar>

        {{-- ── Loading ──────────────────────────────────────────────────────────────── --}}
        <div x-show="navigating" x-cloak aria-busy="true" class="space-y-4">
            <x-ui.skeleton variant="card" :count="2" />
        </div>

        {{-- ── Grouped catalogue ────────────────────────────────────────────────────── --}}
        <div x-show="! navigating" class="space-y-4">
        @if ($permissionGroups === [])
            <x-ui.card>
                <x-ui.empty-state
                    icon="key"
                    title="No permissions match those filters"
                    message="Clear the filters to see the whole catalogue. If it is empty everywhere, the permission seeder has not run yet."
                >
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="route('admin.permissions.index')">Clear filters</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            @foreach ($permissionGroups as $group)
                <x-ui.card
                    :padded="false"
                    :title="$group['label']"
                    :subtitle="count($group['modules']).' '.Str::plural('module', count($group['modules'])).' · '.$group['total'].' '.Str::plural('permission', $group['total']).' on this page'"
                >
                    <x-slot:actions>
                        <x-ui.badge :color="$group['color']" size="sm">{{ $group['label'] }}</x-ui.badge>
                    </x-slot:actions>

                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($group['modules'] as $module)
                            <section class="p-4 sm:p-5">
                                {{-- Module header --}}
                                <div class="mb-3 flex flex-wrap items-center gap-2">
                                    @if ($module['icon'])
                                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                            <x-ui.icon :name="$module['icon']" class="h-3.5 w-3.5" />
                                        </span>
                                    @endif

                                    <h3 class="text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
                                        {{ $module['name'] }}
                                    </h3>

                                    <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-2xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                        {{ $module['slug'] }}
                                    </code>

                                    @if ($module['is_core'])
                                        <x-ui.badge color="amber" size="xs" icon="lock-closed">Core</x-ui.badge>
                                    @elseif (! $module['is_enabled'])
                                        <x-ui.badge color="rose" size="xs" icon="eye-slash" title="Module disabled — these abilities are denied to everyone, Super Admin included.">
                                            Disabled
                                        </x-ui.badge>
                                    @endif

                                    <span class="text-2xs text-slate-400 tabular-nums dark:text-slate-500">
                                        {{ $module['total'] }} {{ Str::plural('ability', $module['total']) }}
                                    </span>
                                </div>

                                {{-- Abilities --}}
                                <ul class="space-y-2">
                                    @foreach ($module['cells'] as $ability => $permission)
                                        <li class="flex flex-col gap-1.5 rounded-lg bg-slate-50/70 px-3 py-2 sm:flex-row sm:items-center sm:gap-3 dark:bg-slate-950/30">
                                            <div class="flex min-w-0 shrink-0 items-center gap-2 sm:w-72">
                                                <x-ui.badge
                                                    :color="PermissionMatrix::abilityColor((string) $ability)"
                                                    size="xs"
                                                >{{ PermissionMatrix::abilityLabel((string) $ability) }}</x-ui.badge>

                                                <code class="truncate font-mono text-2xs text-slate-500 dark:text-slate-400">
                                                    {{ $permission->name }}
                                                </code>
                                            </div>

                                            <p class="min-w-0 flex-1 truncate text-xs text-slate-600 dark:text-slate-300">
                                                {{ $permission->label ?: $permission->displayName() }}
                                            </p>

                                            {{-- Which roles hold it (eager loaded) --}}
                                            <div class="flex min-w-0 flex-wrap items-center gap-1 sm:justify-end">
                                                @if ($permission->roles->isEmpty())
                                                    <span class="text-2xs italic text-slate-400 dark:text-slate-600">
                                                        held by no role
                                                    </span>
                                                @else
                                                    @foreach ($permission->roles as $role)
                                                        @can('view', $role)
                                                            <a href="{{ route('admin.roles.show', $role) }}">
                                                                <x-ui.badge :color="$role->panelType()->color()" size="xs">
                                                                    {{ $role->displayName() }}
                                                                </x-ui.badge>
                                                            </a>
                                                        @else
                                                            <x-ui.badge :color="$role->panelType()->color()" size="xs">
                                                                {{ $role->displayName() }}
                                                            </x-ui.badge>
                                                        @endcan
                                                    @endforeach
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach

            <x-ui.card :compact="true">
                <x-ui.pagination-summary :paginator="$permissions" label="permissions" />
            </x-ui.card>
        @endif
        </div>{{-- /catalogue --}}

        <p class="px-1 text-xs text-slate-500 dark:text-slate-400">
            A permission is always <code class="font-mono">module.ability</code>. The catalogue is generated from
            <code class="font-mono">App\Support\PermissionRegistry</code>, so it changes in code and is applied by the
            seeder — never typed in by hand.
        </p>
    </div>
@endsection
