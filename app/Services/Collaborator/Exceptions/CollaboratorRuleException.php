<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A phase-08-09 business rule refused the act — a 422 with the message on the named field.
 *
 * These rules hold whatever the caller's permissions, Super Admin included: a status change needs a
 * reason, a referral code that has already been used does not change, a collaborator who is owed money
 * is not deleted, and a payout account with an in-flight payout is not disabled.
 *
 * It **is** a `ValidationException`, exactly like the equivalents in phases 5, 6 and 7, so Laravel
 * renders it as a 422 with an `errors` bag for JSON and as a redirect back with the error for a browser
 * form — and it is thrown inside the service's transaction, so the refused act leaves nothing behind.
 */
class CollaboratorRuleException extends ValidationException
{
    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }

    public static function reasonRequired(string $field = 'reason', string $message = 'A reason is required.'): static
    {
        return static::refuse($field, $message);
    }
}
