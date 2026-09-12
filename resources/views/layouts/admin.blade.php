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
