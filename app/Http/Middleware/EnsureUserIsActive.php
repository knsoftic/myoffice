<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\SettingsRegistry;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Alias: `active` (phase-01 §6).
 *
 * Account state is enforced on *every* request, not only at login, so suspending a user takes
 * effect immediately even if they already hold a session:
 *
 *   1. `users.status` is not Active  → log the session out and bounce to /login with an error
 *      that names the state. The `blocked` login_histories row is written by the auth listeners
 *      (they subscribe to the Logout event), never here.
 *   2. `users.must_change_password`  → force the change-password screen; only that screen (plus
 *      logout and the theme endpoint) is reachable until the password has been changed.
 *   3. the password is older than `security.force_password_change_days` (0 = off) → the same
 *      redirect, with a message that says the password expired.
 *
 * The change-password screen is resolved by route name at runtime (see CHANGE_PASSWORD_ROUTES)
 * so this middleware does not hard-depend on the panel route files being registered yet.
 */
final class EnsureUserIsActive
{
    /**
     * Candidate route names for the change-password screen, best match first.
     *
     * The user's own panel prefix is tried before these (`admin.account.password.edit`, …).
     *
     * @var list<string>
     */
    private const CHANGE_PASSWORD_ROUTES = [
        'account.password.edit',
        'account.password',
        'password.change',
        'password.edit',
        'profile.edit',
    ];

    /**
     * Route names always reachable while a password change is pending.
     *
     * @var list<string>
     */
    private const ALWAYS_ALLOWED = [
        'logout',
        'password.update',
        'password.confirm',
        'account.theme.update',
    ];

    /** Shown when an administrator flagged the account (`users.must_change_password`). */
    private const MUST_CHANGE_MESSAGE = 'You must change your password before continuing.';

    /** Shown when the password is older than `security.force_password_change_days`. */
    private const EXPIRED_MESSAGE = 'Your password has expired. Choose a new one to continue.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $status = $this->statusOf($user);

        if ($status !== null && ! $status->canLogin()) {
            return $this->reject($request, $status);
        }

        if ((bool) ($user->must_change_password ?? false)) {
            return $this->forcePasswordChange($request, $next, $user);
        }

        if ($this->passwordHasExpired($user)) {
            return $this->forcePasswordChange($request, $next, $user, self::EXPIRED_MESSAGE);
        }

        return $next($request);
    }

    /**
     * `security.force_password_change_days`: is this user's password older than the policy allows?
     *
     * 0 (the default) switches expiry off. The age runs from `users.password_changed_at` — stamped
     * by PasswordChangeService, the reset flow and UserService — or, for an account whose password
     * was never changed, from the account's creation. An account with neither date has a password
     * of unknown age and is treated as expired. Changing the password stamps a fresh
     * `password_changed_at`, which is what releases the user from the change-password screen.
     */
    private function passwordHasExpired(User $user): bool
    {
        $days = $this->passwordMaxAgeDays();

        if ($days === 0) {
            return false;
        }

        $changedAt = $user->password_changed_at ?? $user->created_at ?? null;

        if (! $changedAt instanceof DateTimeInterface) {
            return true;
        }

        return Carbon::instance($changedAt)->lt(Carbon::now()->subDays($days));
    }

    /**
     * The policy in days, clamped to 0..FORCE_PASSWORD_CHANGE_DAYS_MAX; an unreadable setting is 0.
     */
    private function passwordMaxAgeDays(): int
    {
        try {
            $days = settings_repo()->get('security.force_password_change_days');
        } catch (Throwable) {
            return 0;
        }

        if (! is_numeric($days)) {
            return 0;
        }

        return max(0, min(SettingsRegistry::FORCE_PASSWORD_CHANGE_DAYS_MAX, (int) $days));
    }

    /**
     * Kill the session of a user whose account may no longer log in.
     */
    private function reject(Request $request, UserStatus $status): Response
    {
        $message = $this->messageFor($status);

        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, $message);
        }

        return redirect()
            ->to(RouteFacade::has('login') ? route('login') : '/')
            ->withErrors(['email' => $message])
            ->with('toast', ['type' => 'error', 'message' => $message]);
    }

    /**
     * Pin the user to the change-password screen until they have changed it.
     */
    private function forcePasswordChange(Request $request, Closure $next, User $user, string $message = self::MUST_CHANGE_MESSAGE): Response
    {
        $target = $this->changePasswordRoute($user);

        // Nothing to redirect to yet (route files not registered): do not lock the app out.
        if ($target === null) {
            return $next($request);
        }

        if ($request->routeIs(...$this->allowedRoutePatterns($target))) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, $message);
        }

        return redirect()
            ->route($target)
            ->with('toast', ['type' => 'warning', 'message' => $message]);
    }

    /**
     * Route-name patterns that stay reachable while a password change is pending:
     * the change-password screen and its siblings (its update action), logout, the theme endpoint.
     *
     * @return list<string>
     */
    private function allowedRoutePatterns(string $target): array
    {
        $patterns = self::ALWAYS_ALLOWED;
        $patterns[] = $target;

        // The screen's own sub-actions, e.g. `account.password.update` for `account.password`.
        $patterns[] = $target.'.*';

        // When the screen is named `<group>.edit` / `<group>.show`, its siblings are the rest of
        // that group (`account.password.update`), so widen to the group — but only one level, and
        // only for those suffixes. Widening unconditionally would open the whole `account.*`
        // namespace (profile, sessions, login-history) while a change is still pending.
        if (Str::contains($target, '.') && Str::endsWith($target, ['.edit', '.show'])) {
            $patterns[] = Str::beforeLast($target, '.').'.*';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * The first change-password route that actually exists, panel-specific first.
     */
    private function changePasswordRoute(User $user): ?string
    {
        $candidates = [];
        $prefix = $this->panelPrefix($user);

        if ($prefix !== null) {
            $candidates[] = $prefix.'.account.password.edit';
            $candidates[] = $prefix.'.account.password';
        }

        foreach (self::CHANGE_PASSWORD_ROUTES as $name) {
            $candidates[] = $name;
        }

        foreach ($candidates as $name) {
            if (RouteFacade::has($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Route-name prefix of the user's primary panel, or null when they have no panel yet.
     */
    private function panelPrefix(User $user): ?string
    {
        try {
            return $user->primaryPanel()->routePrefix();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `users.status` as an enum, tolerating a raw string and a missing value.
     */
    private function statusOf(User $user): ?UserStatus
    {
        $status = $user->status ?? null;

        if ($status instanceof UserStatus) {
            return $status;
        }

        if (is_string($status) && $status !== '') {
            return UserStatus::tryFrom($status);
        }

        return null;
    }

    /**
     * The error shown on the login screen, naming the state the account is in.
     */
    private function messageFor(UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Suspended => 'Your account is suspended. Please contact the administrator.',
            UserStatus::Inactive => 'Your account is inactive. Please contact the administrator.',
            UserStatus::Pending => 'Your account is pending approval. Please contact the administrator.',
            UserStatus::Active => 'Your account is not active. Please contact the administrator.',
        };
    }
}
