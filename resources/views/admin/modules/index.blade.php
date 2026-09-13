@extends('layouts.admin')

@section('title', 'Modules')

{{--
    Module switchboard (route admin.modules.index; toggle → admin.modules.toggle,
    bulk → admin.modules.bulk-toggle, impact preview → admin.modules.impact).

    Switching a module off closes it completely — `Gate::before` denies every one of its
    permissions to everyone, Super Admin included, its routes answer 403 and its sidebar entries
    disappear — and touches none of its data. Switching it back on restores access exactly as it
    was. Core modules are structural and are rendered locked, each with its own reason.

    Dependency awareness (phase-02 §3, §5): every card shows what it needs and what needs it, and
    a disable that would strand an enabled dependent is refused by the service unless the
    administrator explicitly confirms the cascade in the impact dialog.

    View data: $modules (paginator), $groups, $groupOptions, $stats, $dependencies (slug => chips),
    $routeCounts (slug => int), $canToggle.
--}}

@php
    use App\Enums\Ability;
    use Illuminate\Support\Facades\Route as RouteFacade;

    // Another agent owns routes/admin.php; until the Phase 2 routes land, the screen degrades to
    // the plain switch instead of 500-ing on a missing route name.
    $hasImpactRoute = RouteFacade::has('admin.modules.impact');
    $hasBulkRoute = RouteFacade::has('admin.modules.bulk-toggle');
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
                :value="app_number($stats['total'])"
                icon="puzzle-piece"
                color="brand"
            />

            <x-ui.stat-card
                label="Enabled"
                :value="app_number($stats['enabled'])"
                icon="check-circle"
                color="emerald"
                :href="route('admin.modules.index', ['state' => 'enabled'])"
            />

            <x-ui.stat-card
                label="Disabled"
                :value="app_number($stats['disabled'])"
                icon="eye-slash"
                color="rose"
                :href="route('admin.modules.index', ['state' => 'disabled'])"
            />

            <x-ui.stat-card
                label="Core (always on)"
                :value="app_number($stats['core'])"
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
                @php
                    $groupCount = count($group['modules']);
                    $switchable = count($group['toggleable']);
                    $groupOn = collect($group['modules'])->filter(fn ($module) => $module->isEnabled())->count();
                @endphp

                <section class="space-y-3">
                    <x-ui.section-heading
                        :title="$group['label']"
                        :subtitle="$groupOn.' of '.$groupCount.' '.Str::plural('module', $groupCount).' on'.($switchable === 0 ? ' · none switchable' : '')"
                        :divider="true"
                    >
                        <x-slot:actions>
                            <x-ui.badge :color="$group['color']" size="sm">{{ $group['label'] }}</x-ui.badge>

                            @if ($canToggle && $hasBulkRoute && $switchable > 0)
                                @include('admin.modules.partials.bulk-actions', [
                                    'group' => $group,
                                    'switchable' => $switchable,
                                ])
                            @endif
                        </x-slot:actions>
                    </x-ui.section-heading>

                    <div class="card-grid-3">
                        @foreach ($group['modules'] as $module)
                            @include('admin.modules.partials.card', [
                                'module' => $module,
                                'canToggle' => $canToggle,
                                'chips' => $dependencies[$module->slug] ?? ['dependencies' => [], 'dependents' => [], 'missing' => [], 'blocking' => []],
                                'routeCount' => (int) ($routeCounts[$module->slug] ?? 0),
                                'hasImpactRoute' => $hasImpactRoute,
                            ])
                        @endforeach
                    </div>
                </section>
            @endforeach

            <x-ui.card :compact="true">
                <x-ui.pagination-summary :paginator="$modules" label="modules" />
            </x-ui.card>
        @endif
    </div>

    {{-- One dialog for the whole page: the card that was clicked tells it which module it is. --}}
    @if ($canToggle && $hasImpactRoute)
        @include('admin.modules.partials.impact-modal')
    @endif
@endsection
