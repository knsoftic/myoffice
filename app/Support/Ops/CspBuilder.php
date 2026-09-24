<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Support\Str;

/**
 * Builds the Content-Security-Policy header, and owns the per-request nonce (phase-24-25 §6.3).
 *
 * **A CSP is a list of the places a browser may fetch code from.** Everything not on the list is
 * refused by the browser itself, which is what makes it the one defence that still works after an
 * injected `<script>` has made it into the HTML. It cannot stop the injection; it stops the
 * injected script from running.
 *
 * **The nonce is the reason this is a service and not a constant.** A policy that allowed every
 * inline script would allow the injected one too, so each response gets a fresh random value, the
 * layouts stamp it on the inline scripts the application wrote, and anything else is refused. The
 * value is generated once per request and never reused — a nonce an attacker can predict is not a
 * nonce, it is a password everybody knows.
 *
 * **`'unsafe-eval'` is a deliberate, documented concession** (§12 Q4). Alpine 3 compiles every
 * `x-on`, `x-show` and `x-text` expression with `new Function`, which a policy without
 * `'unsafe-eval'` blocks — every interactive component in twenty-three phases of screens would stop
 * working. The alternative is Alpine's CSP build, which requires rewriting every expression in the
 * codebase as a component method. This is recorded rather than hidden: `'unsafe-eval'` widens what
 * `script-src` permits to code the page constructs at runtime, and it does **not** re-permit
 * injected `<script>` tags, which the nonce still governs.
 *
 * **`style-src` carries `'unsafe-inline'` for the same kind of reason** and a weaker one: Alpine's
 * `x-show` writes `style="display:none"`, and `style-src` has no nonce path that survives that.
 * An injected stylesheet is a far smaller problem than injected script.
 *
 * Report-only by default (`security.csp_report_only`). A policy that blocks before anybody has read
 * a violation report breaks a working screen to prevent nothing in particular; the go-live
 * checklist flips it once the report log is clean.
 */
final class CspBuilder
{
    /**
     * The header name when the policy is enforced.
     */
    public const HEADER_ENFORCE = 'Content-Security-Policy';

    /**
     * The header name when it only reports.
     */
    public const HEADER_REPORT = 'Content-Security-Policy-Report-Only';

    /**
     * This request's nonce. Generated on first read, then stable for the rest of the request.
     */
    private ?string $nonce = null;

    /**
     * The value every inline `<script>` in a layout must carry.
     *
     * 16 random bytes, base64-encoded: the CSP specification asks for at least 128 bits of entropy,
     * and this is exactly that.
     */
    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Whether a policy should be sent at all.
     */
    public function enabled(): bool
    {
        return (bool) setting('security.csp_enabled', true);
    }

    /**
     * Whether the policy reports instead of blocking.
     */
    public function reportOnly(): bool
    {
        return (bool) setting('security.csp_report_only', true);
    }

    /**
     * The header name to send this policy under.
     */
    public function header(): string
    {
        return $this->reportOnly() ? self::HEADER_REPORT : self::HEADER_ENFORCE;
    }

    /**
     * The policy, as the header value.
     */
    public function build(): string
    {
        $directives = [
            "default-src 'self'",
            // The nonce is what lets the application's own inline scripts run while an injected
            // one does not. 'unsafe-eval' is Alpine — see the class note.
            sprintf("script-src 'self' 'nonce-%s' 'unsafe-eval'", $this->nonce()),
            "style-src 'self' 'unsafe-inline'",
            // `data:` for the inline SVG icons and the base64 logo preview; `blob:` for the client
            // side image preview before an upload is posted.
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            sprintf('frame-ancestors %s', $this->frameAncestors()),
            // A form that posts somewhere else is the shape of a credential-harvesting injection,
            // and nothing in this application ever needs to.
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        $reportUri = $this->reportUri();

        if ($reportUri !== null) {
            $directives[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $directives);
    }

    /**
     * Who may put this application in a frame: `'none'` or `'self'`, and nothing else.
     *
     * There is no third option in `security.frame_ancestors` on purpose — an admin panel inside
     * somebody else's frame is a clickjacking target, and no screen here needs to be embedded.
     */
    public function frameAncestors(): string
    {
        return setting('security.frame_ancestors', 'none') === 'self' ? "'self'" : "'none'";
    }

    /**
     * The `X-Frame-Options` value that says the same thing to an older browser.
     */
    public function frameOptions(): string
    {
        return setting('security.frame_ancestors', 'none') === 'self' ? 'SAMEORIGIN' : 'DENY';
    }

    /**
     * Where violation reports go, or null.
     *
     * Only an absolute http(s) URL is accepted. A relative or malformed value is dropped rather
     * than emitted: a `report-uri` the browser cannot parse invalidates nothing, but one pointing
     * at an unintended host would send it the URL of every page a violation happened on.
     */
    private function reportUri(): ?string
    {
        $uri = setting('security.csp_report_uri');

        if (! is_string($uri) || trim($uri) === '') {
            return null;
        }

        $uri = trim($uri);

        if (! Str::startsWith($uri, ['http://', 'https://'])) {
            return null;
        }

        // A semicolon or a comma would end the directive early and start another one.
        return preg_match('/[;,\s]/', $uri) === 1 ? null : $uri;
    }
}
