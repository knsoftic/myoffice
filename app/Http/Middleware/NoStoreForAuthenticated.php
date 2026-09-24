<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a signed-in page being kept by the browser, or found by a search engine (phase-24-25 §6.3).
 *
 * **The back button is the attack.** Somebody signs out on a shared machine, the next person
 * presses Back, and the browser re-renders the page it still has in its history cache — the client
 * list, the payroll run, the commission ledger — without making a request. No middleware runs, no
 * session is checked, because nothing is asked of the server at all. `no-store` is what makes the
 * browser refuse to keep the page in the first place, which is the only point at which this can be
 * stopped.
 *
 * `must-revalidate` and the `Pragma` header cover older browsers and intermediate proxies that
 * predate `no-store`, and `private` says the response belongs to one person even where a shared
 * cache would otherwise be allowed to keep it.
 *
 * **`noindex, nofollow` on every panel route** is the other half. A panel page should never be in a
 * search index, and the way one gets there is not a crawler guessing URLs — it is a person pasting
 * a link somewhere public, or a browser extension reporting it.
 *
 * **Deliberately not applied to a guest response.** The public website's whole performance story is
 * the full-page cache (phase-03 §6.7), and a `no-store` on an anonymous page would disable it. The
 * rule is "authenticated, or a panel route", and nothing else.
 */
final class NoStoreForAuthenticated
{
    private const NO_STORE = 'no-store, no-cache, must-revalidate, private';

    /**
     * Route-name prefixes that are panels. A panel page is never indexable, signed in or not —
     * the sign-in screen of a panel is still not something a search engine should hold.
     */
    private const PANEL_PREFIXES = ['admin.', 'client.', 'student.', 'teacher.', 'collaborator.', 'account.'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $panel = $this->isPanelRoute($request);

        if ($panel) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // `auth()->hasUser()` rather than `check()`: this runs after the response is built, and
        // asking the guard to resolve a user here would fire a database query on every response
        // that does not already have one.
        if (auth()->hasUser() || $panel) {
            $response->headers->set('Cache-Control', self::NO_STORE);
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    private function isPanelRoute(Request $request): bool
    {
        $name = (string) ($request->route()?->getName() ?? '');

        if ($name === '') {
            return false;
        }

        foreach (self::PANEL_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
