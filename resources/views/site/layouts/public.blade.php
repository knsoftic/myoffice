{{--
    The public website layout (phase-03 §8.14). Every public page extends it:

        @extends('site.layouts.public')
        @section('title', 'Page not found')          optional: fallback title when there is no SEO payload
        @section('robots', 'noindex, nofollow')      optional: explicit robots for error pages
        @section('content') … @endsection

    Receives:
      $site   App\Support\SitePayload (or the same keys as an array): header, footer, sections, seo,
              page, isPreview, bodyClass. Every read goes through data_get(), so a page rendered with
              no payload at all (an error page) still gets a working shell: the brand from settings,
              the theme toggle, and a footer built from Phase 2's contact and social settings.
      $page   optional; only its title and slug are read here, for the preview ribbon.
      $previewSection  optional section id (section preview), named in the preview ribbon.

    It owns, so no page or section repeats them:
      · the head: <x-site.seo>, the pre-paint theme script (appearance.default_theme), the Vite bundle,
        and the SAME runtime brand palette partial the admin shell uses (layouts.partials.brand-theme),
        so `branding.brand_color` repaints the public site with no rebuild and no colour is hardcoded;
      · landmarks: a skip link, the header section, one <main id="content">, the footer section;
      · the header-over-hero decision (only when the first section is a hero that has a background
        image or video AND the header section asks for it);
      · the back-to-top button, and the amber ribbon for a preview or for staff browsing a site that is
        in maintenance or switched off (request attribute `site_state`: 'maintenance' | 'disabled').

    Deliberately absent: no request-forgery token meta tag and no flash toasts. Public pages are stored
    in the version-stamped page cache (D22), so nothing per-visitor may be printed into them; a later
    public form fetches its token when it needs one.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $site = $site ?? null;
    $seo = data_get($site, 'seo');
    $isPreview = (bool) data_get($site, 'isPreview', false);
    $header = data_get($site, 'header');
    $footer = data_get($site, 'footer');
    $bodyClass = trim((string) data_get($site, 'bodyClass', ''));
    $siteState = request()->attributes->get('site_state');

    $firstSection = collect(data_get($site, 'sections', []))->first();
    $firstSnapshot = is_array(data_get($firstSection, 'published_content')) ? data_get($firstSection, 'published_content') : $firstSection;
    $overlayHeader = data_get($firstSnapshot, 'section_key') === 'hero'
        && (filled(data_get($firstSnapshot, 'media.background_image.url'))
            || filled(data_get($firstSnapshot, 'media.background_video.url'))
            || filled(data_get($firstSnapshot, 'media.video_poster.url')))
        && (bool) data_get($header, 'fields.transparent_over_hero', false);

    $pageData = $page ?? data_get($site, 'page');
    $previewTarget = data_get($pageData, 'title') ?? (isset($previewSection) ? 'Section #'.$previewSection : null);
    $pageSlug = data_get($pageData, 'slug');
    $exitUrl = filled($pageSlug) && Route::has('site.page')
        ? route('site.page', ['slug' => $pageSlug])
        : (Route::has('site.home') ? route('site.home') : url('/'));
    // A reviewer on a signed link has no account: offer the editor only to a signed-in user.
    $editorUrl = auth()->check() && Route::has('admin.website.index') ? route('admin.website.index') : null;

    $ribbonMode = $isPreview ? 'preview' : (in_array($siteState, ['maintenance', 'disabled'], true) ? $siteState : null);

    $htmlLang = str_replace('_', '-', (string) (data_get($seo, 'locale') ?: app()->getLocale()));

    $fontsHref = 'https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap';
@endphp

<!DOCTYPE html>
<html lang="{{ $htmlLang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f8fafc">
    <meta name="format-detection" content="telephone=no">

    <x-site.seo
        :seo="$seo"
        :preview="$isPreview"
        :title="trim($__env->yieldContent('title'))"
        :robots="trim($__env->yieldContent('robots')) ?: null"
    />

    {{-- Fonts load without blocking the first paint; the system stack stands in until they arrive. --}}
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="stylesheet" href="{{ $fontsHref }}" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="{{ $fontsHref }}"></noscript>

    @include('site.partials.theme-script')
    @include('site.partials.fx-script')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('layouts.partials.brand-theme')

    @stack('head')
</head>
<body
    x-data
    @class([
        'min-h-screen bg-white font-sans text-base text-slate-700 antialiased selection:bg-brand-500/20 dark:bg-slate-950 dark:text-slate-300',
        'pb-24 sm:pb-14' => $ribbonMode !== null,
        $bodyClass => $bodyClass !== '',
    ])
>
    <a href="#content" class="sr-only focus-not-sr-only">Skip to content</a>

    @include('site.sections.header', [
        'section' => $header,
        'content' => (array) data_get($header, 'fields', []),
        'items' => (array) data_get($header, 'items', []),
        'media' => (array) data_get($header, 'media', []),
        'menus' => (array) data_get($header, 'menus', []),
        'overlay' => $overlayHeader,
    ])

    <main id="content" tabindex="-1" class="outline-none">
        @yield('content')
    </main>

    @include('site.sections.footer', [
        'section' => $footer,
        'content' => (array) data_get($footer, 'fields', []),
        'items' => (array) data_get($footer, 'items', []),
        'media' => (array) data_get($footer, 'media', []),
        'menus' => (array) data_get($footer, 'menus', []),
    ])

    <div
        x-data="{ show: false }"
        x-on:scroll.window.passive="show = window.scrollY > 720"
        @class([
            'fixed right-4 z-topbar sm:right-6',
            'bottom-28 sm:bottom-20' => $ribbonMode !== null,
            'bottom-4 sm:bottom-6' => $ribbonMode === null,
        ])
    >
        <button
            type="button"
            x-show="show"
            x-cloak
            x-transition.opacity
            x-on:click="window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); document.getElementById('content')?.focus({ preventScroll: true })"
            class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/95 text-slate-700 shadow-lg ring-1 ring-slate-200 backdrop-blur transition duration-150 hover:-translate-y-0.5 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-800/95 dark:text-slate-200 dark:ring-white/10 dark:hover:text-white"
        >
            <x-ui.icon name="chevron-up" class="h-5 w-5" />
            <span class="sr-only">Back to top</span>
        </button>
    </div>

    @if ($ribbonMode !== null)
        <x-site.preview-ribbon
            :mode="$ribbonMode"
            :target="$previewTarget"
            :exit-url="$ribbonMode === 'preview' ? $exitUrl : null"
            :editor-url="$editorUrl"
        />
    @endif

    @stack('scripts')
</body>
</html>
