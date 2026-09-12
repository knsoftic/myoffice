{{--
    Shared user form — included by admin/users/create and admin/users/edit.

    Expects:
        $user           App\Models\User        existing row, or a fresh instance on create
        $roles          Collection<Role>       only the roles the actor may actually grant
        $branches       Collection<Branch>
        $statusOptions  array<string, string>
        $action         string                 form target
        $method         'POST'|'PUT'
        $submitLabel    string

    Every field here is validated server-side by Store/UpdateUserRequest — this markup only
    decides what is comfortable to type.

    Two fields are deliberately absent when editing an existing account, because the server refuses
    them there (see UpdateUserRequest and UserService):

        status / status_reason  taking access away is PATCH users/{user}/status, which demands
                                users.change_status and a written reason, and never your own account
        roles (your own row)    nobody assigns themselves a role

    A missing input is only a courtesy; the refusal itself lives on the server.
--}}

@php
    use App\Enums\UserStatus;

    $isEdit = $user->exists;
    $isSelf = $isEdit && $user->is(auth()->user());

    // old() wins after a failed submit; checkbox groups cannot resolve that themselves.
    $currentRoleIds = $isEdit ? $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    $selectedRoles = array_map('intval', (array) old('roles', $currentRoleIds));

    $rolesByPanel = $roles->groupBy(fn ($role) => $role->panelType()->value);

    $statusValue = old('status', $user->status instanceof UserStatus ? $user->status->value : UserStatus::Active->value);

    // The change-password screen's route name is not fixed by the contract; resolve it rather than
    // guessing, so a rename leaves plain text instead of a RouteNotFoundException.
    $passwordScreen = collect(['account.password.edit', 'account.password'])
        ->first(fn (string $name) => Route::has($name));
    $passwordScreen = $passwordScreen ? route($passwordScreen) : null;
@endphp

<form
    method="POST"
    action="{{ $action }}"
    enctype="multipart/form-data"
    class="space-y-5"
