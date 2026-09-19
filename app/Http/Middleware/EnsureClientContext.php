<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Alias: `client.context` (phase-05 §6.9, §9.2, D31, F-12.3).
 *
 *   Route::prefix('client')->middleware(['auth', 'active', 'panel:client', 'client.context'])
 *
 * Runs on **every** `/client` route, after `panel:client`, and answers 403 with an explanatory page when:
 *
 *   · `crm.client_portal_enabled` is off (the master switch — `/admin` is unaffected, test 78);
 *   · the signed-in user resolves to no client (no `clients.user_id`, no `client_contacts.user_id` with
 *     `portal_access`);
 *   · the client's `portal_enabled` is false, or its status forbids the portal (`ClientStatus::canUsePortal()`),
 *     or the contact's portal access was revoked.
 *
 * `ClientContext` is scoped to the request and re-resolved from the database on every request, so revoking access
 * takes effect on the next click, not at the next login (test 63). The middleware never reads a client id from the
 * request — controllers take it from `ClientContext` alone (CLAUDE.md rule 10).
 */
final class EnsureClientContext
{
    /** The `crm` settings group key of the master switch (phase-05 §5). */
    private const PORTAL_SWITCH = 'client_portal_enabled';

    public function __construct(
        private readonly ClientContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->portalSwitchedOn()) {
            abort(Response::HTTP_FORBIDDEN, 'The client portal is switched off at the moment. Please contact your account manager.');
        }

        // Resolve afresh for this request: a scoped instance survives between requests handled by one application
        // (the test client, a long-running worker), and a stale "yes" would outlive a revocation.
        $this->context->forget();

        if ($this->context->inspect() !== null) {
            abort(Response::HTTP_FORBIDDEN, $this->context->message());
        }

        return $next($request);
    }

    /**
     * `crm.client_portal_enabled` (default true). An unreadable settings store keeps the default rather than
     * locking every client out.
     */
    private function portalSwitchedOn(): bool
    {
        try {
            $value = settings_repo()->get('crm.'.self::PORTAL_SWITCH, true);
        } catch (Throwable) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
