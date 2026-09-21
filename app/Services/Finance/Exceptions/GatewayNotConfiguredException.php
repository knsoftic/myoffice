<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use RuntimeException;

/**
 * A gateway driver was asked to move money it has no way of moving (phase-13 §6.6).
 *
 * `ManualGateway` throws this from `charge()` and `refund()`. It is not a placeholder that silently
 * returns a failed result: a caller that believed a charge had been attempted would be a caller that
 * could mark an invoice paid on the strength of a no-op.
 */
final class GatewayNotConfiguredException extends RuntimeException
{
    public static function for(string $driver, string $operation): self
    {
        return new self(sprintf(
            'No payment gateway is wired in this release, so "%s" cannot %s. The method is recorded and '
            .'the money is taken offline; switch the driver on when an integration exists.',
            $driver,
            $operation,
        ));
    }
}
