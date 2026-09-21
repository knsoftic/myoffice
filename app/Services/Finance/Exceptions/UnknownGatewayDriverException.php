<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use InvalidArgumentException;

/**
 * A `payment_methods.gateway_driver` names something nothing is bound to (phase-13 §6.6).
 *
 * Named rather than silently falling back to the manual driver: a method configured for "stripe" that
 * quietly behaved like an offline one would take a payment nobody could later find.
 */
final class UnknownGatewayDriverException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function for(string $driver, array $known): self
    {
        return new self(sprintf(
            'Payment gateway driver [%s] is not registered. Known drivers: %s.',
            $driver,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }
}
