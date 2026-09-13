@extends('layouts.admin')

@section('title', $user->name)

{{--
    User profile (route admin.users.show).

    Four questions this page answers: who is this, what can they do, when did they last sign in,
    and what has been changed on the account. The permission list is the *effective* set — what
    the roles actually grant — not a wish list.
--}}

@php
    use App\Enums\Ability;
    use App\Services\Core\PermissionMatrix;

    $isSuperAdmin = $user->isSuperAdmin();

    // `Gate::before` grants Super Admin every policy, so @can alone would offer an administrator
    // actions against their own account that UserService then refuses. Hide them instead.
    $isSelf = $user->is(auth()->user());
    $permissionTotal = collect($permissionGroups)->sum('total');
@endphp

@section('header')
    <x-ui.page-header
        :title="$user->name"
        :subtitle="$user->email"
        icon="user"
        :back="route('admin.users.index')"
        :badge="$user->status->label()"
        :badge-color="$user->status->color()"
    >
        <x-slot:actions>
            @can('update', $user)
                <x-ui.button icon="pencil" :href="route('admin.users.edit', $user)">Edit</x-ui.button>
            @endcan

            @if (! $isSelf)
            @can('delete', $user)
                <x-ui.confirm
                    :action="route('admin.users.destroy', $user)"
                    method="DELETE"
                    :title="'Delete '.$user->name.'?'"
                    message="Their sign-in is revoked immediately and every open session is closed. The account is soft deleted, so the records they created stay in place."
                    confirm-label="Delete user"
                    :require-text="$user->name"
                >
                    <x-slot:trigger>
                        <x-ui.button variant="secondary" icon="trash">Delete</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-5">

        {{-- ── The temporary password, shown exactly once ───────────────────────────── --}}
        @if (filled($temporaryPassword))
            <div
                x-data="{ copied: false }"
                class="rounded-xl bg-amber-50 p-4 ring-1 ring-inset ring-amber-200 sm:p-5 dark:bg-amber-500/10 dark:ring-amber-500/25"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
                            <x-ui.icon name="key" class="h-[1.125rem] w-[1.125rem]" />
                        </span>

                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                Temporary password for {{ $user->name }}
                            </h2>
                            <p class="mt-0.5 text-xs text-amber-800 dark:text-amber-200/80">
                                Shown once and never stored in readable form. They must change it at their next sign-in.
                            </p>
                            <code class="mt-2 inline-block select-all rounded-lg bg-white px-3 py-1.5 font-mono text-sm font-semibold tracking-wide text-slate-900 ring-1 ring-amber-200 dark:bg-slate-950 dark:text-white dark:ring-amber-500/30">{{ $temporaryPassword }}</code>
                        </div>
                    </div>

                    {{--
                        The value rides on a data attribute: Blade directives are not compiled
                        inside component attributes, so @js() in an Alpine expression here would
                        reach the browser verbatim.
                    --}}
                    <x-ui.button
                        variant="secondary"
                        size="sm"
                        icon="clipboard"
                        class="shrink-0"
                        data-password="{{ $temporaryPassword }}"
                        x-on:click="navigator.clipboard?.writeText($el.dataset.password).then(() => copied = true)"
                    >
                        <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </x-ui.button>
                </div>
            </div>
        @endif

        {{-- ── Super Admin notice ───────────────────────────────────────────────────── --}}
        @if ($isSuperAdmin)
            <div class="flex items-start gap-3 rounded-xl bg-brand-50 p-4 ring-1 ring-inset ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/25">
                <x-ui.icon name="shield-check" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400" />
                <p class="text-sm text-brand-900 dark:text-brand-100">
                    <strong class="font-semibold">Super Admin.</strong>
                    This account passes every permission check through the gate, so the list below is
                    informational — it can do anything except reach a module that has been switched off.
                </p>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

            {{-- ══ Side column: identity, roles, actions ═════════════════════════════ --}}
            <div class="space-y-5">

                {{-- Profile card --}}
                <x-ui.card>
                    <div class="flex flex-col items-center text-center">
                        <x-ui.avatar
                            :src="$user->avatar_url"
                            :name="$user->name"
                            size="2xl"
                            :status="$user->status->color()"
                        />

                        <h2 class="mt-3 text-base font-semibold tracking-tight text-slate-900 dark:text-white">
                            {{ $user->name }}
                        </h2>

                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>

                        <div class="mt-3 flex flex-wrap justify-center gap-1.5">
                            <x-ui.badge :color="$user->status->color()" :dot="true" size="sm">
                                {{ $user->status->label() }}
                            </x-ui.badge>

                            @if ($user->must_change_password)
                                <x-ui.badge color="amber" size="sm" icon="key">Must change password</x-ui.badge>
                            @endif

                            @if ($user->trashed())
                                <x-ui.badge color="rose" size="sm" icon="trash">Deleted</x-ui.badge>
                            @endif
                        </div>

                        @if (filled($user->status_reason))
                            <p class="mt-3 w-full rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-950/40 dark:text-slate-300">
                                {{ $user->status_reason }}
                            </p>
                        @endif
                    </div>

                    <dl class="mt-5 space-y-3 border-t border-slate-100 pt-4 text-xs dark:border-slate-800">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Phone</dt>
                            <dd class="text-right tabular-nums text-slate-700 dark:text-slate-200">
                                {{ $user->phone ?: '—' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">WhatsApp</dt>
                            <dd class="text-right tabular-nums text-slate-700 dark:text-slate-200">
                                {{ $user->whatsapp ?: '—' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Branch</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->branch?->name ?: '—' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Last sign-in</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Created</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->created_at ? app_date($user->created_at) : '—' }}
                                @if ($user->creator)
                                    <span class="block text-2xs text-slate-400 dark:text-slate-500">by {{ $user->creator->name }}</span>
                                @endif
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Last updated</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->updated_at?->diffForHumans() ?? '—' }}
                                @if ($user->editor)
                                    <span class="block text-2xs text-slate-400 dark:text-slate-500">by {{ $user->editor->name }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>

                {{-- Roles --}}
                <x-ui.card title="Roles" icon="shield-check" :subtitle="$user->roles->count().' assigned'">
                    @if ($user->roles->isEmpty())
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            No roles — this account cannot reach any panel.
                        </p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($user->roles as $role)
                                <li class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-950/40">
                                    <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        <x-ui.badge :color="$role->panelType()->color()" size="sm">
                                            {{ $role->displayName() }}
                                        </x-ui.badge>

                                        @if ($role->is_system)
                                            <x-ui.icon name="lock-closed" class="h-3 w-3 text-amber-500" />
                                        @endif
                                    </span>

                                    <span class="shrink-0 text-2xs text-slate-400 tabular-nums dark:text-slate-500">
                                        {{ $role->panelType()->label() }} · L{{ $role->level }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <x-slot:footer>
                        <span>Panels reachable: {{ $user->panels()->map(fn ($panel) => $panel->label())->join(', ') ?: 'none' }}</span>
                    </x-slot:footer>
                </x-ui.card>

                {{-- Access actions --}}
                @if (! $isSelf)
                @canany(['changeStatus', 'resetPassword'], $user)
                    <x-ui.card id="access-controls" title="Access controls" icon="adjustments-horizontal">
                        <div class="space-y-5">
                            @can('changeStatus', $user)
                                {{--
                                    PATCH, not PUT: `admin.users.status` is registered as
                                    Route::patch(), so a spoofed PUT answered 405 and the only
                                    legitimate way to change a status was unreachable from the UI.
                                --}}
                                <form method="POST" action="{{ route('admin.users.status', $user) }}" class="space-y-3">
                                    @csrf
                                    @method('PATCH')

                                    <x-ui.form.select
                                        name="status"
                                        label="Change status"
                                        :options="$statusOptions"
                                        :selected="$user->status->value"
                                        size="sm"
                                        required
                                    />

                                    <x-ui.form.textarea
                                        name="reason"
                                        label="Reason"
                                        :rows="2"
                                        :maxlength="255"
                                        placeholder="Required when access is taken away"
                                    />

                                    <x-ui.button type="submit" size="sm" variant="secondary" icon="check-badge" block>
                                        Update status
                                    </x-ui.button>
                                </form>
                            @endcan

                            @can('resetPassword', $user)
                                <div class="border-t border-slate-100 pt-4 dark:border-slate-800">
                                    <x-ui.confirm
                                        :action="route('admin.users.reset-password', $user)"
                                        method="POST"
                                        title="Issue a temporary password?"
                                        message="A random password is generated, every open session is closed, and the account must choose a new password at its next sign-in. The password is shown to you once."
                                        confirm-label="Issue temporary password"
                                        variant="warning"
                                        icon="key"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.button size="sm" variant="secondary" icon="key" block>
                                                Reset password
                                            </x-ui.button>
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                </div>
                            @endcan
                        </div>
                    </x-ui.card>
                @endcanany
                @endif
            </div>

            {{-- ══ Main column: permissions, logins, audit ═══════════════════════════ --}}
            <div class="space-y-5 lg:col-span-2">

                {{-- Effective permissions --}}
                <x-ui.card
                    title="Effective permissions"
                    :subtitle="$permissionTotal.' granted through '.$user->roles->count().' '.Str::plural('role', $user->roles->count())"
                    icon="key"
                    :padded="false"
                >
                    @if ($permissionGroups === [])
                        <x-ui.empty-state
                            icon="key"
                            title="No permissions"
                            :message="$isSuperAdmin
                                ? 'Super Admin needs none — the gate grants everything.'
                                : 'This account holds no abilities at all. Grant it a role with permissions.'"
                            :compact="true"
                        />
                    @else
                        <div class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($permissionGroups as $group)
                                <section class="p-4 sm:p-5">
                                    <div class="mb-3 flex flex-wrap items-center gap-2">
                                        <x-ui.badge :color="$group['color']" size="sm">{{ $group['label'] }}</x-ui.badge>
                                        <span class="text-2xs text-slate-400 tabular-nums dark:text-slate-500">
                                            {{ $group['total'] }} {{ Str::plural('permission', $group['total']) }}
                                            in {{ count($group['modules']) }} {{ Str::plural('module', count($group['modules'])) }}
                                        </span>
                                    </div>

                                    <div class="space-y-2.5">
                                        @foreach ($group['modules'] as $module)
                                            <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
                                                <p class="flex w-full shrink-0 items-center gap-1.5 text-xs font-medium text-slate-700 sm:w-48 dark:text-slate-200">
                                                    @if ($module['icon'])
                                                        <x-ui.icon :name="$module['icon']" class="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                                    @endif
                                                    <span class="truncate">{{ $module['name'] }}</span>

                                                    @unless ($module['is_enabled'])
                                                        <span title="Module disabled — these abilities are denied to everyone">
                                                            <x-ui.icon name="eye-slash" class="h-3 w-3 shrink-0 text-rose-500" />
                                                        </span>
                                                    @endunless
                                                </p>

                                                {{--
                                                    Flush-left and on one line on purpose: a Super
                                                    Admin holds every permission, so this loop runs
                                                    ~800 times and the surrounding indentation alone
                                                    would add a few hundred KB to the response.
                                                --}}
                                                <div class="flex flex-wrap gap-1">
@foreach ($module['cells'] as $ability => $permission)
<x-ui.badge :color="PermissionMatrix::abilityColor((string) $ability)" size="xs" :title="$permission->name">{{ PermissionMatrix::abilityLabel((string) $ability) }}</x-ui.badge>
@endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @endif

                    <x-slot:footer>
                        @can('roles.'.Ability::ViewAny->value)
                            <a href="{{ route('admin.roles.index') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                                Change what a role grants on the roles screen
                            </a>
                        @else
                            <span>Permissions come from the roles above.</span>
                        @endcan
                    </x-slot:footer>
                </x-ui.card>

                {{--
                    Recent sign-ins — log data, not profile data.

                    `users.view` gets you the directory; IP addresses, devices and failed attempts
                    need `login_history.view_logs`, the same ability /admin/login-history demands.
                    The controller hands over an empty collection without it, and the whole card
                    disappears rather than teasing an empty table.
                --}}
                @if ($canSeeLogins)
                <x-ui.card title="Recent sign-ins" subtitle="The last 10 authentication events." icon="finger-print" :padded="false">
                    <x-ui.table :is-empty="$logins->isEmpty()" :dense="true" :flush="true">
                        <x-slot:head>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Result</th>
                            <th class="px-4 py-3">Device</th>
                            <th class="px-4 py-3">IP</th>
                        </x-slot:head>

                        @foreach ($logins as $login)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <p class="text-xs text-slate-700 dark:text-slate-200">
                                        {{ ($login->logged_in_at ?? $login->created_at) ? app_datetime($login->logged_in_at ?? $login->created_at) : '—' }}
                                    </p>
                                    <p class="text-2xs text-slate-400 dark:text-slate-500">
                                        {{ ($login->logged_in_at ?? $login->created_at)?->diffForHumans() }}
                                    </p>
                                </td>

                                <td class="px-4 py-2.5">
                                    <x-ui.badge :color="$login->status->color()" size="xs" :dot="true">
                                        {{ $login->status->label() }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-4 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                                    {{ $login->deviceLabel() }}
                                </td>

                                <td class="px-4 py-2.5 text-xs tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ $login->ip_address ?: '—' }}
                                </td>
                            </tr>
                        @endforeach

                        <x-slot:empty>
                            <x-ui.empty-state
                                icon="finger-print"
                                title="No sign-ins recorded"
                                message="Nothing yet — the first successful or failed attempt will appear here."
                                :compact="true"
                            />
                        </x-slot:empty>
                    </x-ui.table>

                    @if (Route::has('admin.login-history.index'))
                        <x-slot:footer>
                            <a
                                href="{{ route('admin.login-history.index', ['user_id' => $user->id]) }}"
                                class="font-medium text-brand-600 hover:underline dark:text-brand-400"
                            >See the full login history</a>
                        </x-slot:footer>
                    @endif
                </x-ui.card>
                @endif

                {{-- Audit trail for this account — gated on activity_log.view_logs, as above. --}}
                @if ($canSeeActivity)
                <x-ui.card
                    title="Account audit trail"
                    subtitle="The last 10 changes made to this account, with who made them."
                    icon="history"
                    :padded="false"
                >
                    @if ($activities->isEmpty())
                        <x-ui.empty-state
                            icon="history"
                            title="No changes recorded"
                            message="Edits to this account — including role and status changes — will be listed here."
                            :compact="true"
                        />
                    @else
                        <ol class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($activities as $entry)
                                <li class="flex gap-3 p-4">
                                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                        <x-ui.icon
                                            :name="match ($entry->event) {
                                                'created' => 'plus',
                                                'deleted' => 'trash',
                                                default => 'pencil',
                                            }"
                                            class="h-3.5 w-3.5"
                                        />
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-slate-800 dark:text-slate-100">{{ $entry->description }}</p>

                                        <p class="mt-0.5 text-2xs text-slate-500 dark:text-slate-400">
                                            {{ app_datetime($entry->created_at) }}
                                            · by {{ $entry->causer?->name ?? 'system' }}
                                            @if (filled($entry->ip_address))
                                                · {{ $entry->ip_address }}
                                            @endif
                                        </p>

                                        @if (filled($entry->reason))
                                            <p class="mt-1 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600 dark:bg-slate-950/40 dark:text-slate-300">
                                                {{ $entry->reason }}
                                            </p>
                                        @endif

                                        @php $changes = $entry->changedValues(); @endphp

                                        @if ($changes !== [])
                                            <ul class="mt-1.5 space-y-0.5 text-2xs text-slate-500 dark:text-slate-400">
                                                @foreach (array_slice($changes, 0, 6, true) as $field => $change)
                                                    <li class="truncate">
                                                        <span class="font-medium text-slate-600 dark:text-slate-300">{{ Str::headline($field) }}</span>:
                                                        <span class="line-through opacity-70">{{ Str::limit(is_scalar($change['old']) ? (string) $change['old'] : json_encode($change['old']), 40) ?: '—' }}</span>
                                                        →
                                                        <span>{{ Str::limit(is_scalar($change['new']) ? (string) $change['new'] : json_encode($change['new']), 40) ?: '—' }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    @if (Route::has('admin.activity-log.index'))
                        <x-slot:footer>
                            <a
                                href="{{ route('admin.activity-log.index', ['module' => 'users', 'subject_type' => $user->getMorphClass()]) }}"
                                class="font-medium text-brand-600 hover:underline dark:text-brand-400"
                            >Open the full activity log</a>
                        </x-slot:footer>
                    @endif
                </x-ui.card>
                @endif
            </div>
        </div>
    </div>
@endsection
