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
    x-site.brand — the company mark: logo (light and dark variants) plus, optionally, the name.

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

    Accessible name: when the company name is printed beside the logo the logo is decorative
    (empty alt), so a screen reader hears the name once, not twice.

    Settings are read defensively (`function_exists` + `rescue`) because this component is also
    used by the holding and maintenance pages, which Phase 2's middleware renders before every
    Phase 3 helper is necessarily wired in; a missing value degrades to the monogram, never a 500.
--}}

@php
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Facades\Storage;

    $siteSetting = static fn (string $key, mixed $default = null): mixed => function_exists('site_setting')
        ? rescue(static fn () => site_setting($key, $default), $default)
        : $default;

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

    $companyName = trim((string) ($name ?? $siteSetting('company.name', '') ?? ''));

    $lightMedia = filled(data_get($logoLight, 'url'))
        ? $logoLight
        : (($url = $publicUrl($siteSetting('branding.logo_light'))) !== null ? ['url' => $url] : null);

    $darkMedia = filled(data_get($logoDark, 'url'))
        ? $logoDark
        : (($url = $publicUrl($siteSetting('branding.logo_dark'))) !== null ? ['url' => $url] : null);

    // An explicit override always wins; only the settings fallback swaps to the dark-background logo.
    if ($preferDark && $darkMedia !== null && ! filled(data_get($logoLight, 'url'))) {
        $lightMedia = $darkMedia;
    }

    $nameVisible = (bool) $showName && $companyName !== '';
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
    @elseif ($initials !== '')
        <span
            class="{{ $monogramSize }} inline-flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 font-bold tracking-tight text-white shadow-sm ring-1 ring-inset ring-white/20"
            aria-hidden="true"
        >{{ $initials }}</span>
    @endif

    @if ($nameVisible)
        <span class="{{ $nameSize }} truncate font-semibold tracking-tight text-slate-900 dark:text-white">{{ $companyName }}</span>
    @endif
</a>
