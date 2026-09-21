<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

/**
 * An invoice rule refused the act — a 422 with the message on the named field.
 *
 * The same shape as {@see PaymentRuleException} and for the same reason: these hold whatever the
 * caller's permissions, Super Admin included. An invoice for nothing is never issued, a number is never
 * assigned twice, a document somebody has paid against is never corrected in place, and a cancelled
 * invoice never quietly gains a receipt.
 *
 * The messages name the document and the figure rather than stating a rule, because the person reading
 * one is usually about to explain it to a client.
 */
class InvoiceRuleException extends PaymentRuleException
{
    /**
     * A refusal whose whole content is "you have not said why".
     *
     * Separate from {@see refuse()} so the two read differently at the call site: one is "that cannot be
     * done", the other is "that can be done, once you say why".
     */
    public static function reasonRequired(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }
}
