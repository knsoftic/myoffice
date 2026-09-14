@props([
    'seo' => null,
    'title' => null,
    'preview' => false,
    'robots' => null,
    'analytics' => true,
])

{{--
    x-site.seo — everything search engines and social networks read from the head of a public page
    (phase-03 §6.5, §8.14, requirement §105).

        <x-site.seo :seo="$site->seo" :preview="$site->isPreview" />
        <x-site.seo title="Page not found" robots="noindex, nofollow" />

    `seo` is the `App\Services\Cms\Data\SeoPayload` that `SeoService::for()` resolved — the per-field
    fallback chain and the strictest-wins robots rule are already applied there, so this component
    only escapes and prints. The array form (`SeoPayload::toArray()`) works too, which is what a
    cached or serialised payload looks like.

    An explicit `title` (error pages) wins over the payload's title; with no payload at all it is `title`
    plus the company name. An explicit `robots` prop also drops the canonical link: a page that must not
    be indexed should not claim to be the canonical copy of anything.

    Robots, strictest wins: a preview is always `noindex, nofollow` (INV-9) whatever the payload says;
    an explicit `robots` prop is used by error and holding pages.

    Analytics — Google Tag Manager, Google Analytics 4 and the Meta pixel from Phase 2's `seo.*` keys —
    render only when their key is set **and** matches the vendor's id format (so a pasted snippet can
    never become script), and never in preview. Each id reaches JavaScript through `@json`, never by
    string concatenation. Every script is async; nothing third-party blocks rendering.
--}}

