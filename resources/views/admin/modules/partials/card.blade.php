{{--
    One module card (included by admin/modules/index.blade.php).

    Expects: $module (with permissions_count + disabledBy loaded), $canToggle (bool),
             $chips ['dependencies','dependents','missing','blocking'], $routeCount (int),
             $hasImpactRoute (bool).

    The switch never acts on its own: it opens the impact dialog, which says what will break, asks
    for a reason and states that no data is deleted. Core modules render locked with their own
    reason — T22: the four `*_portal` cards are permission namespaces, not structural screens, and
    must not be explained as if they were the dashboard.
--}}

@php
    use App\Enums\Ability;

    $isCore = $module->isCore();
    $on = $module->isEnabled();
    $locked = $isCore || ! $canToggle;
    $slug = (string) $module->slug;

    /*
    | Why this module can never be switched off — one accurate sentence per core module (T22).
    |
    | Two families, and they are not the same thing:
    |   · a structural system module, without which the admin panel cannot be operated at all;
    |   · a `*_portal` permission namespace (D20 / T20), which is not a feature area: it carries
    |     the permissions that let one kind of account reach its own panel, so switching it off
    |     would deny every one of those permissions and lock that whole audience out.
    */
    $coreReasons = [
        'dashboard' => 'Core module: the dashboard is where every panel lands after login — with it off there would be no first screen to send anyone to.',
        'users' => 'Core module: user accounts are what every other record is attributed to, and without it nobody could be created, suspended or repaired.',
        'roles' => 'Core module: roles carry every permission grant, so switching them off would revoke authorisation for everyone at once.',
        'permissions' => 'Core module: the permission catalogue is the registry the role editor and every policy read — it is infrastructure, not a feature area.',
        'modules' => 'Core module: this is the switchboard itself. Turning it off would leave no way to turn anything back on.',
        'settings' => 'Core module: every brand, currency, mail and numbering value the system reads lives here, with no other way to edit them.',
        'activity_log' => 'Core module: the audit trail has to keep recording even while a feature area is being taken down — a log with an off switch is not an audit trail.',
        'login_history' => 'Core module: login, lockout and session history is a security record, and it keeps recording whatever else is switched off.',
        'backups' => 'Core module: backups are the last line of defence and must stay reachable exactly when the rest of the system is being reconfigured.',
        'global_search' => 'Core module: global search is part of the shell rather than a feature area — the topbar expects it to exist.',

        'collaborator_portal' => 'Not a feature area but a permission namespace: it holds the permissions that let a collaborator reach their own panel. Switching it off would deny every one of them and lock every collaborator out, so it is never switchable — close the panel by disabling the Collaborators module instead.',
        'student_portal' => 'Not a feature area but a permission namespace: it holds the permissions that let a student reach their own panel. Switching it off would deny every one of them and lock every student out of their portal, so it is never switchable.',
        'teacher_portal' => 'Not a feature area but a permission namespace: it holds the permissions that let a teacher reach their own panel. Switching it off would deny every one of them and lock every teacher out of their portal, so it is never switchable.',
        'client_portal' => 'Not a feature area but a permission namespace: it holds the permissions that let a client reach their own panel. Switching it off would deny every one of them and lock every client out of their portal, so it is never switchable.',
    ];

    $lockReason = $isCore
        ? ($coreReasons[$slug] ?? 'Core module: it is structural, so switching it off is never allowed.')
        : 'You do not hold the permission to change a module’s state.';

    // Same visual language as x-ui.form.toggle, but driven by server state rather than
    // peer-checked, because the control is a dialog trigger instead of a form field.
    $track = static fn (bool $isOn, bool $isLocked): string => implode(' ', [
        'h-6 w-11 rounded-full transition-colors duration-150',
        $isLocked
            ? 'bg-slate-200 dark:bg-slate-700'
            : ($isOn ? 'bg-brand-600 dark:bg-brand-500' : 'bg-slate-200 dark:bg-slate-700'),
    ]);

    $knob = static fn (bool $isOn): string => implode(' ', [
        'pointer-events-none absolute left-[3px] h-[1.125rem] w-[1.125rem] rounded-full bg-white shadow-sm transition-transform duration-150',
        $isOn ? 'translate-x-5' : 'translate-x-0',
    ]);

    $chipColor = static fn (array $chip): string => $chip['enabled'] ? 'emerald' : 'rose';
@endphp

