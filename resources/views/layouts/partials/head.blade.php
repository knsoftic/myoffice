{{--
    Shared <head> for every layout (admin, panel, guest).

    Anything that must run before first paint goes here; everything else is deferred by Vite.
--}}

@php
    $appName = setting('company.name', config('app.name', 'My Office'));

    // The favicon and the logo live in the `branding` group — the rows the Branding screen
    // writes. The Phase 1 `company.favicon_path` / `company.logo_path` rows are superseded by
    // `2026_09_12_060400_supersede_relocated_setting_keys`, which copied their values here, so
    // reading them as a second fallback would only reintroduce the split. The logo stands in for
    // a favicon nobody has uploaded yet; first filled value wins.
    $faviconPath = setting('branding.favicon')
        ?: setting('branding.logo_light');

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

{{--
    The runtime brand palette comes AFTER the stylesheet on purpose: app.css ships the indigo
    fallback on bare `:root`, this overrides it from `branding.brand_color`. The partial also
    raises its own specificity (`html:root`) so the override does not depend on this order —
    both belts, because a page that silently loses its brand colour is hard to notice and
    trivial to reintroduce.
--}}
@include('layouts.partials.brand-theme')

@stack('styles')
