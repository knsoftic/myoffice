{{--
    Guest / authentication shell — split screen: brand story on the left, form card on the right.
    Below lg the brand panel collapses to a compact band above the form.

    Works both ways, so the auth views can use either convention:

        <x-guest-layout> … </x-guest-layout>        // Breeze component style

        @extends('layouts.guest')                    // section style
        @section('title', 'Sign in')
        @section('content') … @endsection

    Settings read: company.name, company.tagline, company.features (a JSON list, or one entry per
    line), the logo from branding.logo_dark / branding.logo_light, the brand panel image from
    branding.login_background, and the credit line switch appearance.show_powered_by. The theme
    these pages open in is appearance.default_theme (see layouts/partials/theme-script). Every one
    of them is declared in App\Support\SettingsRegistry, so every one of them is editable on the
    settings screen — a view may never read a key the registry does not declare.

    branding.login_background is shown to signed-out visitors, so it can only ever be served from
    the public disk. The layout asks SettingsService::diskFor() where the field lives and renders
    the image only when that answer is the public disk: a file on the private disk is never given
    a URL here (D21), whatever the row holds.
--}}

@php
    $authName = setting('company.name', config('app.name', 'My Office'));
    $authTagline = setting('company.tagline', 'One system for your software house and training institute.');

    $rawFeatures = setting('company.features');

    if (is_string($rawFeatures) && filled($rawFeatures)) {
        $decoded = json_decode($rawFeatures, true);
        $authFeatures = is_array($decoded)
            ? $decoded
            : preg_split('/\r\n|\r|\n/', $rawFeatures);
    } elseif (is_array($rawFeatures)) {
        $authFeatures = $rawFeatures;
    } else {
        $authFeatures = [
            'Projects, tasks and client billing in one place',
            'Admissions, batches, attendance and fees for the institute',
            'Role-based access with a full audit trail',
        ];
    }

    $authFeatures = collect($authFeatures)
        ->map(fn ($feature) => is_string($feature) ? trim($feature) : null)
        ->filter()
        ->take(4);

    // The brand panel is the brand gradient in both themes, so the dark-background variant is
    // the right one here; the light logo is the fallback when only one has been uploaded.
    $authLogoPath = setting('branding.logo_dark') ?: setting('branding.logo_light');
    $authLogoUrl = filled($authLogoPath)
        ? (str_starts_with((string) $authLogoPath, 'http')
            ? $authLogoPath
            : \Illuminate\Support\Facades\Storage::disk('public')->url((string) $authLogoPath))
        : null;

    $authBackgroundPath = setting('branding.login_background');
    $authBackgroundUrl = null;

    if (is_string($authBackgroundPath) && trim($authBackgroundPath) !== '') {
        $authBackgroundPath = trim($authBackgroundPath);

        if (str_starts_with($authBackgroundPath, 'http')) {
            $authBackgroundUrl = $authBackgroundPath;
        } elseif (\App\Services\Core\SettingsService::diskFor('branding.login_background') === \App\Services\Core\SettingsService::PUBLIC_DISK) {
            $authBackgroundUrl = \Illuminate\Support\Facades\Storage::disk(\App\Services\Core\SettingsService::PUBLIC_DISK)->url($authBackgroundPath);
        }
    }

    $authPoweredBy = (bool) setting('appearance.show_powered_by', true);
    $authProduct = trim((string) config('app.name', 'My Office'));

    $authWords = array_values(array_filter(preg_split('/\s+/', trim((string) $authName)) ?: []));
    $authInitials = count($authWords) > 1
        ? mb_strtoupper(mb_substr($authWords[0], 0, 1).mb_substr($authWords[1], 0, 1))
        : mb_strtoupper(mb_substr((string) ($authWords[0] ?? 'M'), 0, 2));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('layouts.partials.head')
