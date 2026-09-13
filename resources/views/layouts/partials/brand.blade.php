{{--
    The brand block: logo from settings with an initials fallback.

    Included with variables:
        @include('layouts.partials.brand', ['href' => route('admin.dashboard'), 'size' => 'md'])

    Settings read: company.name, company.short_name, company.tagline, and the logo from
    `branding.logo_light` / `branding.logo_dark` (phase-02 §2) — the only rows the Branding screen
    writes. The Phase 1 `company.logo_path` / `company.logo_dark_path` rows are NOT read any more:
    they are superseded, marked readonly and labelled "Deprecated - use …" by
    `2026_09_12_060400_supersede_relocated_setting_keys`, which carried their values into the
    branding rows. Reading both halves was the bug — an uploaded logo changed one row while the
    shell rendered the other.

    When a dark logo is configured the two images are both rendered and swapped by the theme
    class, so the shell never shows a dark-on-dark wordmark; with only one logo that single image
    is used in both themes.
--}}

@php
    $brandName = setting('company.name', config('app.name', 'My Office'));
    $brandShort = setting('company.short_name');
    $brandTagline = ($showTagline ?? false) ? setting('company.tagline') : null;

    $brandAssetUrl = static function (mixed $path): ?string {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return str_starts_with($path, 'http')
            ? $path
            : \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    };

    $logoUrl = $brandAssetUrl(setting('branding.logo_light'));
    $logoDarkUrl = $brandAssetUrl(setting('branding.logo_dark'));

    // "My Office ERP" => "MO"; single word => first two letters.
    $words = preg_split('/\s+/', trim((string) $brandName)) ?: [];
    $words = array_values(array_filter($words));

    if (count($words) > 1) {
        $brandInitials = mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
    } else {
        $brandInitials = mb_strtoupper(mb_substr((string) ($words[0] ?? 'M'), 0, 2));
    }

    $href = $href ?? url('/');
    $markSize = ($size ?? 'md') === 'lg' ? 'h-11 w-11 text-base' : 'h-9 w-9 text-sm';
@endphp

<a
    href="{{ $href }}"
    class="rail-trigger rail-center group relative flex min-w-0 items-center gap-3 rounded-lg px-1 py-1 transition-colors hover:bg-slate-100/70 dark:hover:bg-slate-800/60"
>
    @if ($logoUrl || $logoDarkUrl)
        @php
            // One logo: used by both themes. Two: each hidden in the other's theme.
            $logoLight = $logoUrl ?: $logoDarkUrl;
            $logoDark = $logoDarkUrl ?: $logoUrl;
            $swap = $logoLight !== $logoDark;
        @endphp

        <img
            src="{{ $logoLight }}"
            alt="{{ $brandName }}"
            class="{{ $markSize }} shrink-0 rounded-lg object-contain {{ $swap ? 'dark:hidden' : '' }}"
        />

        @if ($swap)
            <img
                src="{{ $logoDark }}"
                alt="{{ $brandName }}"
                class="{{ $markSize }} hidden shrink-0 rounded-lg object-contain dark:block"
            />
        @endif
    @else
        <span
            class="{{ $markSize }} inline-flex shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 font-bold tracking-tight text-white shadow-sm ring-1 ring-inset ring-white/10"
            aria-hidden="true"
        >{{ $brandInitials }}</span>
    @endif

    <span class="rail-hide min-w-0">
        <span class="block truncate text-sm font-semibold tracking-tight text-slate-900 dark:text-white">
            {{ $brandShort ?: $brandName }}
        </span>

        @if (filled($brandTagline))
            <span class="block truncate text-2xs text-slate-500 dark:text-slate-400">{{ $brandTagline }}</span>
        @endif
    </span>

    {{-- Tooltip shown only while the sidebar is a rail. --}}
    <span class="rail-tooltip absolute left-full top-1/2 z-dropdown ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white shadow-lg dark:bg-slate-700">
        {{ $brandName }}
    </span>
</a>
