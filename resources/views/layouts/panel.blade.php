{{--
    Portal shell for the client, student, teacher and collaborator panels.

        @extends('layouts.panel')
        @section('title', 'My courses')
        @section('header') … @endsection
        @section('content') … @endsection

    Identical to layouts/admin except for a slimmer nav and a panel chip under the brand.
    The nav comes from the same App\Support\Sidebar — forUser() returns the portal tree for
    these roles, so there is exactly one place menus are declared.
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