</head>
{{-- x-data scopes the whole shell for Alpine; see the note in layouts/admin.blade.php. --}}
<body x-data class="min-h-full bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="flex min-h-screen flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-0">
        {{-- Brand panel --}}
        <div class="relative isolate overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-brand-800 px-6 py-8 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:px-12 lg:py-14">
            @if ($authBackgroundUrl)
                {{--
                    The administrator's image, under a brand-tinted scrim so the white copy stays
                    readable over any photograph. First in the DOM, so every later positioned child
                    paints above it without a z-index. The scrim is an inline gradient over the
                    runtime --brand-* variables: it follows branding.brand_color in both themes.
                --}}
                <img
                    src="{{ $authBackgroundUrl }}"
                    alt=""
                    class="pointer-events-none absolute inset-0 h-full w-full object-cover"
                    decoding="async"
                    aria-hidden="true"
                    data-login-background
                />
                <div
                    class="pointer-events-none absolute inset-0"
                    style="background-image: linear-gradient(135deg, rgb(var(--brand-800) / 0.88) 0%, rgb(var(--brand-600) / 0.72) 55%, rgb(var(--brand-900) / 0.9) 100%);"
                    aria-hidden="true"
                ></div>
            @endif

            {{-- Decorative glows --}}
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-white/10 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-20 h-80 w-80 rounded-full bg-brand-400/25 blur-3xl" aria-hidden="true"></div>

            <div class="relative">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-3">
                    @if ($authLogoUrl)
                        <img src="{{ $authLogoUrl }}" alt="{{ $authName }}" class="h-10 w-10 rounded-lg bg-white/10 object-contain p-1" />
                    @else
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-white/15 text-sm font-bold tracking-tight ring-1 ring-inset ring-white/25" aria-hidden="true">
                            {{ $authInitials }}
                        </span>
                    @endif

                    <span class="text-base font-semibold tracking-tight">{{ $authName }}</span>
                </a>
            </div>

            <div class="relative mt-8 lg:mt-0">
                {{-- A <p>, not an <h1>: every page using this layout renders its own heading
                     ("Sign in", "Reset your password"), and two <h1> elements leave a
                     screen-reader user with no single answer to "what page is this". This is
                     branding beside the form. The type scale is unchanged, so nothing moves. --}}
                <p class="max-w-lg text-2xl font-semibold leading-snug tracking-tight sm:text-3xl lg:text-4xl lg:leading-tight">
                    {{ $authTagline }}
                </p>

                @if ($authFeatures->isNotEmpty())
                    <ul class="mt-6 hidden space-y-3 sm:block lg:mt-8">
                        @foreach ($authFeatures as $feature)
                            <li class="flex items-start gap-3 text-sm text-white/85">
                                <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/15 ring-1 ring-inset ring-white/25">
                                    <x-ui.icon name="check" class="h-3 w-3" />
                                </span>
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <p class="relative mt-8 hidden text-xs text-white/60 lg:mt-0 lg:block">
                &copy; {{ now()->year }} {{ $authName }}

                @if ($authPoweredBy && $authProduct !== '')
                    <span data-powered-by>&middot; Powered by {{ $authProduct }}</span>
                @endif
            </p>
        </div>

        {{-- Form panel --}}
        <div class="relative flex flex-1 items-center justify-center px-4 py-10 sm:px-6 lg:px-12">
            {{-- Theme switcher stays reachable on the sign-in screen --}}
            <div class="absolute right-4 top-4 flex items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 sm:right-6 sm:top-6 dark:bg-slate-800" role="group" aria-label="Colour theme">
                @foreach ([['light', 'sun', 'Light'], ['dark', 'moon', 'Dark'], ['system', 'monitor', 'Match system']] as [$tValue, $tIcon, $tLabel])
                    <button
                        type="button"
                        x-on:click="$store.theme.set('{{ $tValue }}')"
                        x-bind:aria-pressed="$store.theme.is('{{ $tValue }}').toString()"
                        x-bind:class="$store.theme.is('{{ $tValue }}')
                            ? 'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400'
                            : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-md transition-colors duration-150"
                        aria-label="{{ $tLabel }} theme"
                        title="{{ $tLabel }}"
                    >
                        <x-ui.icon :name="$tIcon" class="h-4 w-4" />
                    </button>
                @endforeach
            </div>

            <div class="w-full max-w-md">
                @hasSection('heading')
                    <div class="mb-6">@yield('heading')</div>
                @endif

                <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70 sm:p-8 dark:bg-slate-900 dark:ring-slate-800">
                    @yield('content')
                    {{ $slot ?? '' }}
                </div>

                @hasSection('below')
                    <div class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">@yield('below')</div>
                @endif
            </div>
        </div>
    </div>

    <x-ui.toast />

    @stack('modals')
    @stack('scripts')
</body>
</html>
