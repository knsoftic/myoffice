<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\SettingsRegistry;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sign-in request (phase-01 §7).
 *
 * Breeze's throttle by email + IP is kept as it was. What is added: once the credentials are
 * accepted, the account state is asserted with `UserStatus::canLogin()`. A user who may not log
 * in is signed straight back out — session invalidated, token regenerated — before any response
 * can be rendered, so no session ever survives the refusal, and the validation error names the
 * state the account is in.
 *
 * The `blocked` row in `login_histories` is written by RecordLogout, which the logout below
 * fires; the same listener covers the `active` middleware tearing a session down mid-session, so
 * that rule lives in exactly one place.
 */
class LoginRequest extends FormRequest
{
    /**
     * Attempts allowed per email + IP before the lockout, when `security.login_max_attempts` is
     * unreadable.
     */
    private const MAX_ATTEMPTS = 5;

    /** Lockout length in minutes when `security.lockout_minutes` is unreadable. */
    private const LOCKOUT_MINUTES = 15;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), $this->lockoutSeconds());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $this->ensureAccountMayLogin();

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Right credentials, wrong account state: log out immediately and explain why.
     *
     * @throws ValidationException
     */
    protected function ensureAccountMayLogin(): void
    {
        $user = Auth::user();

        if ($user instanceof User && $user->canLogin()) {
            return;
        }

        $status = $user instanceof User && $user->status instanceof UserStatus
            ? $user->status
            : null;

        // Fires Logout → RecordLogout writes the `blocked` login_histories row.
        Auth::guard('web')->logout();

        if ($this->hasSession()) {
            $this->session()->invalidate();
            $this->session()->regenerateToken();
        }

        // A refused state still counts against the throttle, so the endpoint cannot be used as
        // an account-status oracle.
        RateLimiter::hit($this->throttleKey(), $this->lockoutSeconds());

        throw ValidationException::withMessages([
            'email' => $this->statusMessage($status),
        ]);
    }

    /**
     * The message shown on the login screen, naming the state.
     *
     * Mirrors App\Http\Middleware\EnsureUserIsActive so a user sees the same sentence whether
     * they were refused at sign-in or dropped mid-session.
     */
    protected function statusMessage(?UserStatus $status): string
    {
        return match ($status) {
            UserStatus::Suspended => 'Your account is suspended. Please contact the administrator.',
            UserStatus::Inactive => 'Your account is inactive. Please contact the administrator.',
            UserStatus::Pending => 'Your account is pending approval. Please contact the administrator.',
            default => 'Your account is not active. Please contact the administrator.',
        };
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts())) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * `security.login_max_attempts`, read live so the Security screen actually governs the sign-in
     * throttle — and clamped to the hard server floor (3–10), whatever the row holds. The registry
     * refuses a value outside that range; this is the second lock, for a row written by raw SQL or
     * by an older release that allowed 20. A setting may tighten the throttle, never loosen it.
     */
    protected function maxAttempts(): int
    {
        return $this->securityInt(
            'security.login_max_attempts',
            self::MAX_ATTEMPTS,
            SettingsRegistry::LOGIN_MAX_ATTEMPTS_MIN,
            SettingsRegistry::LOGIN_MAX_ATTEMPTS_MAX,
        );
    }

    /**
     * `security.lockout_minutes` as the limiter's decay, clamped to the hard server floor
     * (5–1440 minutes): a lockout can be made longer, never shorter than five minutes — 20 attempts
     * a minute was 28,800 guesses a day per email + IP.
     */
    protected function lockoutSeconds(): int
    {
        return $this->securityInt(
            'security.lockout_minutes',
            self::LOCKOUT_MINUTES,
            SettingsRegistry::LOCKOUT_MINUTES_MIN,
            SettingsRegistry::LOCKOUT_MINUTES_MAX,
        ) * 60;
    }

    private function securityInt(string $key, int $fallback, int $min, int $max): int
    {
        try {
            $value = settings_repo()->get($key);
        } catch (Throwable) {
            return $fallback;
        }

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $fallback;
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->value()).'|'.$this->ip());
    }
}
