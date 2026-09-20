<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Collaborator\ReferralLinkService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notice `?ref=COL-1001` on a public page and remember it (phase-08-09 §6.4, §7.6).
 *
 * Appended to the **public `web` group only**, never to the five panel groups: a signed-in member of
 * staff following a partner's link is not a referral, and recording it would put a visit row under
 * their own user id for the resolver to argue with later.
 *
 * **It never blocks the page.** No redirect, no exception, no 500 — a referral link is a marketing link
 * and a visitor who followed one must see the page whatever this code makes of their code.
 *
 * **It keeps working when the visit register is switched off.** Disabling
 * `collaborator_referral_visits` hides a screen; it must not cost a partner their commission, so
 * attribution continues through the session and the cookie (§9's module-gating row).
 */
final class CaptureReferral
{
    public function __construct(
        private readonly ReferralLinkService $links,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only a plain page view. A POST carrying `?ref=` in its action is a form submission, and the
        // resolver reads the carriers there rather than opening a new visit mid-conversion.
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $this->links->capture($request);
        }

        return $next($request);
    }
}
