{{--
    The standalone 503 page shared by site/holding and site/maintenance (phase-03 §6.10, §8.14).

    Rendered by App\Http\Middleware\EnsurePublicSiteAvailable (alias `public_site`) with:
      $company  the company name
      $heading  "Scheduled maintenance" | "This website is currently unavailable." (or the view default)
      $message  maintenance.maintenance_message, printed as PLAIN TEXT (escaped, line breaks kept)
    and from the including view:
      $variant  'maintenance' | 'holding'

    Rules this page keeps, because it is what visitors and crawlers see while the site is down:
      · 503 comes from the middleware; this page says `noindex, nofollow` itself as well;
      · no header section, no navigation, no sign-in link, no stack trace, no analytics;
      · it must render even when half the stack is unavailable — every setting is read defensively,
        the Phase 3 helper may not be wired yet, and when the Vite build is missing the page falls back
        to a small inline stylesheet instead of failing;
      · brand colour and logo still apply (the same brand palette partial as the panels), and Light /
        Dark follows the visitor's stored choice or `appearance.default_theme`.
--}}

@php
    use Illuminate\Support\Facades\Storage;

    $variant = ($variant ?? 'holding') === 'maintenance' ? 'maintenance' : 'holding';

    $siteSetting = static fn (string $key, mixed $default = null): mixed => function_exists('site_setting')
        ? rescue(static fn () => site_setting($key, $default), $default)
        : $default;

    $companyName = trim((string) ($company ?? $siteSetting('company.name', '') ?? ''));
    $pageHeading = trim((string) ($heading ?? ''));
    $pageHeading = $pageHeading !== '' ? $pageHeading : ($variant === 'maintenance' ? 'Scheduled maintenance' : 'This website is currently unavailable.');
    $pageMessage = trim((string) ($message ?? ''));

    $email = trim((string) ($siteSetting('contact.email', '') ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    $phone = trim((string) ($siteSetting('contact.phone', '') ?? ''));
    $phoneHref = $phone !== '' ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;

    $year = rescue(static fn (): string => app_date(now(), 'Y'), static fn (): string => (string) now()->year, false);

    $hasBuild = is_file(public_path('build/manifest.json')) || is_file(public_path('hot'));

    $logoPath = $siteSetting('branding.logo_light');
    $logoUrl = null;

    if (is_string($logoPath) && trim($logoPath) !== '') {
        $logoUrl = preg_match('~^https?://~i', $logoPath) === 1
            ? $logoPath
            : rescue(static fn () => Storage::disk('public')->url(ltrim($logoPath, '/')), null, false);
    }

    $words = array_values(array_filter(preg_split('/\s+/', $companyName) ?: []));
    $initials = count($words) > 1
        ? mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1))
        : mb_strtoupper(mb_substr((string) ($words[0] ?? ''), 0, 2));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f8fafc">
    <title>{{ $pageHeading }}@if ($companyName !== '') · {{ $companyName }}@endif</title>

    @include('site.partials.theme-script')

    @if ($hasBuild)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('layouts.partials.brand-theme')
    @else
        <style>
            :root { color-scheme: light; --bg: #f8fafc; --card: #fff; --fg: #0f172a; --muted: #475569; --line: #e2e8f0; }
            html.dark { color-scheme: dark; --bg: #020617; --card: #0f172a; --fg: #f1f5f9; --muted: #94a3b8; --line: #1e293b; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: var(--bg); color: var(--fg); font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
            main { max-width: 34rem; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 1rem; padding: 2.5rem 2rem; text-align: center; }
            h1 { margin: 1rem 0 .75rem; font-size: 1.5rem; } p { margin: .5rem 0; color: var(--muted); white-space: pre-line; } a { color: inherit; }
            .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
        </style>
    @endif
</head>
<body class="min-h-screen bg-slate-50 font-sans text-base text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-16 sm:px-6">
        <div class="pointer-events-none absolute -top-40 left-1/2 -z-10 h-[32rem] w-[56rem] -translate-x-1/2 rounded-full bg-brand-500/15 blur-3xl dark:bg-brand-600/15" aria-hidden="true"></div>

        <main class="w-full max-w-xl">
            <div class="rounded-3xl border border-slate-200/80 bg-white/90 p-8 text-center shadow-xl backdrop-blur sm:p-12 dark:border-white/10 dark:bg-slate-900/80">
                <div class="flex justify-center">
                    @if ($logoUrl !== null)
                        <x-site.image :media="['url' => $logoUrl, 'alt' => $companyName]" profile="logo" :lazy="false" img-class="h-12 w-auto max-w-[12rem] object-contain" />
                    @elseif ($initials !== '')
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-base font-bold tracking-tight text-white shadow-sm" aria-hidden="true">{{ $initials }}</span>
                    @endif
                </div>

                @if ($companyName !== '')
                    <p class="mt-4 text-sm font-semibold tracking-tight text-slate-500 dark:text-slate-400">{{ $companyName }}</p>
                @endif

                <div class="mt-8 flex justify-center" aria-hidden="true">
                    <span @class([
                        'inline-flex h-12 w-12 items-center justify-center rounded-2xl ring-1 ring-inset',
                        'bg-amber-50 text-amber-600 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' => $variant === 'maintenance',
                        'bg-slate-100 text-slate-500 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10' => $variant === 'holding',
                    ])>
                        <x-ui.icon :name="$variant === 'maintenance' ? 'wrench-screwdriver' : 'globe-alt'" class="h-6 w-6" />
                    </span>
                </div>

                <h1 class="mt-6 text-balance text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">{{ $pageHeading }}</h1>

                @if ($pageMessage !== '')
                    <p class="mx-auto mt-4 max-w-md whitespace-pre-line text-base leading-relaxed text-slate-600 dark:text-slate-300">{{ $pageMessage }}</p>
                @endif

                @if ($email !== null || $phoneHref !== null)
                    <div class="mt-8 border-t border-slate-200 pt-6 dark:border-white/10">
                        <p class="text-sm text-slate-500 dark:text-slate-400">Need to reach us in the meantime?</p>
                        <ul role="list" class="mt-3 flex flex-col items-center justify-center gap-2 text-sm sm:flex-row sm:gap-6">
                            @if ($email !== null)
                                <li>
                                    <a href="mailto:{{ $email }}" class="inline-flex items-center gap-2 rounded font-medium text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-300 dark:hover:text-brand-200">
                                        <x-ui.icon name="envelope" class="h-4 w-4" />
                                        <span>{{ $email }}</span>
                                    </a>
                                </li>
                            @endif
                            @if ($phoneHref !== null)
                                <li>
                                    <a href="{{ $phoneHref }}" class="inline-flex items-center gap-2 rounded font-medium text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-300 dark:hover:text-brand-200">
                                        <x-ui.icon name="phone" class="h-4 w-4" />
                                        <span>{{ $phone }}</span>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </div>
                @endif
            </div>

            <p class="mt-8 text-center text-xs text-slate-500 dark:text-slate-500">&copy; {{ $year }}@if ($companyName !== '') {{ $companyName }}@endif</p>
        </main>
    </div>
</body>
</html>
