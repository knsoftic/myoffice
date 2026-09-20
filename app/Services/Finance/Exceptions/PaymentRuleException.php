<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A money rule refused the act — a 422 with the message on the named field.
 *
 * These hold whatever the caller's permissions, Super Admin included: a refund never exceeds what was
 * received (INV-9), a receipt is never dated in the future, an installment belongs to its own charge,
 * and a reversal is approved once. It **is** a `ValidationException`, exactly like the equivalents in
 * phases 5 to 9, so it renders as a 422 with an errors bag for JSON and as a redirect back with the
 * error for a browser form — and it is thrown inside the service's transaction, so the refused act
 * leaves nothing behind.
 *
 * The messages name the figure and the receipt rather than stating a rule, because the person reading
 * one is a cashier with somebody standing in front of them.
 */
class PaymentRuleException extends ValidationException
{
    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }
}