@php
    use Illuminate\Support\Facades\Storage;

    // Never rescued (INV-10): a key that is not public must fail the render, not quietly print its default.
    $siteSetting = static fn (string $key, mixed $default = null): mixed => site_setting($key, $default);

    $read = static fn (string $camel, string $snake): mixed => data_get($seo, $camel) ?? data_get($seo, $snake);

    $companyName = trim((string) ($siteSetting('company.name', '') ?? ''));

    // An explicit `title` (an error page) wins over the payload, which describes the route it borrowed.
    $explicitTitle = trim((string) $title);
    $pageTitle = $explicitTitle !== '' ? '' : trim((string) ($read('title', 'title') ?? ''));

    if ($pageTitle === '') {
        $pageTitle = $explicitTitle === ''
            ? $companyName
            : ($companyName !== '' && ! str_contains($explicitTitle, $companyName) ? $explicitTitle.' | '.$companyName : $explicitTitle);
    }

    $description = $read('metaDescription', 'meta_description');
    $keywords = $read('metaKeywords', 'meta_keywords');
    $canonical = (string) ($read('canonicalUrl', 'canonical_url') ?? url()->current());

    $robotsHeader = is_object($seo) && method_exists($seo, 'robotsHeader')
        ? $seo->robotsHeader()
        : data_get($seo, 'robots_header');

    if (filled($robots)) {
        $robotsHeader = (string) $robots;
    }

    if ($preview) {
        $robotsHeader = 'noindex, nofollow';
    }

    $ogTitle = (string) ($read('ogTitle', 'og_title') ?? $pageTitle);
    $ogDescription = $read('ogDescription', 'og_description') ?? $description;
    $ogImage = $read('ogImageUrl', 'og_image_url');
    $ogType = (string) ($read('ogType', 'og_type') ?? 'website');
    $siteName = (string) ($read('siteName', 'site_name') ?? $companyName);
    $locale = (string) ($read('locale', 'locale') ?? 'en');
    $imageWidth = $read('imageWidth', 'image_width');
    $imageHeight = $read('imageHeight', 'image_height');

    // Open Graph wants language_TERRITORY; the setting holds a bare language.
    $ogLocale = match ($locale) {
        'en' => 'en_US',
        'ur' => 'ur_PK',
        default => str_replace('-', '_', $locale),
    };

    $faviconPath = $siteSetting('branding.favicon');
    $faviconUrl = null;
    $faviconType = null;

    if (is_string($faviconPath) && trim($faviconPath) !== '') {
        $faviconUrl = preg_match('~^https?://~i', $faviconPath) === 1
            ? $faviconPath
            : rescue(static fn () => Storage::disk('public')->url(ltrim($faviconPath, '/')), null, false);

        $faviconType = match (strtolower(pathinfo($faviconPath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => null,
        };
    }

    $verification = trim((string) ($siteSetting('seo.google_site_verification') ?? ''));
    $verification = preg_match('/^[A-Za-z0-9_\-]{10,190}$/', $verification) === 1 ? $verification : null;

    $gtmId = null;
    $gaId = null;
    $pixelId = null;

    if ($analytics && ! $preview) {
        $gtmId = strtoupper(trim((string) ($siteSetting('seo.google_tag_manager_id') ?? '')));
        $gtmId = preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtmId) === 1 ? $gtmId : null;

        $gaId = strtoupper(trim((string) ($siteSetting('seo.google_analytics_id') ?? '')));
        $gaId = preg_match('/^G-[A-Z0-9]{4,16}$/', $gaId) === 1 ? $gaId : null;

        $pixelId = trim((string) ($siteSetting('seo.facebook_pixel_id') ?? ''));
        $pixelId = preg_match('/^\d{6,20}$/', $pixelId) === 1 ? $pixelId : null;
    }
@endphp

<title>{{ $pageTitle }}</title>

@if (filled($description))
    <meta name="description" content="{{ $description }}">
@endif

@if (filled($keywords))
    <meta name="keywords" content="{{ $keywords }}">
@endif

@if (filled($robotsHeader))
    <meta name="robots" content="{{ $robotsHeader }}">
@endif

@if (preg_match('~^https?://~i', $canonical) === 1 && ! $preview && blank($robots))
    <link rel="canonical" href="{{ $canonical }}">
@endif

<meta property="og:type" content="{{ $ogType }}">
<meta property="og:title" content="{{ $ogTitle }}">
@if (filled($ogDescription))
    <meta property="og:description" content="{{ $ogDescription }}">
@endif
<meta property="og:url" content="{{ $canonical }}">
@if ($siteName !== '')
    <meta property="og:site_name" content="{{ $siteName }}">
@endif
<meta property="og:locale" content="{{ $ogLocale }}">
@if (filled($ogImage))
    <meta property="og:image" content="{{ $ogImage }}">
    @if (filled($imageWidth) && filled($imageHeight))
        <meta property="og:image:width" content="{{ (int) $imageWidth }}">
        <meta property="og:image:height" content="{{ (int) $imageHeight }}">
    @endif
@endif

<meta name="twitter:card" content="{{ filled($ogImage) ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $ogTitle }}">
@if (filled($ogDescription))
    <meta name="twitter:description" content="{{ $ogDescription }}">
@endif
@if (filled($ogImage))
    <meta name="twitter:image" content="{{ $ogImage }}">
@endif

@if ($verification !== null)
    <meta name="google-site-verification" content="{{ $verification }}">
@endif

@if ($faviconUrl !== null)
    <link rel="icon" href="{{ $faviconUrl }}" @if ($faviconType !== null) type="{{ $faviconType }}" @endif>
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
@endif

@if ($gtmId !== null)
    <script>
        (function (w, d, s, l, i) {
            w[l] = w[l] || [];
            w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
            var f = d.getElementsByTagName(s)[0], j = d.createElement(s), dl = l !== 'dataLayer' ? '&l=' + l : '';
            j.async = true;
            j.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(i) + dl;
            f.parentNode.insertBefore(j, f);
        })(window, document, 'script', 'dataLayer', @json($gtmId));
    </script>
@endif

@if ($gaId !== null)
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { window.dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', @json($gaId), { anonymize_ip: true });
    </script>
@endif

@if ($pixelId !== null)
    <script>
        !function (f, b, e, v, n, t, s) {
            if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
            if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
            t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', @json($pixelId));
        fbq('track', 'PageView');
    </script>
@endif