<x-ui.card :hover="! $locked" class="h-full">
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-2.5">
            <span @class([
                'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400' => $on,
                'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500' => ! $on,
            ])>
                <x-ui.icon :name="$module->icon ?: 'puzzle-piece'" class="h-[1.125rem] w-[1.125rem]" />
            </span>

            <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
                    {{ $module->name }}
                </h3>

                <code class="block truncate font-mono text-2xs text-slate-400 dark:text-slate-500">
                    {{ $slug }}
                </code>
            </div>
        </div>

        {{-- ── The switch ─────────────────────────────────────────────────── --}}
        <div class="shrink-0">
            @if ($locked)
                {{-- Locked: the same switch, inert, with this module's own reason on hover. --}}
                <span
                    class="relative inline-flex cursor-not-allowed items-center opacity-60"
                    role="switch"
                    aria-checked="{{ $on ? 'true' : 'false' }}"
                    aria-disabled="true"
                    title="{{ $lockReason }}"
                >
                    <span class="{{ $track($on, true) }}" aria-hidden="true"></span>
                    <span class="{{ $knob($on) }}" aria-hidden="true"></span>
                    <span class="sr-only">{{ $module->name }} is {{ $on ? 'enabled' : 'disabled' }} and locked: {{ $lockReason }}</span>
                </span>
            @elseif ($hasImpactRoute)
                {{--
                    Opens the shared impact dialog, which fetches admin.modules.impact for this
                    module, lists what breaks, demands a reason and posts the toggle itself.
                --}}
                <button
                    type="button"
                    role="switch"
                    aria-checked="{{ $on ? 'true' : 'false' }}"
                    aria-haspopup="dialog"
                    class="relative inline-flex items-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950"
                    title="{{ $on ? 'Disable' : 'Enable' }} {{ $module->name }}"
                    x-on:click="$dispatch('module-impact', {
                        name: @js($module->name),
                        slug: @js($slug),
                        enable: @js(! $on),
                        impactUrl: @js(route('admin.modules.impact', $module)),
                        toggleUrl: @js(route('admin.modules.toggle', $module)),
                    })"
                >
                    <span class="{{ $track($on, false) }}" aria-hidden="true"></span>
                    <span class="{{ $knob($on) }}" aria-hidden="true"></span>
                    <span class="sr-only">{{ $on ? 'Disable' : 'Enable' }} {{ $module->name }}</span>
                </button>
            @else
                {{--
                    Fallback while the impact route does not exist yet: the phase-01 confirm, which
                    still posts an explicit state and still records a reason.
                --}}
                <x-ui.confirm
                    :id="'module-toggle-'.$module->id"
                    :action="route('admin.modules.toggle', $module)"
                    method="POST"
                    :title="($on ? 'Disable ' : 'Enable ').$module->name.'?'"
                    :message="$on
                        ? 'Its routes will answer 403 for everyone — Super Admin included — and its sidebar entries disappear. No data is deleted: switching it back on restores access exactly as it was.'
                        : 'Its routes, sidebar entries and permissions become available again to everyone who holds them.'"
                    :confirm-label="$on ? 'Disable module' : 'Enable module'"
                    :variant="$on ? 'danger' : 'warning'"
                    :icon="$on ? 'eye-slash' : 'check-circle'"
                >
                    <x-slot:trigger>
                        <button
                            type="button"
                            role="switch"
                            aria-checked="{{ $on ? 'true' : 'false' }}"
                            class="relative inline-flex items-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950"
                            title="{{ $on ? 'Disable' : 'Enable' }} {{ $module->name }}"
                        >
                            <span class="{{ $track($on, false) }}" aria-hidden="true"></span>
                            <span class="{{ $knob($on) }}" aria-hidden="true"></span>
                            <span class="sr-only">{{ $on ? 'Disable' : 'Enable' }} {{ $module->name }}</span>
                        </button>
                    </x-slot:trigger>

                    <x-slot:fields>
                        <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}" />
                    </x-slot:fields>

                    <div class="mt-4">
                        <label for="module-reason-{{ $module->id }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                            Reason @if ($on)<span class="text-rose-500">*</span>@endif
                            <span class="font-normal text-slate-400">(recorded in the audit trail)</span>
                        </label>
                        {{-- D63: the server refuses a disable without a 5–255 character reason; the browser says so first. --}}
                        <input
                            id="module-reason-{{ $module->id }}"
                            type="text"
                            name="reason"
                            maxlength="255"
                            @if ($on) required minlength="5" @endif
                            form="{{ 'module-toggle-'.$module->id }}"
                            placeholder="e.g. not part of this rollout"
                            class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                        />
                    </div>
                </x-ui.confirm>
            @endif
        </div>
    </div>

    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
        {{ $module->description ?: 'No description.' }}
    </p>

    {{-- ── Dependencies ───────────────────────────────────────────────────────── --}}
    @if ($chips['dependencies'] !== [] || $chips['dependents'] !== [])
        <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
            @if ($chips['dependencies'] !== [])
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex items-center gap-1 text-2xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">
                        <x-ui.icon name="arrow-right" class="h-3 w-3" />
                        Needs
                    </span>

                    @foreach ($chips['dependencies'] as $chip)
                        <a
                            href="{{ route('admin.modules.index', ['search' => $chip['slug']]) }}"
                            title="{{ $module->name }} needs {{ $chip['name'] }}, which is currently {{ $chip['enabled'] ? 'on' : 'OFF' }}."
                        >
                            <x-ui.badge :color="$chipColor($chip)" size="xs" :icon="$chip['enabled'] ? 'check' : 'exclamation-triangle'">
                                {{ $chip['name'] }}
                            </x-ui.badge>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($chips['dependents'] !== [])
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex items-center gap-1 text-2xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">
                        <x-ui.icon name="arrow-left" class="h-3 w-3" />
                        Needed by
                    </span>

                    @foreach ($chips['dependents'] as $chip)
                        <a
                            href="{{ route('admin.modules.index', ['search' => $chip['slug']]) }}"
                            title="{{ $chip['name'] }} depends on {{ $module->name }} and is currently {{ $chip['enabled'] ? 'on — it blocks a plain disable' : 'off' }}."
                        >
                            <x-ui.badge :color="$chip['enabled'] ? 'amber' : 'slate'" size="xs" icon="link">
                                {{ $chip['name'] }}
                            </x-ui.badge>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ── Why it is off ──────────────────────────────────────────────────────── --}}
    @if (! $on && $module->disabled_at !== null)
        <div class="mt-3 rounded-lg bg-rose-50/70 p-2.5 text-2xs leading-relaxed text-rose-700 ring-1 ring-inset ring-rose-100 dark:bg-rose-500/5 dark:text-rose-300 dark:ring-rose-500/20">
            <p class="font-medium">
                Switched off {{ $module->disabled_at->diffForHumans() }}
                {{-- D61: stored in UTC, shown in the display timezone through the Format helpers. --}}
                <span class="font-normal opacity-75" title="{{ app_datetime($module->disabled_at, 'D, '.\App\Support\Format::dateTimeFormat()) }}">
                    ({{ app_datetime($module->disabled_at) }})
                </span>
                @if ($module->disabledBy !== null)
                    by {{ $module->disabledBy->name }}
                @endif
            </p>

            @if (filled($module->disable_reason))
                <p class="mt-0.5 opacity-90">“{{ $module->disable_reason }}”</p>
            @endif
        </div>
    @elseif (! $on)
        <div class="mt-3 rounded-lg bg-slate-50 p-2.5 text-2xs text-slate-500 ring-1 ring-inset ring-slate-100 dark:bg-slate-800/40 dark:text-slate-400 dark:ring-slate-800">
            Switched off, with no recorded reason — the row predates the audit columns or was changed directly in the database.
        </div>
    @endif

    {{-- ── Counts ─────────────────────────────────────────────────────────────── --}}
    <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
        <x-ui.badge :color="$on ? 'emerald' : 'slate'" size="xs" :dot="true">
            {{ $on ? 'Enabled' : 'Disabled' }}
        </x-ui.badge>

        @if ($isCore)
            <span class="inline-flex" title="{{ $lockReason }}">
                <x-ui.badge color="amber" size="xs" icon="lock-closed">Core</x-ui.badge>
            </span>
        @endif

        <span class="inline-flex" title="{{ $routeCount }} registered {{ Str::plural('route', $routeCount) }} answer 403 while this module is off.">
            <x-ui.badge color="slate" size="xs" icon="link">
                {{ $routeCount }} {{ Str::plural('route', $routeCount) }}
            </x-ui.badge>
        </span>

        @can('permissions.'.Ability::ViewAny->value)
            <a href="{{ route('admin.permissions.index', ['module' => $slug]) }}" class="ml-auto">
                <x-ui.badge color="slate" size="xs" icon="key">
                    {{ $module->permissions_count }} {{ Str::plural('permission', (int) $module->permissions_count) }}
                </x-ui.badge>
            </a>
        @else
            <x-ui.badge color="slate" size="xs" icon="key" class="ml-auto">
                {{ $module->permissions_count }} {{ Str::plural('permission', (int) $module->permissions_count) }}
            </x-ui.badge>
        @endcan
    </div>
</x-ui.card>
