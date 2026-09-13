@extends('layouts.admin')

@section('title', 'Users')

{{--
    Users index (route admin.users.index).

    Row actions are gated per row by UserPolicy (which adds the rank rule: you only ever see
    actions for accounts weaker than your own best role), and again on the server. Hiding a
    button is not security — it is just tidiness.

    The rank rule is strict, so a row can legitimately have **no** actions at all: a peer (same
    role level) and anyone stronger than you. That used to render as a row-actions button opening
    an empty menu, which reads as a bug. Such a row now shows a lock and says why instead (T19).

    "Change status" and "Reset password" share one dialog each rather than rendering one per
    row: the clicked row writes its target into the page-level Alpine scope and the dialog
    binds its form action to it.
--}}

@php
    use App\Enums\Ability;
    use App\Models\User;

    $actor = auth()->user();

    // Permission strings are always {module}.{ability} — never a typed literal.
    $canChangeStatus = (bool) $actor?->can('users.'.Ability::ChangeStatus->value);
    $canResetPassword = (bool) $actor?->can('users.'.Ability::Edit->value);

    /*
     * Does the actor hold *any* per-row ability? `users.view_any` alone buys this list and nothing
     * on it, and that is a permission story, not a ranking story — so a row with no actions must not
     * be labelled "outranks yours" unless rank really is the reason (T19). Asked once per page: the
     * abilities are the same for every row, only the rank changes.
     */
    $canActOnRows = $canChangeStatus
        || $canResetPassword
        || (bool) $actor?->can('users.'.Ability::View->value)
        || (bool) $actor?->can('users.'.Ability::Delete->value);
@endphp

@section('header')
    <x-ui.page-header
        title="Users"
        subtitle="Everyone with an account, the roles they hold and the state of their access."
        icon="users"
        :badge="app_number($counts['total']).' accounts'"
        badge-color="slate"
    >
        @can('create', User::class)
            <x-slot:actions>
                <x-ui.button icon="user-plus" :href="route('admin.users.create')">New user</x-ui.button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>
@endsection

