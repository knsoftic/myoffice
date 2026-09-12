<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

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
     * Minimum length a new password may have.
     */
    public const MIN_LENGTH = 10;

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
            Password::min(self::MIN_LENGTH)
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
        return 'At least '.self::MIN_LENGTH.' characters, with upper and lower case, a number and a symbol.';
    }
}
