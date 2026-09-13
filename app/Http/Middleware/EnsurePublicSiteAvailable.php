<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: `public_site` (phase-02 §2 `maintenance` group, §6 "Maintenance").
 *
 *   Route::get('/', …)->middleware('public_site');
 *
 * Two settings, one gate:
 *
 *   · `maintenance.public_site_enabled` off  — the public website is not published at all.
 *   · `maintenance.maintenance_mode` on      — the website is published but temporarily closed,
 *                                              showing `maintenance.maintenance_message`.
 *
 * Both answer **503 Service Unavailable** with a holding page, which is what tells a crawler the
 * absence is temporary; a 200 would invite it to index the holding page in place of the real site.
 *
 * The admin panel and the four portals can never be blocked by this — not because the middleware
 * checks the path, but because it is attached only to public routes. That is deliberate: a gate
 * that decides "is this request public?" from the URL is one rename away from locking every
 * administrator out of the screen that turns it off again. Phase 3 applies the same alias to its
 * own public route group and replaces the inline page with `site.maintenance`.
 *
 * `site_module` (D26) is a different gate: it 404s one content area. This one closes the site.
 */
final class EnsurePublicSiteAvailable
{
    /** How long a crawler should wait before asking again (seconds). */
    private const RETRY_AFTER = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        if (! setting('maintenance.public_site_enabled', true)) {
            return $this->holdingPage(
                'This website is currently unavailable.',
                (string) (setting('maintenance.maintenance_message') ?: 'The public website has been switched off. Please try again later.'),
            );
        }

        if (setting('maintenance.maintenance_mode', false)) {
            return $this->holdingPage(
                'Scheduled maintenance',
                (string) (setting('maintenance.maintenance_message') ?: 'We are performing scheduled maintenance. Please check back shortly.'),
            );
        }

        return $next($request);
    }

    /**
     * The holding page, as a 503.
     *
     * Phase 3 owns `resources/views/site/**`; the moment `site.maintenance` exists it takes over,
     * exactly as the `home` route hands over to `site.home`.
     */
    private function holdingPage(string $heading, string $message): Response
    {
        $company = (string) (setting('company.name') ?: config('app.name', 'My Office'));

        if (View::exists('site.maintenance')) {
            return response()
                ->view('site.maintenance', [
                    'company' => $company,
                    'heading' => $heading,
                    'message' => $message,
                ], 503)
                ->header('Retry-After', (string) self::RETRY_AFTER);
        }

        $name = e($company);
        $title = e($heading);
        $body = e($message);
        $year = date('Y');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>{$title} — {$name}</title>
                <style>
                    :root { --bg:#f1f5f9; --card:#fff; --fg:#0f172a; --muted:#64748b; --border:#e2e8f0; }
                    @media (prefers-color-scheme: dark) {
                        :root { --bg:#020617; --card:#0f172a; --fg:#e2e8f0; --muted:#94a3b8; --border:#1e293b; }
                    }
                    * { box-sizing: border-box; }
                    html, body { height: 100%; }
                    body {
                        margin: 0; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
                        background: var(--bg); color: var(--fg); line-height: 1.6;
                        font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                    }
                    .card {
                        width: 100%; max-width: 30rem; padding: 2.5rem 2rem; text-align: center;
                        border: 1px solid var(--border); border-radius: 1rem; background: var(--card);
                        box-shadow: 0 12px 32px -20px rgba(15, 23, 42, .35);
                    }
                    h1 { margin: 0 0 .75rem; font-size: 1.375rem; font-weight: 700; letter-spacing: -.015em; }
                    p { margin: 0; color: var(--muted); }
                    footer { margin-top: 2rem; color: var(--muted); font-size: .8125rem; }
                </style>
            </head>
            <body>
                <main class="card">
                    <h1>{$title}</h1>
                    <p>{$body}</p>
                    <footer>&copy; {$year} {$name}</footer>
                </main>
            </body>
            </html>
            HTML;

        return response($html, 503)->header('Retry-After', (string) self::RETRY_AFTER);
    }
}
