<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use LogicException;

/**
 * An attempt to move a column on a received payment that is not allowed to move (spine INV-8).
 *
 * `amount`, `paid_on` and `payment_method_id` stay outside the whitelist **for ever**. A receipt that
 * can be edited is a receipt whose commission, whose income report and whose student balance can all be
 * changed after the fact without a trace. The correct act is a **void** — a full reversal that leaves
 * the original visible — followed by a fresh receipt.
 */
final class ImmutablePaymentAttributeException extends LogicException
{
    /**
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    public static function forColumns(string $model, string|int $id, array $touched, array $allowed): self
    {
        return new self(sprintf(
            '%s #%s: %s cannot change once the money is recorded (spine INV-8). Void it and enter a '
            .'fresh receipt — a voided payment stays visible, and an edited one silently moves a '
            .'commission, an income report and a balance at once. Only these may move: %s.',
            $model,
            (string) $id,
            implode(', ', $touched),
            implode(', ', $allowed),
        ));
    }
}
