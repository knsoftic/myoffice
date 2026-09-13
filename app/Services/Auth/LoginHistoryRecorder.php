<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\LoginStatus;
use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only writer of `login_histories` (phase-01 §1.6, §7).
 *
 * Called by the three auth listeners (RecordSuccessfulLogin / RecordFailedLogin /
 * RecordLogout), never by a controller — the one exception is
 * {@see self::rebindSession()}, which AuthenticatedSessionController calls after the session
 * id has been regenerated (see the note on that method).
 *
 * Status mapping:
 *   · `success` — credentials accepted **and** the account may log in;
 *   · `failed`  — credentials rejected (the attempted email is kept, the account may not exist);
 *   · `logout`  — the user signed out;
 *   · `blocked` — the session was torn down because `users.status` may not log in. This is the
 *                 row produced both by LoginRequest (correct credentials, suspended account)
 *                 and by the `active` middleware (account suspended mid-session) — both reach
 *                 it through the Logout event, so the rule lives in one place.
 *
 * Bound as a singleton (EventListenerServiceProvider) so the row written during a request can
 * still be found later in that same request.
 */
final class LoginHistoryRecorder
{
    /**
     * Module slug the activity entries belong to.
     */
    private const MODULE = 'login_history';

    /**
     * How much of a `User-Agent` header is kept.
     *
     * `login_histories.user_agent` is a TEXT column, so this is not about fitting the column — it is
     * about every other column on the row being clamped while this one was not (T16). A real client
     * sends a few hundred characters; a hostile one sends 64 kB of them on every failed sign-in
     * attempt, which is a free write amplifier against the audit table and, on a strict server, an
     * error thrown from inside the login listener. 1 kB keeps every genuine agent string intact.
     */
    private const USER_AGENT_MAX_LENGTH = 1024;

    /**
     * Primary key of the `success` row written during this request, if any.
     */
    private ?int $currentRowId = null;

    public function __construct(private readonly AuthActivityLogger $activity) {}

    /**
     * A user authenticated successfully.
     *
     * A user whose status may not log in gets **no** row here: the immediate logout that
     * follows (LoginRequest::authenticate()) records the `blocked` row instead, so the history
     * never claims a sign-in that was refused.
     */
    public function success(User $user): ?LoginHistory
    {
        if (! $user->canLogin()) {
            return null;
        }

        $context = $this->context();

        $row = $this->write($user, LoginStatus::Success, $context, [
            'logged_in_at' => now(),
        ]);

        $this->currentRowId = $row?->getKey() === null ? null : (int) $row->getKey();

        $this->touchLastLogin($user, $context['ip_address']);

        $this->activity->record('Signed in', $user, 'login', self::MODULE, [
            'ip' => $context['ip_address'],
            'device' => $context['device'],
            'platform' => $context['platform'],
            'browser' => $context['browser'],
        ]);

        return $row;
    }

    /**
     * Credentials were rejected.
     *
     * The attempted email is always stored; `user_id` is filled only when the auth provider
     * had already resolved an account. Nothing here is echoed back to the client, so the
     * response cannot tell an attacker whether the address exists.
     */
    public function failed(?string $email, ?User $user = null): ?LoginHistory
    {
        $context = $this->context();
        $attempted = $this->normaliseEmail($email) ?? $user?->email;

        $row = $this->write($user, LoginStatus::Failed, $context, [
            'email' => $attempted,
        ]);

        $this->activity->record('Sign-in failed', $user, 'login_failed', self::MODULE, [
            'email' => $attempted,
            'ip' => $context['ip_address'],
        ]);

        return $row;
    }

    /**
     * The user signed out — or was signed out because the account may no longer log in.
     */
    public function logout(?User $user): ?LoginHistory
    {
        if (! $user instanceof User) {
            return null;
        }

        $context = $this->context();
        $blocked = ! $user->canLogin();
        $status = $blocked ? LoginStatus::Blocked : LoginStatus::Logout;

        $this->closeOpenSession($user, $context['session_id']);

        $row = $this->write($user, $status, $context, [
            'logged_out_at' => now(),
        ]);

        $this->activity->record(
            $blocked ? 'Session blocked' : 'Signed out',
            $user,
            $blocked ? 'login_blocked' : 'logout',
            self::MODULE,
            [
                'ip' => $context['ip_address'],
                'status' => $user->status instanceof UserStatus ? $user->status->value : null,
            ],
        );

        return $row;
    }

    /**
     * Move the `success` row written during this request onto the regenerated session id.
     *
     * Laravel fires `Login` before the controller regenerates the session, so the id captured
     * by {@see self::success()} is the pre-regeneration one. Re-stamping it keeps
     * `login_histories.session_id` join-able with the `sessions` table, which is what lets the
     * logout listener close the right row and the session screen cross-reference a login.
     */
    public function rebindSession(?string $sessionId): void
    {
        $sessionId = $this->clamp($sessionId, 255);

        if ($this->currentRowId === null || $sessionId === null) {
            return;
        }

        try {
            LoginHistory::query()
                ->whereKey($this->currentRowId)
                ->update(['session_id' => $sessionId]);
        } catch (Throwable $exception) {
            $this->reportSwallowed($exception, 'could not re-stamp the session id', [
                'login_history_id' => $this->currentRowId,
            ]);
        }
    }