>
    @csrf

    @if (($method ?? 'POST') !== 'POST')
        @method($method)
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        {{-- ══ Main column ═══════════════════════════════════════════════════════════ --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- Identity --}}
            <x-ui.card title="Identity" subtitle="Who this person is and how the system addresses them." icon="user">
                <div class="space-y-4">
                    <x-ui.form.file
                        name="avatar"
                        label="Profile photo"
                        accept="image/jpeg,image/png,image/webp"
                        :current="$isEdit ? $user->avatar_url : null"
                        hint="JPG, PNG or WEBP, up to 2 MB. Leave empty to keep the generated initials avatar."
                    />

                    @if ($isEdit && filled($user->avatar_path))
                        <x-ui.form.checkbox
                            name="remove_avatar"
                            value="1"
                            label="Remove the current photo"
                            description="Falls back to the generated initials avatar."
                            :with-hidden="true"
                        />
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.form.input
                            name="name"
                            label="Full name"
                            :value="$user->name"
                            required
                            autocomplete="name"
                            placeholder="Ayesha Khan"
                        />

                        <x-ui.form.input
                            name="email"
                            type="email"
                            label="Email address"
                            :value="$user->email"
                            required
                            icon="envelope"
                            autocomplete="email"
                            help="Used to sign in. Must be unique across every panel."
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.form.input
                            name="phone"
                            label="Phone"
                            :value="$user->phone"
                            icon="phone"
                            optional
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="+92 300 1234567"
                        />

                        <x-ui.form.input
                            name="whatsapp"
                            label="WhatsApp"
                            :value="$user->whatsapp"
                            icon="whatsapp"
                            optional
                            inputmode="tel"
                            placeholder="+92 300 1234567"
                        />
                    </div>
                </div>
            </x-ui.card>

            {{-- Credentials --}}
            <x-ui.card
                title="Sign-in"
                :subtitle="$isEdit
                    ? 'Leave the password blank to keep the current one.'
                    : 'Set the first password. Force a change so the account owner picks their own.'"
                icon="lock-closed"
            >
                <div class="space-y-4">
                    @if ($isSelf)
                        {{--
                            Your own password is changed on your own account screen, which confirms
                            the current one first. Setting it from here would revoke the sessions of
                            the person doing it; UpdateUserRequest and UserService both refuse it.
                        --}}
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            @if ($passwordScreen)
                                Change your own password from
                                <a href="{{ $passwordScreen }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">your account settings</a>,
                                where your current password is confirmed first.
                            @else
                                Change your own password from your account settings, where your current
                                password is confirmed first.
                            @endif
                        </p>
                    @else
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.form.input
                                name="password"
                                type="password"
                                label="Password"
                                :required="! $isEdit"
                                :optional="$isEdit"
                                autocomplete="new-password"
                            />

                            <x-ui.form.input
                                name="password_confirmation"
                                type="password"
                                label="Confirm password"
                                :required="! $isEdit"
                                :optional="$isEdit"
                                autocomplete="new-password"
                            />
                        </div>
                    @endif

                    <x-ui.form.toggle
                        name="must_change_password"
                        label="Force a password change at next sign-in"
                        description="The account is sent to the change-password screen before it can go anywhere else."
                        :checked="(bool) old('must_change_password', $user->must_change_password)"
                    />
                </div>
            </x-ui.card>

            {{-- Roles --}}
            <x-ui.card
                title="Roles"
                subtitle="Roles decide which panel the account reaches and what it may do. Pick at least one."
                icon="shield-check"
                :padded="false"
            >
                @if ($isSelf)
                    {{--
                        Your own account: the roles are listed, never offered. UpdateUserRequest
                        rejects any role delta on your own row and UserService throws on one, so a
                        checkbox here would only be a trap.
                    --}}
                    <div class="p-4 sm:p-5">
                        <div class="mb-3 flex flex-wrap items-center gap-1.5">
                            @forelse ($user->roles as $role)
                                <x-ui.badge :color="$role->panelType()->color()" size="sm" icon="shield-check">
                                    {{ $role->displayName() }}
                                </x-ui.badge>
                            @empty
                                <span class="text-xs text-slate-500 dark:text-slate-400">No roles assigned.</span>
                            @endforelse
                        </div>

                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            You cannot change your own roles — nobody grants themselves power. Ask another
                            administrator who outranks you.
                        </p>
                    </div>
                @elseif ($roles->isEmpty())
                    <x-ui.empty-state
                        icon="shield-check"
                        title="No roles you can grant"
                        message="You can only hand out roles weaker than your own, to an account weaker than your own. Ask a higher-level administrator to assign this account."
                        :compact="true"
                    />
                @else
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($rolesByPanel as $panelValue => $panelRoles)
                            @php $panel = App\Enums\PanelType::tryFrom((string) $panelValue); @endphp

                            <div class="p-4 sm:p-5">
                                <div class="mb-3 flex items-center gap-2">
                                    <x-ui.badge :color="$panel?->color() ?? 'slate'" size="sm">
                                        {{ $panel?->label() ?? ucfirst((string) $panelValue) }} panel
                                    </x-ui.badge>
                                    <span class="text-2xs text-slate-400 dark:text-slate-500">
                                        {{ $panelRoles->count() }} {{ Str::plural('role', $panelRoles->count()) }}
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @foreach ($panelRoles as $role)
                                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-slate-200 p-3 transition-colors hover:border-brand-300 hover:bg-brand-50/40 dark:border-slate-800 dark:hover:border-brand-500/40 dark:hover:bg-brand-500/5">
                                            <input
                                                type="checkbox"
                                                name="roles[]"
                                                value="{{ $role->id }}"
                                                @checked(in_array((int) $role->id, $selectedRoles, true))
                                                class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800"
                                            />

                                            <span class="min-w-0">
                                                <span class="flex flex-wrap items-center gap-1.5">
                                                    <span class="text-sm font-medium text-slate-900 dark:text-white">
                                                        {{ $role->displayName() }}
                                                    </span>

                                                    @if ($role->is_system)
                                                        <x-ui.badge color="amber" size="xs" icon="lock-closed">System</x-ui.badge>
                                                    @endif

                                                    <span class="text-2xs text-slate-400 tabular-nums dark:text-slate-500">L{{ $role->level }}</span>
                                                </span>

                                                @if (filled($role->description))
                                                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                                                        {{ $role->description }}
                                                    </span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <x-slot:footer>
                    <x-ui.form.error for="roles" />
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        Holding roles from more than one panel gives the account access to each of them.
                    </span>
                </x-slot:footer>
            </x-ui.card>
        </div>

        {{-- ══ Side column ═══════════════════════════════════════════════════════════ --}}
        <div class="space-y-5">
            <x-ui.card title="Access" subtitle="Only an active account can sign in." icon="check-badge">
                <div class="space-y-4">
                    @if ($isEdit)
                        {{--
                            Read-only on purpose. A status change takes access away, so it goes
                            through the dedicated control on the profile screen, which requires
                            users.change_status, demands a reason for the audit trail and refuses
                            your own account. The server ignores a `status` posted here and refuses
                            one that differs from the row.
                        --}}
                        <div>
                            <span class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Status</span>

                            <x-ui.badge :color="$user->status->color()" size="sm" :dot="true">
                                {{ $user->status->label() }}
                            </x-ui.badge>

                            @if (filled($user->status_reason))
                                <p class="mt-2 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600 dark:bg-slate-950/40 dark:text-slate-300">
                                    {{ $user->status_reason }}
                                </p>
                            @endif

                            @if ($user->status_changed_at)
                                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                    Status last changed {{ $user->status_changed_at->diffForHumans() }}.
                                </p>
                            @endif

                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                @can('changeStatus', $user)
                                    Activate, deactivate or suspend this account from
                                    <a href="{{ route('admin.users.show', $user) }}#access-controls" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Access controls</a>
                                    on the profile screen — a reason is recorded with the change.
                                @else
                                    Changing the status needs the <span class="font-mono">users.change_status</span>
                                    permission, and never applies to your own account.
                                @endcan
                            </p>
                        </div>
                    @else
                        <x-ui.form.select
                            name="status"
                            label="Status"
                            :options="$statusOptions"
                            :selected="$statusValue"
                            required
                        />

                        <x-ui.form.textarea
                            name="status_reason"
                            label="Restriction note"
                            optional
                            :rows="3"
                            :maxlength="255"
                            :value="$user->status_reason"
                            placeholder="Why is this account not active?"
                            help="Shown in the audit trail. Cleared automatically when the account becomes active."
                        />
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Branch" subtitle="Where this person is based." icon="building-office">
                @if ($branches->isEmpty())
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        No branches exist yet, so the account is not tied to one.
                    </p>
                @else
                    <x-ui.form.select
                        name="branch_id"
                        label="Branch"
                        placeholder="No branch"
                        :selected="old('branch_id', $user->branch_id)"
                        optional
                    >
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id', $user->branch_id) === (int) $branch->id)>
                                {{ $branch->name }}@if (! $branch->is_active) (inactive)@endif
                            </option>
                        @endforeach
                    </x-ui.form.select>
                @endif
            </x-ui.card>

            @if ($isEdit)
                <x-ui.card title="Record" icon="clock">
                    <dl class="space-y-2.5 text-xs">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Created</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->created_at?->format('d M Y') ?? '—' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Last sign-in</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Password changed</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">
                                {{ $user->password_changed_at?->diffForHumans() ?? 'Unknown' }}
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- ══ Actions ════════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col-reverse items-stretch gap-2 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-end dark:border-slate-800">
        <x-ui.button
            variant="secondary"
            :href="$isEdit ? route('admin.users.show', $user) : route('admin.users.index')"
        >
            Cancel
        </x-ui.button>

        <x-ui.button type="submit" icon="check">{{ $submitLabel }}</x-ui.button>
    </div>
</form>
