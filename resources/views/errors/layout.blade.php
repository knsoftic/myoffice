{{--
    The shell every error page uses (phase-24-25 §6.3, GL-49 / SEC-37).

    **It is deliberately self-contained, and that is the whole design.** Every other layout in this
    application reads settings, which reads the database; resolves the sidebar, which reads
    permissions; and loads the compiled bundle through Vite, which needs a build manifest. An error
    page that needs any of those cannot render the three failures it most needs to render — the
    database being down, the cache being unreachable, and a deploy that has not finished building.
    So this page has no `@vite`, no components, no settings read that is not wrapped, and its CSS is
    inline.

    It still looks like the application. The brand colour and the company name are read through a
    `try` so a page rendered with no database falls back to the framework defaults rather than
    throwing a second exception on top of the first — which is how an error page turns a 500 into a
    blank white screen.

    Dark mode is `prefers-color-scheme` rather than the stored preference, for the same reason: the
    preference lives on the user row.

    Sections:
      @section('code')      the status number
      @section('title')     the short heading
      @section('message')   a sentence or two
      @section('actions')   optional extra buttons, rendered before "Go back"
      @section('own-back')  any value: this page supplies its own way back, so the default
                            "Go back" button is not rendered as well

    **No inline JavaScript anywhere on these pages**, including `onclick`. The Content-Security-Policy
    this phase ships governs inline handlers as strictly as it governs inline `<script>`, and an
    attribute cannot carry a nonce — so an error page with an `onclick` would render a dead button in
    production and work perfectly in development. Everything here is a link.
--}}

@php
    // Supplied by the `errors.*` view composer (AppServiceProvider), which is where the settings
    // reads and the same-origin guard live - a child template's sections are captured before this
    // layout runs, so 419 could not see a variable defined here. The fallbacks cover a page
    // rendered outside the composer, in a test that renders the view directly.
    $errorAppName ??= (string) config('app.name', 'My Office');
    $errorBrand ??= '#2563eb';
    $errorBack ??= url('/');
@endphp
    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- An error page is never something a search engine should hold. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $errorAppName }}</title>
    <style>
        :root {
            --brand: {{ $errorBrand }};
            --bg: #f8fafc;
            --card: #ffffff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #020617;
                --card: #0f172a;
                --ink: #f1f5f9;
                --muted: #94a3b8;
                --line: #1e293b;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            max-width: 32rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04), 0 8px 24px rgb(15 23 42 / 0.06);
        }

        .code {
            display: inline-block;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--brand);
            margin: 0 0 12px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        p {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 0.975rem;
        }

        .actions {
            margin-top: 28px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
        }

        a.button {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 0.925rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--line);
            color: var(--ink);
            background: transparent;
            transition: background-color .15s ease, border-color .15s ease;
        }

        a.button:hover { border-color: var(--brand); }

        a.button.primary {
            background: var(--brand);
            border-color: var(--brand);
            color: #ffffff;
        }

        a.button.primary:hover { filter: brightness(1.08); }

        .brand {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            font-size: 0.8rem;
            color: var(--muted);
        }

        @media (max-width: 420px) {
            .card { padding: 28px 20px; }
            a.button { width: 100%; }
        }
    </style>
</head>
<body>
<main class="card" role="main">
    <p class="code">@yield('code')</p>

    <h1>@yield('title')</h1>

    @yield('message')

    <div class="actions">
        @yield('actions')

        @unless (trim($__env->yieldContent('own-back')) !== '')
            <a class="button" href="{{ $errorBack }}">Go back</a>
        @endunless
    </div>

    <p class="brand">{{ $errorAppName }}</p>
</main>
</body>
</html>
