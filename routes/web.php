<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Public routes (phase-01 §8)
|--------------------------------------------------------------------------
|
| Phase 1 exposes exactly one public route: a branded holding page. The real,
| CMS-driven public website (sections, menus, pages, services, blog, SEO) is
| Phase 3, which replaces this route with App\Http\Controllers\Site\* actions
| and the resources/views/site/** views — the route name `home` stays.
|
| Authentication (login, password reset, email verification) and the /account
| screens live in routes/auth.php, required at the bottom of this file.
| The five panel route files are loaded by bootstrap/app.php (withRouting
| then:), each declaring its own prefix, name prefix and panel middleware.
|
*/

Route::get('/', function (): Response {
    $company = (string) (setting('company.name') ?: config('app.name', 'My Office'));
    $tagline = (string) (setting('company.tagline') ?: 'One system for your software house and training institute.');

    // Phase 3 owns resources/views/site/**; hand over to it the moment it exists.
    if (View::exists('site.home')) {
        return response()->view('site.home', [
            'company' => $company,
            'tagline' => $tagline,
        ]);
    }

    $initials = Str::of($company)
        ->explode(' ')
        ->filter()
        ->map(static fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
        ->take(2)
        ->implode('');

    $name = e($company);
    $mark = e($initials !== '' ? $initials : 'MO');
    $lead = e($tagline);
    $year = date('Y');
    $loginUrl = Route::has('login') ? e(route('login')) : null;
    $signIn = $loginUrl === null
        ? ''
        : '<a class="action" href="'.$loginUrl.'">Sign in to the panel</a>';

    $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>{$name}</title>
            <style>
                :root {
                    --bg: #f1f5f9;
                    --card: #ffffff;
                    --fg: #0f172a;
                    --muted: #64748b;
                    --border: #e2e8f0;
                    --brand: #4f46e5;
                    --brand-soft: #eef2ff;
                    --brand-fg: #ffffff;
                }
                @media (prefers-color-scheme: dark) {
                    :root {
                        --bg: #020617;
                        --card: #0f172a;
                        --fg: #e2e8f0;
                        --muted: #94a3b8;
                        --border: #1e293b;
                        --brand: #6366f1;
                        --brand-soft: #1e1b4b;
                        --brand-fg: #ffffff;
                    }
                }
                * { box-sizing: border-box; }
                html, body { height: 100%; }
                body {
                    margin: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 1.5rem;
                    background: var(--bg);
                    color: var(--fg);
                    font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                    line-height: 1.6;
                    -webkit-font-smoothing: antialiased;
                }
                .card {
                    width: 100%;
                    max-width: 32rem;
                    padding: 2.5rem 2rem;
                    border: 1px solid var(--border);
                    border-radius: 1rem;
                    background: var(--card);
                    box-shadow: 0 12px 32px -20px rgba(15, 23, 42, .35);
                    text-align: center;
                }
                .mark {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 3.25rem;
                    height: 3.25rem;
                    margin-bottom: 1.25rem;
                    border-radius: .875rem;
                    background: var(--brand);
                    color: var(--brand-fg);
                    font-size: 1.125rem;
                    font-weight: 700;
                    letter-spacing: .04em;
                }
                h1 {
                    margin: 0 0 .5rem;
                    font-size: 1.5rem;
                    font-weight: 700;
                    letter-spacing: -.015em;
                }
                .lead { margin: 0 0 1.75rem; color: var(--muted); }
                .notice {
                    padding: .875rem 1rem;
                    border: 1px solid var(--border);
                    border-radius: .75rem;
                    background: var(--brand-soft);
                    color: var(--fg);
                    font-size: .875rem;
                    text-align: left;
                }
                .notice strong { font-weight: 600; }
                .action {
                    display: inline-block;
                    margin-top: 1.75rem;
                    padding: .625rem 1.25rem;
                    border-radius: .625rem;
                    background: var(--brand);
                    color: var(--brand-fg);
                    font-size: .9375rem;
                    font-weight: 600;
                    text-decoration: none;
                }
                .action:hover { filter: brightness(1.08); }
                footer {
                    margin-top: 2rem;
                    color: var(--muted);
                    font-size: .8125rem;
                }
                @media (max-width: 480px) {
                    .card { padding: 2rem 1.25rem; }
                    h1 { font-size: 1.25rem; }
                }
            </style>
        </head>
        <body>
            <main class="card">
                <div class="mark" aria-hidden="true">{$mark}</div>
                <h1>{$name}</h1>
                <p class="lead">{$lead}</p>
                <p class="notice">
                    <strong>Website coming in Phase 3.</strong>
                    This is a temporary holding page. The full public website — pages, services,
                    courses, portfolio and blog — is managed from the admin CMS and goes live in
                    Phase 3 of the build.
                </p>
                {$signIn}
                <footer>&copy; {$year} {$name}</footer>
            </main>
        </body>
        </html>
        HTML;

    return response($html);
})->name('home');

require __DIR__.'/auth.php';