    /**
     * Stamp `logged_out_at` on the still-open `success` row of the session being closed.
     *
     * The session id is matched first. When it matches nothing — the id was rotated after the row
     * was written, or the session never reached a store — the user's newest still-open sign-in is
     * closed instead, so a session is never left looking open forever.
     */
    private function closeOpenSession(User $user, ?string $sessionId): void
    {
        try {
            if ($sessionId !== null) {
                $closed = $this->openSessions($user)
                    ->where('session_id', $sessionId)
                    ->update(['logged_out_at' => now()]);

                if ($closed > 0) {
                    return;
                }
            }

            $this->openSessions($user)
                ->orderByDesc('id')
                ->limit(1)
                ->update(['logged_out_at' => now()]);
        } catch (Throwable $exception) {
            $this->reportSwallowed($exception, 'could not close the open sign-in row', [
                'user_id' => $user->getKey(),
            ]);
        }
    }

    /**
     * Sign-ins of this user that have not been closed yet.
     *
     * @return Builder<LoginHistory>
     */
    private function openSessions(User $user): Builder
    {
        return LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->where('status', LoginStatus::Success->value)
            ->whereNull('logged_out_at');
    }

    /**
     * Insert one history row.
     *
     * @param  array<string, mixed>  $extra
     */
    private function write(?User $user, LoginStatus $status, array $context, array $extra = []): ?LoginHistory
    {
        $attributes = array_merge([
            'user_id' => $user?->getKey(),
            'email' => $user?->email,
            'status' => $status,
            'ip_address' => $context['ip_address'],
            'user_agent' => $context['user_agent'],
            'device' => $context['device'],
            'platform' => $context['platform'],
            'browser' => $context['browser'],
            'session_id' => $context['session_id'],
        ], $extra);

        try {
            return LoginHistory::query()->create($attributes);
        } catch (Throwable $exception) {
            // Swallowed on purpose — a sign-in must not fail because its history row could not be
            // written — but never silently: a login history that quietly stops recording is a
            // missing audit trail nobody would notice (T16).
            $this->reportSwallowed($exception, 'could not write a history row', [
                'status' => $status->value,
                'user_id' => $user?->getKey(),
            ]);

            return null;
        }
    }

    /**
     * Leave a trace of a failure this class deliberately absorbs.
     *
     * Everything in here runs inside the authentication request, so the logging itself must be
     * incapable of breaking a login: `report()` goes through the application's exception handler,
     * which can throw on its own when the log channel is misconfigured or the disk is full. Both
     * calls are therefore individually guarded, and in the worst case the failure is lost rather
     * than propagated.
     *
     * `Log::warning()` carries the context (which row, whose account) and `report()` carries the
     * stack trace plus anything an external reporter is wired to.
     *
     * @param  array<string, mixed>  $context
     */
    private function reportSwallowed(Throwable $exception, string $what, array $context = []): void
    {
        try {
            Log::warning('Login history: '.$what.'.', array_merge($context, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));
        } catch (Throwable) {
            // The logger is the broken part. There is nowhere left to report it.
        }

        try {
            report($exception);
        } catch (Throwable) {
            // Same reason. A swallowed failure must never become a failed sign-in.
        }
    }

    /**
     * Bookkeeping on the user row. Both columns are in the activity log's ignore list, so this
     * write never produces an audit entry of its own.
     */
    private function touchLastLogin(User $user, ?string $ip): void
    {
        try {
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
            ])->save();
        } catch (Throwable $exception) {
            $this->reportSwallowed($exception, 'could not stamp last_login_at', [
                'user_id' => $user->getKey(),
            ]);
        }
    }

    /**
     * Everything the history row needs about the current request.
     *
     * @return array{ip_address: string|null, user_agent: string|null, device: string|null, platform: string|null, browser: string|null, session_id: string|null}
     */
    private function context(): array
    {
        $userAgent = null;
        $ip = null;
        $sessionId = null;

        try {
            $request = request();
            $userAgent = trim((string) $request->userAgent()) ?: null;
            $ip = trim((string) $request->ip()) ?: null;

            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (Throwable) {
            // Console / queue context: no request, no session.
        }

        $parsed = Device::parse($userAgent);

        return [
            'ip_address' => $this->clamp($ip, 45),
            'user_agent' => $this->clamp($userAgent, self::USER_AGENT_MAX_LENGTH),
            'device' => $this->clamp($parsed['device'], 64),
            'platform' => $this->clamp($parsed['platform'], 64),
            'browser' => $this->clamp($parsed['browser'], 64),
            'session_id' => $this->clamp($sessionId, 255),
        ];
    }

    private function normaliseEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_substr($email, 0, 255);
    }

    private function clamp(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
