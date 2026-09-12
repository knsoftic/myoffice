@extends('layouts.admin')

@section('title', 'Modules')

{{--
    Module switchboard (route admin.modules.index, toggle posts to admin.modules.toggle).

    Switching a module off closes it completely — `Gate::before` denies every one of its
    permissions to everyone, Super Admin included, its routes answer 403 and its sidebar entries
    disappear — and touches none of its data. Switching it back on restores access exactly as it
    was. Core modules are structural and are rendered locked.
--}}

@php
    use App\Enums\Ability;

    $actor = auth()->user();
    $canToggle = (bool) $actor?->can('modules.'.Ability::ChangeStatus->value);

    // Same visual language as x-ui.form.toggle, but driven by server state rather than
    // peer-checked, because the control is a confirm trigger instead of a form field.
    $track = static fn (bool $on, bool $locked): string => implode(' ', [
        'h-6 w-11 rounded-full transition-colors duration-150',
        $locked
            ? 'bg-slate-200 dark:bg-slate-700'
            : ($on ? 'bg-brand-600 dark:bg-brand-500' : 'bg-slate-200 dark:bg-slate-700'),
    ]);

    $knob = static fn (bool $on): string => implode(' ', [
        'pointer-events-none absolute left-[3px] h-[1.125rem] w-[1.125rem] rounded-full bg-white shadow-sm transition-transform duration-150',
        $on ? 'translate-x-5' : 'translate-x-0',
    ]);
@endphp

