{{--
    Shared <head> for every layout (admin, panel, guest).

    Anything that must run before first paint goes here; everything else is deferred by Vite.
--}}

@php
    $appName = setting('company.name', config('app.name', 'My Office'));
    $faviconPath = setting('company.favicon_path', setting('company.logo_path'));

    $faviconUrl = null;

    if (filled($faviconPath)) {
        $faviconUrl = str_starts_with((string) $faviconPath, 'http')
            ? $faviconPath
            : \Illuminate\Support\Facades\Storage::disk('public')->url((string) $faviconPath);
    }
@endphp

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#f8fafc">
<meta name="robots" content="noindex, nofollow">

{{-- theme.js reads this to mirror the user's choice to the server; absent until the route exists. --}}
@if (Route::has('account.theme.update'))
    <meta name="theme-endpoint" content="{{ route('account.theme.update') }}">
@endif

<title>@hasSection('title')@yield('title') · {{ $appName }}@else{{ $appName }}@endif</title>

@if ($faviconUrl)
    <link rel="icon" href="{{ $faviconUrl }}">
@endif

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|figtree:400,500,600&display=swap" rel="stylesheet" />

@include('layouts.partials.theme-script')

@vite(['resources/css/app.css', 'resources/js/app.js'])

@stack('styles')
