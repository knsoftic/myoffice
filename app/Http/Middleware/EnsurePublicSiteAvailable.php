<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aliases: `site` (phase-03 §6.10) and `public_site` (phase-02 §6 "Maintenance") — one gate, two names.
 *
 *   Route::get('/', …)->middleware('site');
 *
 * Two settings, one gate:
 *
 *   · `maintenance.public_site_enabled` off  — the public website is not published at all
 *                                              (`site.holding`).
 *   · `maintenance.maintenance_mode` on      — the website is published but temporarily closed,
 *                                              showing `maintenance.maintenance_message`
 *                                              (`site.maintenance`).
 *
 * Both answer **503 Service Unavailable** with `Retry-After` and `X-Robots-Tag: noindex`, which is what
 * tells a crawler the absence is temporary; a 200 would invite it to index the holding page in place of
 * the real site.
 *
 * **Staff bypass (phase-03 §6.10).** A signed-in user holding `website_sections.view` sees the real site
 * with an amber ribbon naming the state (request attribute `site_state`, read by `site.layouts.public`).
 * The permission decides, never the login: a signed-in student or client is an ordinary visitor (§9).
 * That response is personal, so it is marked `private, no-store` and `noindex`.
 *
 * The admin panel and the four portals can never be blocked by this — not because the middleware checks
 * the path, but because it is attached only to public routes. A gate that decides "is this request
 * public?" from the URL is one rename away from locking every administrator out of the screen that
 * turns it off again. robots.txt never carries it ([D-W3-13]).
 *
 * `site_module` (D26) is a different gate: it 404s one content area. This one closes the site.
 */
final class EnsurePublicSiteAvailable
{
    /** How long a crawler should wait before asking again (seconds). */
    private const RETRY_AFTER = 3600;

    /** Who may browse a closed site (phase-03 §6.10). */
    private const BYPASS_PERMISSION = 'website_sections.view';

    public function handle(Request $request, Closure $next): Response
    {
        $state = match (true) {
            ! setting('maintenance.public_site_enabled', true) => 'disabled',
            (bool) setting('maintenance.maintenance_mode', false) => 'maintenance',
            default => null,
        };

        if ($state === null) {
            return $next($request);
        }

        if ($request->user()?->can(self::BYPASS_PERMISSION) === true) {
            $request->attributes->set('site_state', $state);

            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        return $state === 'disabled'
            ? $this->holdingPage(
                'site.holding',
                'This website is currently unavailable.',
                (string) (setting('maintenance.maintenance_message') ?: 'The public website has been switched off. Please try again later.'),
            )
            : $this->holdingPage(
                'site.maintenance',
                'Scheduled maintenance',
                (string) (setting('maintenance.maintenance_message') ?: 'We are performing scheduled maintenance. Please check back shortly.'),
            );
    }

    /**
     * The holding page, as a 503: the state's own view, else `site.maintenance`, else the inline page.
     */
    private function holdingPage(string $view, string $heading, string $message): Response
    {
        $company = (string) (setting('company.name') ?: config('app.name', 'My Office'));

        foreach ([$view, 'site.maintenance'] as $candidate) {
            if (View::exists($candidate)) {
                return response()
                    ->view($candidate, [
                        'company' => $company,
                        'heading' => $heading,
                        'message' => $message,
                    ], 503)
                    ->header('Retry-After', (string) self::RETRY_AFTER)
                    ->header('X-Robots-Tag', 'noindex');
            }
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

        return response($html, 503)
            ->header('Retry-After', (string) self::RETRY_AFTER)
            ->header('X-Robots-Tag', 'noindex');
    }
}
