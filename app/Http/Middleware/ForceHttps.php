<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps every request on HTTPS (phase-24-25 §6.3).
 *
 * **A redirect is right for a read and wrong for a write.** A plain-HTTP GET is redirected, because
 * the request carried nothing but a URL and the visitor simply typed the wrong scheme. A plain-HTTP
 * POST, PUT, PATCH or DELETE has already sent its body — a password, an invoice line, a commission
 * rate — across the network in clear text. Redirecting it would ask the browser to send the same
 * body again to the right address, which protects the second copy and not the first. Refusing it
 * with a 403 is the only honest answer: the data is already exposed, and the write must not appear
 * to have succeeded safely.
 *
 * **Skipped on a local machine**, which has no certificate: forcing HTTPS there redirects into
 * nothing and makes the application unreachable. The check is the environment, not the setting, so
 * an inherited `security.force_https = true` cannot break a developer's machine.
 *
 * `URL::forceScheme('https')` is what makes every generated link — every redirect, every form
 * action, every asset URL — start out on HTTPS, so the redirect below is a safety net rather than
 * the mechanism.
 */
final class ForceHttps
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldForce($request)) {
            return $next($request);
        }

        URL::forceScheme('https');

        if ($request->isSecure()) {
            return $next($request);
        }

        if (in_array($request->method(), self::READ_METHODS, true)) {
            // 301, not 302: this is a permanent property of the address, and a cached permanent
            // redirect means the browser stops making the insecure request at all.
            return redirect()->to(
                $this->secureUrl($request),
                301,
            );
        }

        // See the class note — the body is already in the clear, and a redirect would only
        // protect the copy that has not been sent yet.
        abort(403, 'This request must be made over HTTPS.');
    }

    /**
     * Whether HTTPS is required for this request.
     *
     * The local environment and the test runner are excluded by environment rather than by setting:
     * a developer machine that inherited a production settings row must still be reachable.
     */
    private function shouldForce(Request $request): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return false;
        }

        return (bool) setting('security.force_https', true);
    }

    /**
     * The same URL, on https.
     *
     * Built from the request's own path and query rather than from `URL::full()`, so nothing a
     * client put in the `Host` header can redirect a visitor somewhere else.
     */
    private function secureUrl(Request $request): string
    {
        $query = $request->getQueryString();

        return 'https://'.$request->getHttpHost().$request->getPathInfo()
            .($query === null || $query === '' ? '' : '?'.$query);
    }
}