@section('header')
    <x-ui.page-header
        title="Modules"
        subtitle="Turn feature areas on and off. Disabling hides a module everywhere and denies its permissions — the data stays."
        icon="puzzle-piece"
        :badge="$stats['enabled'].' of '.$stats['total'].' on'"
        badge-color="emerald"
    >
        <x-slot:actions>
            @can('permissions.'.Ability::ViewAny->value)
                <x-ui.button variant="secondary" icon="key" :href="route('admin.permissions.index')">
                    Permissions
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-4">

        {{-- ── Figures ──────────────────────────────────────────────────────────────── --}}
        <div class="card-grid">
            <x-ui.stat-card
                label="Modules"
                :value="number_format((float) $stats['total'])"
                icon="puzzle-piece"
                color="brand"
            />

            <x-ui.stat-card
                label="Enabled"
                :value="number_format((float) $stats['enabled'])"
                icon="check-circle"
                color="emerald"
                :href="route('admin.modules.index', ['state' => 'enabled'])"
            />

            <x-ui.stat-card
                label="Disabled"
                :value="number_format((float) $stats['disabled'])"
                icon="eye-slash"
                color="rose"
                :href="route('admin.modules.index', ['state' => 'disabled'])"
            />

            <x-ui.stat-card
                label="Core (always on)"
                :value="number_format((float) $stats['core'])"
                icon="lock-closed"
                color="amber"
                :href="route('admin.modules.index', ['state' => 'core'])"
                delta-label="cannot be switched off"
            />
        </div>

        {{-- ── Filters ──────────────────────────────────────────────────────────────── --}}
        <x-ui.filter-bar
            placeholder="Search module name, slug or description…"
            :reset="route('admin.modules.index')"
        >
            <x-ui.form.select
                name="group"
                :options="$groupOptions"
                :selected="request('group')"
                placeholder="Any group"
                size="sm"
                icon="rectangle-stack"
                aria-label="Filter by group"
            />

            <x-ui.form.select
                name="state"
                :options="['enabled' => 'Enabled', 'disabled' => 'Disabled', 'core' => 'Core']"
                :selected="request('state')"
                placeholder="Any state"
                size="sm"
                icon="check-circle"
                aria-label="Filter by state"
            />
        </x-ui.filter-bar>

        {{-- ── Cards, grouped ───────────────────────────────────────────────────────── --}}
        @if ($groups === [])
            <x-ui.card>
                <x-ui.empty-state
                    icon="puzzle-piece"
                    title="No modules match those filters"
                    message="Clear the filters to see every module. If nothing shows anywhere, the module seeder has not run yet."
                >
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="route('admin.modules.index')">Clear filters</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            @foreach ($groups as $group)
                <section class="space-y-3">
                    <x-ui.section-heading
                        :title="$group['label']"
                        :subtitle="count($group['modules']).' '.Str::plural('module', count($group['modules'])).' in this group'"
                        :divider="true"
                    >
                        <x-slot:actions>
                            <x-ui.badge :color="$group['color']" size="sm">{{ $group['label'] }}</x-ui.badge>
                        </x-slot:actions>
                    </x-ui.section-heading>

                    <div class="card-grid-3">
                        @foreach ($group['modules'] as $module)
                            @php
                                $isCore = $module->isCore();
                                $on = $module->isEnabled();
                                $locked = $isCore || ! $canToggle;
                                $lockReason = $isCore
                                    ? 'Core module: the dashboard, users, roles, permissions, modules, settings and logs are structural — switching them off would lock everyone out, so it is never allowed.'
                                    : 'You do not hold the permission to change a module’s state.';
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
                                                {{ $module->slug }}
                                            </code>
                                        </div>
                                    </div>

                                    {{-- ── The switch ─────────────────────────────────── --}}
                                    <div class="shrink-0">
                                        @if ($locked)
                                            {{-- Locked: same switch, inert, with the reason on hover. --}}
                                            <span
                                                class="relative inline-flex cursor-not-allowed items-center opacity-60"
                                                role="switch"
                                                aria-checked="{{ $on ? 'true' : 'false' }}"
                                                aria-disabled="true"
                                                title="{{ $lockReason }}"
                                            >
                                                <span class="{{ $track($on, true) }}" aria-hidden="true"></span>
                                                <span class="{{ $knob($on) }}" aria-hidden="true"></span>
                                                <span class="sr-only">{{ $module->name }} is {{ $on ? 'enabled' : 'disabled' }} and locked</span>
                                            </span>
                                        @else
                                            {{--
                                                `id` lands on the dialog's own <form> (x-ui.confirm
                                                spreads its attributes there), which lets the reason
                                                field live in the readable part of the dialog and
                                                still post with it.
                                            --}}
                                            <x-ui.confirm
                                                :id="'module-toggle-'.$module->id"
                                                :action="route('admin.modules.toggle', $module)"
                                                method="POST"
                                                :title="($on ? 'Disable ' : 'Enable ').$module->name.'?'"
                                                :message="$on
                                                    ? 'Its routes will answer 403 for everyone — Super Admin included — and its sidebar entries disappear. None of its data is touched, and switching it back on restores access exactly as it was.'
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

                                                {{-- Posted with the form, so the state is explicit and a double submit cannot flip it back. --}}
                                                <x-slot:fields>
                                                    <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}" />
                                                </x-slot:fields>

                                                <div class="mt-4">
                                                    <label for="module-reason-{{ $module->id }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                        Reason <span class="font-normal text-slate-400">(optional, recorded in the audit trail)</span>
                                                    </label>
                                                    <input
                                                        id="module-reason-{{ $module->id }}"
                                                        type="text"
                                                        name="reason"
                                                        maxlength="255"
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

                                <div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                                    <x-ui.badge :color="$on ? 'emerald' : 'slate'" size="xs" :dot="true">
                                        {{ $on ? 'Enabled' : 'Disabled' }}
                                    </x-ui.badge>

                                    @if ($isCore)
                                        <span class="inline-flex" title="{{ $lockReason }}">
                                            <x-ui.badge color="amber" size="xs" icon="lock-closed">Core</x-ui.badge>
                                        </span>
                                    @endif

                                    @can('permissions.'.Ability::ViewAny->value)
                                        <a href="{{ route('admin.permissions.index', ['module' => $module->slug]) }}" class="ml-auto">
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
                        @endforeach
                    </div>
                </section>
            @endforeach

            <x-ui.card :compact="true">
                <x-ui.pagination-summary :paginator="$modules" label="modules" />
            </x-ui.card>
        @endif
    </div>
@endsection
