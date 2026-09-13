{{--
    Portal shell for the client, student, teacher and collaborator panels.

        @extends('layouts.panel')
        @section('title', 'My courses')
        @section('header') … @endsection
        @section('content') … @endsection

    Identical to layouts/admin except for a slimmer nav and a panel chip under the brand.
    The nav comes from the same App\Support\Sidebar — forUser() returns the portal tree for
    these roles, so there is exactly one place menus are declared.

    ---------------------------------------------------------------------------------------------
    Conditional rendering inside this shell: @module, @unlessmodule, @canany
    ---------------------------------------------------------------------------------------------

    `@module` is registered in AppServiceProvider::registerBladeDirectives() as
    `Blade::if('module', …)` over `App\Support\Modules::enabled($slug)`, which gives four
    directives from one registration:

        @module('collaborator_payouts')
            <x-ui.button :href="route('collaborator.payouts.create')">Request a payout</x-ui.button>
        @elsemodule
            <p class="text-slate-500">Payout requests are currently closed.</p>
        @endmodule

        @unlessmodule('messages')
            <p>Messaging is switched off for this installation.</p>
        @endunlessmodule

    `$slug` is a `modules.slug` value, never a permission name and never a route name. The lookup
    is a cached in-memory map (`modules.enabled.map`), so it costs nothing to use per row. A real
    use of it is the notification bell in `layouts/partials/topbar`, which this shell shares.

    What it is and is not for
    -------------------------
    A module switch is **presentation**, not authorization: `Gate::before` already denies every
    ability belonging to a disabled non-core module, for everyone. Wrap markup in `@module` so a
    portal stops offering a door that is bolted; never rely on it as the only guard.

    Note the one asymmetry that matters here: the four portal permission namespaces
    (`collaborator_portal.*`, `student_portal.*`, `teacher_portal.*`, `client_portal.*`) are
    **core** modules, so a `@module` check on them is always true and switching a module off can
    never close a whole panel (D20). Gate a portal feature on the business module whose data it
    shows — `collaborator_payouts`, `student_fees`, `messages` — not on the portal prefix.

    Permissions in a view use Laravel's own gate directives, and a portal permission is always
    `{portal}.{thing}`:

        @can('collaborator_portal.payout_request')  … @endcan
        @canany(['collaborator_portal.projects', 'collaborator_portal.project_value'])
            <x-ui.card title="Projects"> … </x-ui.card>
        @elsecanany
            <x-ui.empty-state title="Nothing shared with you yet" />
        @endcanany

    Use `@canany` rather than a chain of `@can`s whenever a container — a card, a toolbar, a whole
    table column — should disappear when the user holds none of the abilities inside it: an empty
    card that explains nothing reads as a bug (carryover T19).
--}}

@php
    $panelUser = auth()->user();
    $panelType = null;

    if ($panelUser && method_exists($panelUser, 'primaryPanel')) {
        try {
            $panelType = $panelUser->primaryPanel();
        } catch (\Throwable) {
            $panelType = null;
        }
    }

    $panelLabel = $panelType instanceof \App\Enums\PanelType
        ? $panelType->label().' portal'
        : 'Portal';

    $panelColor = $panelType instanceof \App\Enums\PanelType ? $panelType->color() : 'brand';

    $panelHome = url('/');

    if ($panelType instanceof \App\Enums\PanelType && \Illuminate\Support\Facades\Route::has($panelType->homeRoute())) {
        try {
            $panelHome = route($panelType->homeRoute());
        } catch (\Throwable) {
            $panelHome = url('/');
        }
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
</head>
{{-- x-data scopes the whole shell for Alpine; see the note in layouts/admin.blade.php. --}}
<body x-data class="min-h-full bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <a href="#main-content" class="sr-only focus-not-sr-only">Skip to content</a>

    @include('layouts.partials.sidebar', [
        'homeUrl' => $homeUrl ?? $panelHome,
        'panelLabel' => $panelLabel,
        'panelColor' => $panelColor,
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
