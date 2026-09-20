<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use LogicException;

/**
 * Somebody tried to insert a receipt or a reversal without going through `PaymentService` ([D-IMP-2]).
 *
 * The service is what assigns the document number under its counter lock, resolves and snapshots the
 * attribution, recomputes the charge's caches under its row lock, and dispatches the commission job
 * **after** the transaction commits. A row inserted anywhere else has a number that may collide, no
 * attribution, stale caches around it and no commission — and nothing about it looks wrong.
 *
 * Factories, seeders and a historical import use `PaymentService::allowDirectWrites()`: one greppable
 * escape hatch, which CI greps for outside `database/` and `tests/`.
 */
final class DirectPaymentWriteException extends LogicException
{
    public static function for(string $model): self
    {
        return new self(sprintf(
            'A %s can only be inserted by PaymentService ([D-IMP-2]). It assigns the number under a '
            .'counter lock, snapshots the attribution, recomputes the caches and dispatches the '
            .'commission job after commit — a row written any other way would have none of that and '
            .'would look perfectly normal. Use PaymentService, or allowDirectWrites() in a factory.',
            $model,
        ));
    }
}
