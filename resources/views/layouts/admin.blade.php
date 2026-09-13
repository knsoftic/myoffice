{{--
    Admin shell — every view under resources/views/admin/ extends this.

        @extends('layouts.admin')

        @section('title', 'Users')

        @section('header')
            <x-ui.page-header title="Users" subtitle="Everyone with access" icon="users">
                <x-slot:actions>…</x-slot:actions>
            </x-ui.page-header>
        @endsection

        @section('content')
            …
        @endsection

        @push('scripts') … @endpush
        @push('styles')  … @endpush

    Optional, from the controller:
        $breadcrumbs  overrides the derived breadcrumb bar
        $homeUrl      where the brand links to

    ---------------------------------------------------------------------------------------------
    Conditional rendering inside this shell: @module, @unlessmodule, @canany
    ---------------------------------------------------------------------------------------------

    `@module` is registered in AppServiceProvider::registerBladeDirectives() as
    `Blade::if('module', …)` over `App\Support\Modules::enabled($slug)`, which gives four
    directives from one registration:

        @module('notifications')
            <x-ui.icon-button icon="bell" label="Notifications" />   <-- module is on
        @elsemodule
            …                                                        <-- module is off
        @endmodule

        @unlessmodule('collaborators')
            <p>Referral tracking is switched off for this installation.</p>
        @endunlessmodule

    `$slug` is a `modules.slug` value (`notifications`, `projects`, `student_fees`), never a
    permission name and never a route name. The lookup is a cached in-memory map
    (`modules.enabled.map`), so it costs nothing to use per row. A real use of it is the
    notification bell in `layouts/partials/topbar`.

    What it is and is not for
    -------------------------
    A module switch is **presentation**, not authorization. `Gate::before` already denies every
    ability belonging to a disabled non-core module — for everyone, Super Admin included — so a
    route behind `can:` is closed whether or not the view checks. Wrap markup in `@module` to stop
    offering a door that is bolted (a nav item, a quick action, a dashboard card, a column of
    later-phase data); never rely on it as the only guard, and never use it to hide something a
    permission should be hiding.

    Permissions in a view use Laravel's own gate directives, and permission names are always
    `{module}.{ability}`:

        @can('users.create')            … @endcan
        @canany(['users.edit', 'users.change_status']) … @endcanany   <-- any one of them
        @canany(['users.edit', 'users.delete'])
            <x-ui.dropdown label="Row actions"> … </x-ui.dropdown>
        @elsecanany
            <span class="text-slate-400">No actions</span>
        @endcanany

    Use `@canany` rather than a chain of `@can`s whenever a container — a dropdown, a card, a
    toolbar, a whole table column — should disappear when the user holds none of the abilities
    inside it: an empty menu that opens onto nothing reads as a bug (carryover T19).

    The two compose, in this order, when a block is both module-gated and permission-gated:

        @module('collaborator_payouts')
            @canany(['collaborator_payouts.view_any', 'collaborator_payouts.approve'])
                …
            @endcanany
        @endmodule
--}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
</head>
{{--
    x-data on <body> makes the whole shell one Alpine scope. Without it, Alpine skips every
    directive that is not inside some x-data — which is most of the sidebar and topbar, since
    they drive off global stores rather than local state.
--}}
<body x-data class="min-h-full bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <a href="#main-content" class="sr-only focus-not-sr-only">Skip to content</a>

    @include('layouts.partials.sidebar', [
        'homeUrl' => $homeUrl ?? (\Illuminate\Support\Facades\Route::has('admin.dashboard') ? route('admin.dashboard') : url('/')),
    ])

    <div class="shell-content flex min-h-screen flex-col">
        @include('layouts.partials.topbar')
        @include('layouts.partials.breadcrumbs')

        <main id="main-content" class="flex-1">
            @hasSection('header')
                <div class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="mx-auto max-w-screen-2xl px-4 py-5 sm:px-6 sm:py-6 lg:px-8">
                        @yield('header')
                    </div>
                </div>
            @endif

            <div class="mx-auto max-w-screen-2xl p-4 sm:p-6 lg:p-8">
                @yield('content')
                {{ $slot ?? '' }}
            </div>
        </main>

        @include('layouts.partials.footer')
    </div>

    <x-ui.toast />

    @stack('modals')
    @stack('scripts')
</body>
</html>
