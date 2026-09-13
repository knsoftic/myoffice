<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * The password strength rules, declared once (phase-01 §7).
 *
 * Used by every screen that sets a password: the account change-password form and the reset
 * link flow. Keeping the rule set here means "strong" can never mean two different things.
 *
 * `uncompromised()` checks the k-anonymity range API of haveibeenpwned; when that service is
 * unreachable Laravel's verifier passes the value, so the app never locks anyone out offline.
 */
final class PasswordPolicy
{
    /**
     * The floor: no setting can make a password shorter than this.
     */
    public const MIN_LENGTH = 10;

    /** The ceiling `security.password_min_length` may raise the floor to. */
    public const MAX_MIN_LENGTH = 64;

    /**
     * Minimum length a new password must have right now: `security.password_min_length`, never
     * below MIN_LENGTH (Phase 2 security review — the setting was saved and audited but read by
     * nothing, so the screen promised a protection that did not exist).
     */
    public static function minLength(): int
    {
        try {
            $configured = settings_repo()->get('security.password_min_length');
        } catch (Throwable) {
            return self::MIN_LENGTH;
        }

        $configured = is_numeric($configured) ? (int) $configured : self::MIN_LENGTH;

        return max(self::MIN_LENGTH, min(self::MAX_MIN_LENGTH, $configured));
    }

    /**
     * Validation rules for a new password field (add `confirmed` where a confirmation box
     * exists — that is the form's concern, not the policy's).
     *
     * @return list<ValidationRule|string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            Password::min(self::minLength())
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised(),
        ];
    }

    /**
     * One line of helper text describing the rules above, for the form.
     */
    public static function hint(): string
    {
        return 'At least '.self::minLength().' characters, with upper and lower case, a number and a symbol.';
    }
}
