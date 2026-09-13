@extends('layouts.admin')

@section('title', 'Roles')

{{--
    Roles index (route admin.roles.index).

    `level` is a rank where **lower is stronger** (Super Admin is 1). System roles carry a lock:
    their name, panel and level are frozen and they can never be deleted — only their label,
    description and permission matrix can move.
--}}

@php
    use App\Enums\Ability;
    use App\Models\Role;
    use App\Models\User;

    $actor = auth()->user();

    $levelTone = static fn (int $level): string => match (true) {
        $level <= 5 => 'rose',
        $level <= 20 => 'amber',
        $level <= 40 => 'sky',
        default => 'slate',
    };

    // Does this actor get to see who holds a role? The link goes to the users index, so it needs
    // that screen's own permission — not roles.view.
    $canListUsers = (bool) $actor?->can('users.'.Ability::ViewAny->value);
@endphp

@section('header')
    <x-ui.page-header
        title="Roles"
        subtitle="What each kind of account may do, and which panel it reaches."
        icon="shield-check"
        :badge="app_number($roles->total()).' roles'"
        badge-color="slate"
    >
        <x-slot:actions>
            @can('permissions.'.Ability::ViewAny->value)
                <x-ui.button variant="secondary" icon="key" :href="route('admin.permissions.index')">
                    Permission catalogue
                </x-ui.button>
            @endcan

            @can('create', Role::class)
                <x-ui.button icon="plus" :href="route('admin.roles.create')">New role</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        `navigating` drives the table's skeleton rows. The filter bar submits itself on every
        select change and 400ms after the last keystroke, so the rows on screen are stale from
        that moment until the new page paints — the skeleton says so instead of leaving the old
        list looking current. Only GET submissions count: a POST from a confirm dialog is a
        different kind of navigation and has its own feedback.
    --}}
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >

        <x-ui.filter-bar placeholder="Search role name, label or description…" :reset="route('admin.roles.index')">
            <x-ui.form.select
                name="panel"
                :options="$panelOptions"
                :selected="request('panel')"
                placeholder="Any panel"
                size="sm"
                icon="rectangle-stack"
                aria-label="Filter by panel"
            />

            <x-ui.form.select
                name="system"
                :options="['system' => 'System roles', 'custom' => 'Custom roles']"
                :selected="request('system')"
                placeholder="System and custom"
                size="sm"
                icon="lock-closed"
                aria-label="Filter by protection"
            />
        </x-ui.filter-bar>

        <x-ui.table :is-empty="$roles->isEmpty()" loading="navigating" :loading-rows="8">
            <x-slot:head>
                <x-ui.th-sortable column="label" :sort="$sort" :direction="$direction">Role</x-ui.th-sortable>
                <x-ui.th-sortable column="panel" :sort="$sort" :direction="$direction">Panel</x-ui.th-sortable>
                <x-ui.th-sortable column="level" :sort="$sort" :direction="$direction" align="right" :numeric="true">
                    Level
                </x-ui.th-sortable>
                <x-ui.th-sortable column="users_count" :sort="$sort" :direction="$direction" default="desc" align="right" :numeric="true">
                    Users
                </x-ui.th-sortable>
                <x-ui.th-sortable column="permissions_count" :sort="$sort" :direction="$direction" default="desc" align="right" :numeric="true">
                    Permissions
                </x-ui.th-sortable>
                <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($roles as $role)
                <tr>
                    {{-- Identity --}}
                    <td class="px-4 py-3">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                <x-ui.icon :name="$role->is_system ? 'lock-closed' : 'shield-check'" class="h-4 w-4" />
                            </span>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @can('view', $role)
                                        <a
                                            href="{{ route('admin.roles.show', $role) }}"
                                            class="truncate font-medium text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                        >{{ $role->displayName() }}</a>
                                    @else
                                        <span class="truncate font-medium text-slate-900 dark:text-white">{{ $role->displayName() }}</span>
                                    @endcan

                                    @if ($role->is_system)
                                        <x-ui.badge
                                            color="amber"
                                            size="xs"
                                            icon="lock-closed"
                                            title="System role: the name, panel and level are frozen and it cannot be deleted."
                                        >Locked</x-ui.badge>
                                    @endif

                                    @if ($role->is_default)
                                        <x-ui.badge color="brand" size="xs" title="Given to new accounts of this panel by default.">
                                            Default
                                        </x-ui.badge>
                                    @endif
                                </div>

                                <p class="truncate font-mono text-2xs text-slate-400 dark:text-slate-500">{{ $role->name }}</p>

                                @if (filled($role->description))
                                    <p class="mt-0.5 max-w-md truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $role->description }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </td>

                    {{-- Panel --}}
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$role->panelType()->color()" size="sm">
                            {{ $role->panelType()->label() }}
                        </x-ui.badge>
                    </td>

                    {{-- Level --}}
                    <td class="px-4 py-3 text-right">
                        <x-ui.badge :color="$levelTone((int) $role->level)" size="sm" variant="outline">
                            {{ $role->level }}
                        </x-ui.badge>
                    </td>

                    {{-- Users --}}
                    <td class="px-4 py-3 text-right">
                        @if ($role->users_count > 0)
                            <a
                                href="{{ route('admin.users.index', ['role' => $role->id]) }}"
                                class="font-medium tabular-nums text-brand-600 hover:underline dark:text-brand-400"
                            >{{ app_number($role->users_count) }}</a>
                        @else
                            <span class="tabular-nums text-slate-400 dark:text-slate-600">0</span>
                        @endif
                    </td>

                    {{-- Permissions --}}
                    <td class="px-4 py-3 text-right">
                        <div class="inline-flex flex-col items-end">
                            <span class="font-medium tabular-nums text-slate-700 dark:text-slate-200">
                                {{ app_number($role->permissions_count) }}
                            </span>

                            @if ($totalPermissions > 0)
                                <span class="text-2xs text-slate-400 tabular-nums dark:text-slate-500">
                                    of {{ app_number($totalPermissions) }}
                                </span>
                            @endif
                        </div>
                    </td>

                    {{--
                        Actions — the same row-actions pattern as the users index (carryover T18):
                        the one action you reach for inline, everything else in a dropdown, the
                        destructive one behind x-ui.confirm.

                        The dropdown is rendered only when it would have something in it. A menu
                        that opens onto nothing reads as a defect (carryover T19), and with policy
                        rank rules ("you only manage roles weaker than your own") that is a real
                        case, not a theoretical one.
                    --}}
                    @php
                        $canViewRole = (bool) $actor?->can('view', $role);
                        $canEditRole = (bool) $actor?->can('update', $role);
                        $canDeleteRole = (bool) $actor?->can('delete', $role);
                        $canSeeMembers = $canListUsers && (int) $role->users_count > 0;
                        $hasMenu = $canViewRole || $canEditRole || $canSeeMembers;
                    @endphp

                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @if ($canEditRole)
                                <x-ui.icon-button
                                    icon="adjustments-horizontal"
                                    label="Edit permissions for {{ $role->displayName() }}"
                                    size="sm"
                                    :href="route('admin.roles.edit', $role)"
                                />
                            @endif

                            @if ($hasMenu)
                                <x-ui.dropdown label="Actions for {{ $role->displayName() }}" width="w-60">
                                    @if ($canViewRole)
                                        <x-ui.dropdown-item icon="eye" :href="route('admin.roles.show', $role)">
                                            View role
                                        </x-ui.dropdown-item>
                                    @endif

                                    @if ($canEditRole)
                                        <x-ui.dropdown-item icon="adjustments-horizontal" :href="route('admin.roles.edit', $role)">
                                            Edit permissions
                                        </x-ui.dropdown-item>
                                    @endif

                                    @if ($canSeeMembers)
                                        <x-ui.dropdown-item
                                            icon="users"
                                            :href="route('admin.users.index', ['role' => $role->id])"
                                            :badge="app_number($role->users_count)"
                                        >
                                            Accounts holding it
                                        </x-ui.dropdown-item>
                                    @endif
                                </x-ui.dropdown>
                            @endif

                            @if ($canDeleteRole)
                                <x-ui.confirm
                                    :action="route('admin.roles.destroy', $role)"
                                    method="DELETE"
                                    :title="'Delete the '.$role->displayName().' role?'"
                                    message="The role and its permission grants are removed. Accounts still holding it must be reassigned first — the delete is refused while anyone has it."
                                    confirm-label="Delete role"
                                    :require-text="$role->name"
                                >
                                    <x-slot:trigger>
                                        <x-ui.icon-button
                                            icon="trash"
                                            label="Delete {{ $role->displayName() }}"
                                            variant="danger"
                                            size="sm"
                                        />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @elseif ($role->is_system && $hasMenu)
                                <span
                                    class="inline-flex h-8 w-8 items-center justify-center text-slate-300 dark:text-slate-700"
                                    title="System roles cannot be deleted."
                                >
                                    <x-ui.icon name="lock-closed" class="h-4 w-4" />
                                </span>
                            @endif

                            @unless ($canEditRole || $hasMenu || $canDeleteRole)
                                {{--
                                    Nothing is available on this row: the role is at or above the
                                    actor's own rank. Say that, rather than offering a menu with
                                    no items in it (carryover T19).
                                --}}
                                <span class="inline-flex items-center gap-1.5 text-xs text-slate-400 dark:text-slate-600">
                                    <x-ui.icon name="lock-closed" class="h-3.5 w-3.5" />
                                    Outranks you
                                </span>
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state
                    icon="shield-check"
                    title="No roles match those filters"
                    message="Clear the filters, or create a role and give it a permission matrix."
                >
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="route('admin.roles.index')">Clear filters</x-ui.button>

                        @can('create', Role::class)
                            <x-ui.button icon="plus" :href="route('admin.roles.create')">New role</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-ui.empty-state>
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$roles" label="roles" />
            </x-slot:footer>
        </x-ui.table>

        <p class="px-1 text-xs text-slate-500 dark:text-slate-400">
            <strong class="font-semibold text-slate-600 dark:text-slate-300">Level</strong> is a rank:
            lower is more powerful. You can only edit, delete or hand out roles weaker than your own.
        </p>
    </div>
@endsection
