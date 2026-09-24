<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `GET /health` — what an unauthenticated monitoring agent is allowed to know (phase-24-25 §7.3,
 * §11.9 DEP-22).
 *
 * **This is the one endpoint in twenty-five phases that answers a stranger with facts about the
 * installation**, so every rule below is about limiting what "a stranger with the token" and "a
 * stranger without it" each learn. `/up` stays as the bare liveness probe: 200 and nothing else,
 * no token, no facts.
 *
 * **404 is the answer to every refusal.** Wrong token, missing token, endpoint switched off — all
 * 404, never 403. A 403 says "this endpoint exists and is merely closed", which is an invitation to
 * come back with a better guess; a 404 is the same answer the framework gives for `/heath`,
 * `/status` and every other URL nobody registered.
 *
 * **The token never travels in a query string.** A query string is written to the web server access
 * log, sent in the `Referer` header of anything the response links to, and kept in browser history
 * and proxy logs — so `?token=` would leak a long-lived credential into four places nobody
 * remembers to rotate. Header or signed URL only (SEC-34 asserts it).
 *
 * **The body is counts, ages, booleans and versions.** Never a row, a name, an email, a balance or
 * a path above the base directory. {@see SystemHealthService::publicPayload()} is where that is
 * enforced, and it drops the prose fields rather than sanitising them.
 *
 * Rate limiting is the route's `throttle:health` (30/minute per IP, §6.3.1): the token stops
 * disclosure, the limiter stops the endpoint being a free way to make the server work.
 */
final class HealthController extends Controller
{
    /** The header a monitoring agent puts the token in. */
    private const TOKEN_HEADER = 'X-Health-Token';

    public function __invoke(Request $request, SystemHealthService $health): JsonResponse|Response
    {
        /*
        | Off means gone, not forbidden. `ops.health_endpoint_enabled = false` is how an operator
        | closes the endpoint, and a closed door that announces itself is a door somebody comes
        | back to — so it answers exactly what an unregistered URL answers.
        */
        if (! (bool) $this->setting('ops.health_endpoint_enabled', true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        /*
        | No token configured is also 404. An endpoint that answered while its credential was
        | unset would publish the installation's state to the internet for however long it took
        | somebody to notice — and the moment it is most likely to be unset is the first boot after
        | a restore, which is also the moment the state is most interesting to an attacker.
        */
        $expected = $this->setting('ops.health_check_token', null);

        if (! is_string($expected) || $expected === '') {
            abort(Response::HTTP_NOT_FOUND);
        }

        if (! $this->authorised($request, $expected)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        /*
        | `--deep` is not reachable from HTTP. Deep probes scan the migrations directory and ask the
        | filesystem for volume sizes; exposing that as a query flag would let an unauthenticated
        | caller (or a leaked token) turn a 30/minute limiter into 30 filesystem sweeps a minute.
        | `ops:health --deep` is the console path, and it is run by somebody on the machine.
        */
        $payload = $health->publicPayload(deep: false);

        $status = $payload['status'] === SystemHealthService::STATUS_FAILED
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;

        return response()
            ->json($payload, $status)
            /*
            | A cached health response is a lie with a timestamp on it: an intermediary that stored
            | the 200 would keep serving "ok" through the outage the monitor exists to catch.
            */
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Either the header token or a valid signature — and nothing else.
     *
     * **`hash_equals`, never `==`.** A plain comparison returns as soon as two characters differ,
     * so the time it takes to answer depends on how much of the token was right; a caller who can
     * measure that can recover a 48-character secret one character at a time. `hash_equals`
     * compares every byte whatever happens.
     *
     * A signed URL is the second accepted form (§7.3) because some monitoring services cannot send
     * a custom header. The signature is in the query string, which is the one thing that belongs
     * there: it is bound to the exact path and expiry, so a leaked URL is a leaked *expired* URL,
     * not a leaked credential — and SEC-34 exempts signed-URL signatures for exactly that reason.
     */
    private function authorised(Request $request, string $expected): bool
    {
        $presented = $request->header(self::TOKEN_HEADER);

        if (is_string($presented) && $presented !== '' && hash_equals($expected, $presented)) {
            return true;
        }

        /*
        | Deliberately NOT `$request->query('token')`, and never a fallback to one. See the class
        | note: an access log, a Referer header and a browser history are three copies of the
        | credential that outlive the request.
        */
        return $request->hasValidSignature();
    }

    /**
     * A settings read that cannot take the endpoint down.
     *
     * If the settings table is unreachable the endpoint must still answer — that is the outage it
     * was built to report. The fallbacks are chosen so a broken database cannot *open* the door:
     * the enabled flag defaults to true, and the token defaults to null, which is a 404.
     */
    private function setting(string $key, mixed $default): mixed
    {
        try {
            return setting($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}