@section('content')
    {{-- One Alpine scope for the page so the shared dialogs can read the clicked row. --}}
    <div
        x-data="{ target: { url: '', name: '', status: '' } }"
        class="space-y-4"
    >
        {{-- ── Status overview ──────────────────────────────────────────────────────── --}}
        <div class="card-grid">
            <x-ui.stat-card
                label="All accounts"
                :value="app_number($counts['total'])"
                icon="users"
                color="brand"
                :href="route('admin.users.index')"
            />

            @foreach (App\Enums\UserStatus::cases() as $case)
                <x-ui.stat-card
                    :label="$case->label()"
                    :value="app_number($counts[$case->value] ?? 0)"
                    :icon="match ($case->value) {
                        'active' => 'check-circle',
                        'suspended' => 'lock-closed',
                        'pending' => 'clock',
                        default => 'minus',
                    }"
                    :color="match ($case->color()) {
                        'emerald' => 'emerald',
                        'rose' => 'rose',
                        'amber' => 'amber',
                        default => 'slate',
                    }"
                    :href="route('admin.users.index', ['status' => $case->value])"
                />
            @endforeach
        </div>

        {{-- ── Search and filters ───────────────────────────────────────────────────── --}}
        <x-ui.filter-bar
            placeholder="Search name, email or phone…"
            :reset="route('admin.users.index')"
        >
            <x-ui.form.select
                name="role"
                :options="$roleOptions"
                :selected="$filters['role']"
                placeholder="Any role"
                size="sm"
                icon="shield-check"
                aria-label="Filter by role"
            />

            <x-ui.form.select
                name="status"
                :options="$statusOptions"
                :selected="$filters['status']"
                placeholder="Any status"
                size="sm"
                icon="check-circle"
                aria-label="Filter by status"
            />

            @if ($branchOptions !== [])
                <x-ui.form.select
                    name="branch"
                    :options="$branchOptions"
                    :selected="$filters['branch']"
                    placeholder="Any branch"
                    size="sm"
                    icon="building-office"
                    aria-label="Filter by branch"
                />
            @endif
        </x-ui.filter-bar>

        {{-- ── Table ────────────────────────────────────────────────────────────────── --}}
        <x-ui.table :is-empty="$users->isEmpty()">
            <x-slot:head>
                <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">User</x-ui.th-sortable>
                <th class="px-4 py-3">Contact</th>
                <th class="px-4 py-3">Roles</th>
                <th class="px-4 py-3">Branch</th>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <x-ui.th-sortable column="last_login_at" :sort="$sort" :direction="$direction" default="desc">
                    Last login
                </x-ui.th-sortable>
                <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($users as $user)
                @php
                    /*
                     * Every row action asked once, so the cell can tell "you may do nothing here"
                     * from "here is a menu". `$actor->can()` is the same question `@can` asks.
                     * The four guarded-by-!$isSelf actions are the ones UserService refuses on your
                     * own account whatever the policy says, because `Gate::before` waves a Super
                     * Admin past the policy.
                     */
                    $isSelf = $user->is($actor);

                    $rowActions = [
                        'view' => (bool) $actor?->can('view', $user),
                        'update' => (bool) $actor?->can('update', $user),
                        'changeStatus' => ! $isSelf && (bool) $actor?->can('changeStatus', $user),
                        'resetPassword' => ! $isSelf && (bool) $actor?->can('resetPassword', $user),
                        'delete' => ! $isSelf && (bool) $actor?->can('delete', $user),
                    ];

                    $hasRowActions = in_array(true, $rowActions, true);

                    // The dropdown holds three of the five actions; Edit and Delete sit beside it.
                    // Rendering it for a row whose only action is Edit would re-create the empty
                    // menu in a smaller way.
                    $hasMenuActions = $rowActions['view'] || $rowActions['changeStatus'] || $rowActions['resetPassword'];

                    /*
                     * actions   — there is something to do
                     * outranked — the abilities are held, so rank is the only thing left refusing
                     * none      — no per-row ability at all, or it is your own row (where the
                     *             self-targeting actions are refused by design, not by rank)
                     */
                    $rowState = match (true) {
                        $hasRowActions => 'actions',
                        $isSelf, ! $canActOnRows => 'none',
                        default => 'outranked',
                    };
                @endphp

                <tr>
                    {{-- Identity --}}
                    <td class="px-4 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-ui.avatar :src="$user->avatar_url" :name="$user->name" size="md" />

                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    @can('view', $user)
                                        <a
                                            href="{{ route('admin.users.show', $user) }}"
                                            class="truncate font-medium text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                        >{{ $user->name }}</a>
                                    @else
                                        <span class="truncate font-medium text-slate-900 dark:text-white">{{ $user->name }}</span>
                                    @endcan

                                    @if ($user->is(auth()->user()))
                                        <x-ui.badge color="brand" size="xs">You</x-ui.badge>
                                    @endif

                                    @if ($user->must_change_password)
                                        <span title="Must change password at next sign-in">
                                            <x-ui.icon name="key" class="h-3.5 w-3.5 text-amber-500" />
                                        </span>
                                    @endif
                                </div>

                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                            </div>
                        </div>
                    </td>

                    {{-- Contact --}}
                    <td class="px-4 py-3">
                        @if (filled($user->phone) || filled($user->whatsapp))
                            <div class="space-y-0.5 text-xs text-slate-600 dark:text-slate-300">
                                @if (filled($user->phone))
                                    <p class="flex items-center gap-1.5">
                                        <x-ui.icon name="phone" class="h-3.5 w-3.5 text-slate-400" />
                                        <span class="tabular-nums">{{ $user->phone }}</span>
                                    </p>
                                @endif

                                @if (filled($user->whatsapp) && $user->whatsapp !== $user->phone)
                                    <p class="flex items-center gap-1.5">
                                        <x-ui.icon name="whatsapp" class="h-3.5 w-3.5 text-emerald-500" />
                                        <span class="tabular-nums">{{ $user->whatsapp }}</span>
                                    </p>
                                @endif
                            </div>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-600">—</span>
                        @endif
                    </td>

                    {{-- Roles (eager loaded) --}}
                    <td class="px-4 py-3">
                        @if ($user->roles->isEmpty())
                            <x-ui.badge color="rose" size="sm" icon="exclamation-triangle">No role</x-ui.badge>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($user->roles as $role)
                                    <x-ui.badge :color="$role->panelType()->color()" size="sm">
                                        {{ $role->displayName() }}
                                    </x-ui.badge>
                                @endforeach
                            </div>
                        @endif
                    </td>

                    {{-- Branch --}}
                    <td class="px-4 py-3">
                        @if ($user->branch)
                            <span class="text-xs text-slate-600 dark:text-slate-300">{{ $user->branch->name }}</span>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-600">—</span>
                        @endif
                    </td>

                    {{-- Status --}}
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$user->status->color()" :dot="true" size="sm">
                            {{ $user->status->label() }}
                        </x-ui.badge>

                        @if (filled($user->status_reason))
                            <p class="mt-1 max-w-[14rem] truncate text-2xs text-slate-500 dark:text-slate-400" title="{{ $user->status_reason }}">
                                {{ $user->status_reason }}
                            </p>
                        @endif
                    </td>

                    {{-- Last login --}}
                    <td class="px-4 py-3">
                        @if ($user->last_login_at)
                            <p class="text-xs text-slate-700 dark:text-slate-200">
                                <time datetime="{{ $user->last_login_at->toIso8601String() }}" title="{{ app_datetime($user->last_login_at) }}">{{ $user->last_login_at->diffForHumans() }}</time>
                            </p>
                            <p class="text-2xs text-slate-400 tabular-nums dark:text-slate-500">
                                {{ app_datetime($user->last_login_at) }}
                                @if (filled($user->last_login_ip))
                                    · {{ $user->last_login_ip }}
                                @endif
                            </p>
                        @else
                            <span class="text-xs text-slate-400 dark:text-slate-600">Never</span>
                        @endif
                    </td>

                    {{-- Actions --}}
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @if ($rowState === 'outranked')
                                {{--
                                    Nothing on this row is yours to do, so say that rather than
                                    offering a button that opens an empty menu. The rule is
                                    `roles.level` and it is strict on purpose — a peer is as far out
                                    of reach as a superior — which is why the wording covers both.
                                --}}
                                <span
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs text-slate-400 dark:text-slate-500"
                                    title="Accounts are ranked by their strongest role, and the rule is strict: you can only act on an account that ranks below yours, so an equal rank is out of reach too. A higher-level administrator can manage this one."
                                >
                                    <x-ui.icon name="lock-closed" class="h-4 w-4" />
                                    <span>This account outranks yours</span>
                                </span>
                            @elseif ($rowState === 'none')
                                <span class="text-xs text-slate-400 dark:text-slate-600">—</span>
                            @else
                            @if ($rowActions['update'])
                                <x-ui.icon-button
                                    icon="pencil"
                                    label="Edit {{ $user->name }}"
                                    size="sm"
                                    :href="route('admin.users.edit', $user)"
                                />
                            @endif

                            @if ($hasMenuActions)
                            <x-ui.dropdown label="Actions for {{ $user->name }}" width="w-60">
                                @if ($rowActions['view'])
                                    <x-ui.dropdown-item icon="eye" :href="route('admin.users.show', $user)">
                                        View profile
                                    </x-ui.dropdown-item>
                                @endif

                                {{--
                                    Hidden on your own row. `Gate::before` grants Super Admin every
                                    policy, so the @can alone would offer actions that the service
                                    then refuses — suspending or password-resetting yourself only
                                    closes your own sessions.
                                --}}

                                @if ($rowActions['changeStatus'])
                                    {{--
                                        The row's data travels through data-* attributes rather than
                                        inline Blade inside the Alpine expression: it keeps the
                                        handler free of escaping traps and lets one dialog serve
                                        every row.
                                    --}}
                                    <x-ui.dropdown-item
                                        icon="check-badge"
                                        data-url="{{ route('admin.users.status', $user) }}"
                                        data-name="{{ $user->name }}"
                                        data-status="{{ $user->status->value }}"
                                        x-on:click="target = { url: $el.dataset.url, name: $el.dataset.name, status: $el.dataset.status }; close(false); $dispatch('open-modal', 'change-status')"
                                    >
                                        Change status
                                    </x-ui.dropdown-item>
                                @endif

                                @if ($rowActions['resetPassword'])
                                    <x-ui.dropdown-item
                                        icon="key"
                                        data-url="{{ route('admin.users.reset-password', $user) }}"
                                        data-name="{{ $user->name }}"
                                        data-status="{{ $user->status->value }}"
                                        x-on:click="target = { url: $el.dataset.url, name: $el.dataset.name, status: $el.dataset.status }; close(false); $dispatch('open-modal', 'reset-password')"
                                    >
                                        Reset password
                                    </x-ui.dropdown-item>
                                @endif
                            </x-ui.dropdown>
                            @endif

                            @if ($rowActions['delete'])
                                <x-ui.confirm
                                    :action="route('admin.users.destroy', $user)"
                                    method="DELETE"
                                    title="Delete {{ $user->name }}?"
                                    message="Their sign-in is revoked immediately. The account is soft deleted, so everything they created stays in place and the row can be restored by an administrator."
                                    confirm-label="Delete user"
                                >
                                    <x-slot:trigger>
                                        <x-ui.icon-button
                                            icon="trash"
                                            label="Delete {{ $user->name }}"
                                            variant="danger"
                                            size="sm"
                                        />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state
                    icon="users"
                    title="No users match those filters"
                    message="Try clearing the search or the filters, or add the first account."
                >
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="route('admin.users.index')">Clear filters</x-ui.button>

                        @can('create', User::class)
                            <x-ui.button icon="user-plus" :href="route('admin.users.create')">New user</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-ui.empty-state>
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$users" label="users" />
            </x-slot:footer>
        </x-ui.table>

        {{-- ── Change status ────────────────────────────────────────────────────────── --}}
        @if ($canChangeStatus)
            <x-ui.modal name="change-status" title="Change account status" icon="check-badge" size="md">
                {{--
                    PATCH, not PUT: `admin.users.status` is registered as Route::patch(), so a
                    spoofed PUT answered 405 and this dialog could never actually change a status.
                --}}
                <form method="POST" x-bind:action="target.url" id="change-status-form" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Updating access for
                        <strong class="font-semibold text-slate-900 dark:text-white" x-text="target.name"></strong>.
                        Anything other than <em>Active</em> signs them out of every device straight away.
                    </p>

                    <x-ui.form.select
                        name="status"
                        label="New status"
                        :options="$statusOptions"
                        required
                        x-model="target.status"
                    />

                    <x-ui.form.textarea
                        name="reason"
                        label="Reason"
                        :rows="3"
                        :maxlength="255"
                        placeholder="Why is this changing?"
                        help="Required whenever the new status takes access away — it is stored on the account and in the audit trail."
                    />
                </form>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="hide(true)">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="change-status-form" icon="check">Update status</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif

        {{-- ── Reset password ───────────────────────────────────────────────────────── --}}
        @if ($canResetPassword)
            <x-ui.modal name="reset-password" title="Issue a temporary password" icon="key" size="md">
                <form method="POST" x-bind:action="target.url" id="reset-password-form" class="space-y-4">
                    @csrf

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        A new random password will be generated for
                        <strong class="font-semibold text-slate-900 dark:text-white" x-text="target.name"></strong>.
                        They are signed out everywhere and must choose a new password at their next sign-in.
                    </p>

                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                        The password is shown once, on the next screen. Copy it before you navigate away — it
                        is never stored in readable form and cannot be shown again.
                    </p>

                    <x-ui.form.textarea
                        name="reason"
                        label="Reason"
                        optional
                        :rows="2"
                        :maxlength="255"
                        placeholder="e.g. user reported a lost phone"
                    />
                </form>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="hide(true)">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="reset-password-form" variant="danger" icon="key">
                        Issue temporary password
                    </x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
@endsection
