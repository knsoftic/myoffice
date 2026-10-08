@props([
    'logoLight' => null,
    'logoDark' => null,
    'name' => null,
    'showName' => true,
    'href' => null,
    'size' => 'md',
    'preferDark' => false,
])

{{--
    x-site.brand — the company mark: the logo (light and dark variants) **or** the name, never both.

    Exactly one of three things renders, in this order:
      · a logo, when one is uploaded — and then the name is its alt text, not a second label;
      · the company name, when there is no logo and `showName` is on;
      · a monogram of the name, when there is no logo and `showName` is off.

        <x-site.brand :logo-light="$media['logo_override_light'] ?? null"
                      :logo-dark="$media['logo_override_dark'] ?? null"
                      :show-name="$content['show_company_name'] ?? true" />

    Source order, first filled wins (phase-03 §8.6, §8.14):
      · the header (or footer) section's own logo override — a published media array;
      · the CANONICAL branding keys `branding.logo_light` / `branding.logo_dark`, read through
        `site_setting()`; both are `public => true` in SettingsRegistry;
      · a monogram of the company name in the brand colour, so a site with no logo uploaded yet
        still has a mark rather than a broken image.

    `preferDark` is for surfaces that are always dark (the footer): the dark-background logo is shown
    in both themes, falling back to the light one.

    Every image goes through `<x-site.image>` — there is no bare image tag in the public site.

    Accessible name: the logo's alt text is the company name, because the name is no longer printed
    beside it; when the name is printed (no logo), the link's text is the name; when only the
    monogram shows, the link carries an `aria-label`. A screen reader hears the name once in each case.

    Settings are read defensively (`function_exists` + `rescue`) because this component is also
    used by the holding and maintenance pages, which Phase 2's middleware renders before every
    Phase 3 helper is necessarily wired in; a missing value degrades to the monogram, never a 500.
--}}

