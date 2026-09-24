<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An absolute ceiling on a session, independent of how busy it has been (phase-24-25 §6.3).
 *
 * **This is not the idle timeout, and it is not a duplicate of it.** `security.session_lifetime` is
 * Laravel's own idle expiry: a session that goes untouched for that many minutes ends. By
 * construction it can never end a session that is being used — every request pushes the deadline
 * forward again. A browser left open on a shared desk, a tab a script refreshes, a terminal in a
 * training room nobody signed out of: each stays signed in indefinitely, and the idle timeout is
 * working exactly as designed while it happens.
 *
 * `security.session_absolute_lifetime_hours` is measured from the sign-in instead, so it expires
 * whether or not anybody has been typing. The two settings are in different units — minutes and
 * hours — partly so no screen can show them side by side and invite the reader to think one is the
 * other.
 *
 * **The clock comes from `login_histories.logged_in_at`, not from the session.** A session
 * attribute would be written by this middleware and read by this middleware, which proves nothing:
 * anything that could rewrite the session could push the deadline. The history row is written once
 * by the sign-in listener, is append-only, and is the same row the sessions screen shows.
 *
 * The row is looked up once and then cached in the session, because reading it on every request
 * would put a query on every authenticated page for a value that cannot change.
 */
final class EnforceSessionLifetime
{
    /**
     * Where the resolved sign-in instant is cached for the rest of the session.
     */
    public const SESSION_KEY = 'auth.signed_in_at';

    /**
     * The reason recorded against the logout (SEC-26).
     */
    public const REASON = 'absolute_timeout';

    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $hours = $this->ceilingHours();

        if ($hours <= 0) {
            return $next($request);
        }

        $signedInAt = $this->signedInAt($request, $user);

        if ($signedInAt === null || $signedInAt->copy()->addHours($hours)->isFuture()) {
            return $next($request);
        }

        return $this->expire($request, $user);
    }

    /**
     * The ceiling, in hours. 0 or a nonsense value switches the ceiling off rather than expiring
     * every session immediately — a misread setting must fail open here, because failing closed
     * means nobody can stay signed in at all.
     */
    private function ceilingHours(): int
    {
        $value = setting('security.session_absolute_lifetime_hours', 24);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * When this session began.
     *
     * Cached in the session after the first lookup. A session with no matching history row — one
     * that predates this middleware, or one whose row was pruned — is treated as having no ceiling
     * rather than as expired: ending every existing session the moment this ships would be a
     * self-inflicted outage, and the next sign-in gets a row.
     */
    private function signedInAt(Request $request, User $user): ?Carbon
    {
        $cached = $request->session()->get(self::SESSION_KEY);

        if (is_string($cached) && $cached !== '') {
            try {
                return Carbon::parse($cached);
            } catch (\Throwable) {
                $request->session()->forget(self::SESSION_KEY);
            }
        }

        $row = LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->where('status', LoginStatus::Success->value)
            ->where('session_id', $request->session()->getId())
            ->whereNotNull('logged_in_at')
            ->orderByDesc('logged_in_at')
            ->first();

        $at = $row?->logged_in_at;

        if (! $at instanceof Carbon) {
            return null;
        }

        $request->session()->put(self::SESSION_KEY, $at->toIso8601String());

        return $at;
    }

    /**
     * End the session, record why, and say so.
     *
     * The history row and the activity entry are written **before** the session is invalidated, so
     * the recorder can still read the session id it is closing.
     */
    private function expire(Request $request, User $user): Response
    {
        $this->history->logout($user, self::REASON);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Your session reached its maximum length and was ended. Please sign in again.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()
            ->guest(route('login'))
            ->with('toast', ['type' => 'info', 'message' => $message]);
    }
}
