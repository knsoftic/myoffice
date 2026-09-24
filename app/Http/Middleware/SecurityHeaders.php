<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Ops\CspBuilder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The response headers that tell a browser what it may and may not do (phase-24-25 §6.3).
 *
 * **Every header here is a defence the browser enforces, not the server.** That is the point of
 * them: they keep working after something has already gone wrong on this side. `nosniff` stops an
 * uploaded file being executed as script because the browser guessed at its type; `frame-ancestors`
 * stops this application being framed by a page that draws an invisible button over it; the CSP
 * stops an injected `<script>` from running even though it is in the HTML.
 *
 * **Global, so a route cannot forget it.** A header set per route group is a header some later
 * phase adds a route outside. SEC-08 walks every route and asserts the set arrives.
 *
 * **HSTS is sent only over HTTPS, and only when the setting says so.** Sent over plain HTTP the
 * header is ignored by specification, so sending it there would be noise; sent before the
 * certificate works it locks every visitor out of a site that cannot serve them, for the whole
 * `max-age`, with nothing the server can do to take it back. `security.hsts_enabled` is readonly
 * for that reason.
 */
final class SecurityHeaders
{
    /**
     * Headers that describe the server rather than the response.
     *
     * Removed where PHP allows. `Server` is usually set by Apache below PHP and cannot be unset
     * from here; the attempt is harmless and the manifest records it as a web-server task.
     */
    private const FINGERPRINT_HEADERS = ['X-Powered-By', 'Server'];

    public function __construct(private readonly CspBuilder $csp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A streamed or file response has its own content type and no HTML for a policy to govern,
        // but the fingerprint and sniffing headers still apply — an uploaded file served with the
        // wrong type is exactly what `nosniff` is for.
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', $this->csp->frameOptions());
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        );

        foreach (self::FINGERPRINT_HEADERS as $header) {
            $headers->remove($header);
        }

        // header_remove() reaches the ones PHP itself emitted; `Server` from Apache is out of
        // reach from here and is a web-server configuration item (§6.13).
        if (! headers_sent()) {
            foreach (self::FINGERPRINT_HEADERS as $header) {
                @header_remove($header);
            }
        }

        if ($this->shouldSendHsts($request)) {
            $headers->set('Strict-Transport-Security', $this->hstsValue());
        }

        if ($this->csp->enabled() && $this->carriesMarkup($response)) {
            $headers->set($this->csp->header(), $this->csp->build());
        }

        return $response;
    }

    /**
     * HSTS goes out only on a real HTTPS request, and only when the setting is on.
     */
    private function shouldSendHsts(Request $request): bool
    {
        return (bool) setting('security.hsts_enabled', false) && $request->isSecure();
    }

    private function hstsValue(): string
    {
        $maxAge = (int) setting('security.hsts_max_age', 31536000);
        $value = 'max-age='.max(0, $maxAge);

        if ((bool) setting('security.hsts_include_subdomains', false)) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }

    /**
     * Whether a CSP is worth attaching to this response.
     *
     * A policy on a CSV download or a streamed PDF governs nothing — there is no document for the
     * browser to apply it to — and the header would only be bytes on every export. JSON is
     * included: a JSON response rendered by a browser that decided it was HTML is precisely the
     * case `nosniff` and a policy together close.
     */
    private function carriesMarkup(Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        return $type === ''
            || str_contains($type, 'text/html')
            || str_contains($type, 'application/json')
            || str_contains($type, 'application/xhtml');
    }
}