@php
    use App\Services\Cms\SettingsImageService;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Storage;

    // Never rescued (INV-10): a key that is not public must fail the render, not quietly print its default.
    $siteSetting = static fn (string $key, mixed $default = null): mixed => site_setting($key, $default);

    // A settings image is a path on the public disk (or, rarely, an absolute URL).
    $publicUrl = static function (mixed $path): ?string {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        return rescue(static fn () => Storage::disk('public')->url(ltrim($path, '/')), null, false);
    };

    /*
    | The settings logo, through the one public image pipeline.
    |
    | A settings image is only a path, so it used to be handed on as `['url' => $url]` -- no srcset,
    | no WebP and, worse, no intrinsic dimensions for the browser to reserve header space with. The
    | live site was serving a 4167x1571 PNG of 141 KB to paint a 36-pixel-tall mark, twice per page
    | (light and dark are both in the markup), and shifting its own header while it loaded.
    |
    | `SettingsImageService::snapshot()` returns the same array a published section carries, or null.
    | Null is not a failure to handle: it means "nothing better than the original", which is exactly
    | the old behaviour. Rescued as well as null-safe because this component also renders the holding
    | and maintenance pages, where a container that is not ready yet must not cost the only page the
    | site has.
    */
    $settingsMedia = static function (mixed $path) use ($publicUrl): ?array {
        $url = $publicUrl($path);

        if ($url === null) {
            return null;
        }

        $snapshot = rescue(
            static fn (): ?array => app(SettingsImageService::class)->snapshot(is_string($path) ? $path : null, 'logo'),
            null,
            false,
        );

        if (is_array($snapshot)) {
            return $snapshot;
        }

        // No derivatives came back. A path on the public disk that is not actually there is not a
        // logo: rendering it is a broken image, and now that a logo *replaces* the name instead of
        // sitting beside it, a broken image would leave a header with no name in it at all. So a
        // missing file counts as no logo and the name takes its place. An absolute URL cannot be
        // checked from here and is trusted, as before; if the disk check itself fails, the old
        // behaviour (render the URL) is kept rather than guessing.
        $local = is_string($path) && preg_match('~^https?://~i', $path) !== 1;

        if ($local && ! rescue(static fn (): bool => Storage::disk('public')->exists(ltrim($path, '/')), true, false)) {
            return null;
        }

        return ['url' => $url];
    };

    $companyName = trim((string) ($name ?? $siteSetting('company.name', '') ?? ''));

    $lightMedia = filled(data_get($logoLight, 'url'))
        ? $logoLight
        : $settingsMedia($siteSetting('branding.logo_light'));

    $darkMedia = filled(data_get($logoDark, 'url'))
        ? $logoDark
        : $settingsMedia($siteSetting('branding.logo_dark'));

    // An explicit override always wins; only the settings fallback swaps to the dark-background logo.
    if ($preferDark && $darkMedia !== null && ! filled(data_get($logoLight, 'url'))) {
        $lightMedia = $darkMedia;
    }

    /*
    | One mark, never two.
    |
    | An uploaded logo is almost always a wordmark — knsoftic.com's says "KN Softic" in the image itself
    | — so printing the name beside it put the company's name on screen twice in the header, twice in
    | the mobile drawer and twice in the footer. The owner asked for exactly one: the logo when there is
    | one, the name when there is not.
    |
    | So the name is shown only when no logo will be rendered, and `showName` now decides between the
    | name and the monogram for a site with no logo yet. With a logo the name moves into the image's
    | alt text below, so a screen reader still hears it — once.
    |
    | "Will be rendered" means `$lightMedia`, because that is the only branch of the template that
    | draws a logo: a dark-only upload with no light one falls through to the name, as it always fell
    | through to the monogram.
    */
    $hasLogo = $lightMedia !== null;
    $nameVisible = ! $hasLogo && (bool) $showName && $companyName !== '';
    $altText = $nameVisible ? '' : $companyName;

    $withAlt = static fn (?array $media): ?array => $media === null ? null : array_merge($media, ['alt' => $altText]);

    $lightMedia = $withAlt(is_array($lightMedia) ? $lightMedia : null);
    $darkMedia = $preferDark ? null : $withAlt(is_array($darkMedia) ? $darkMedia : null);

    $logoHeight = match ($size) {
        'sm' => 'h-8',
        'lg' => 'h-11',
        default => 'h-9',
    };

    $monogramSize = match ($size) {
        'sm' => 'h-8 w-8 text-xs',
        'lg' => 'h-11 w-11 text-base',
        default => 'h-9 w-9 text-sm',
    };

    $nameSize = match ($size) {
        'sm' => 'text-sm',
        'lg' => 'text-lg',
        default => 'text-base',
    };

    $words = array_values(array_filter(preg_split('/\s+/', $companyName) ?: []));
    $initials = count($words) > 1
        ? mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1))
        : mb_strtoupper(mb_substr((string) ($words[0] ?? ''), 0, 2));

    $target = $href ?? (Route::has('site.home') ? route('site.home') : url('/'));
@endphp

<a
    href="{{ $target }}"
    {{ $attributes->class('group inline-flex min-w-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-slate-950') }}
    @if (! $nameVisible && $companyName !== '' && $lightMedia === null) aria-label="{{ $companyName }}" @endif
>
    @if ($lightMedia !== null)
        <x-site.image
            :media="$lightMedia"
            profile="logo"
            :lazy="false"
            @class(['shrink-0', 'dark:hidden' => $darkMedia !== null])
            img-class="{{ $logoHeight }} w-auto max-w-[11rem] object-contain"
        />

        @if ($darkMedia !== null)
            <x-site.image
                :media="$darkMedia"
                profile="logo"
                :lazy="false"
                class="hidden shrink-0 dark:block"
                img-class="{{ $logoHeight }} w-auto max-w-[11rem] object-contain"
            />
        @endif
    @elseif ($initials !== '' && ! $nameVisible)
        {{-- Only when the name is off: a monogram beside the name is two marks again. --}}
        <span
            class="{{ $monogramSize }} inline-flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 font-bold tracking-tight text-white shadow-sm ring-1 ring-inset ring-white/20"
            aria-hidden="true"
        >{{ $initials }}</span>
    @endif

    @if ($nameVisible)
        <span class="{{ $nameSize }} truncate font-semibold tracking-tight text-slate-900 dark:text-white">{{ $companyName }}</span>
    @endif
</a>
