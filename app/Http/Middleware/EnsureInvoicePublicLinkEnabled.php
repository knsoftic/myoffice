<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public invoice link is a setting, and switching it off must actually close the door
 * (phase-13 §7.8).
 *
 * **404, never 403.** A 403 would confirm that a link exists and merely is not being served, which
 * tells an unauthenticated stranger something about the business. A 404 says nothing at all — which is
 * the same answer they get for a rotated token, a draft and a cancelled invoice.
 */
final class EnsureInvoicePublicLinkEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) setting('finance.invoice_public_link_enabled', true), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
