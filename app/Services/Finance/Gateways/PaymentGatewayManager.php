<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Models\Finance\PaymentMethodOption;
use App\Services\Finance\Exceptions\UnknownGatewayDriverException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a driver from `payment_methods.gateway_driver` (phase-13 §6.6).
 *
 * A **named** exception for an unknown driver, never a silent fallback: a method configured for
 * "stripe" that quietly behaved like the offline one would take a payment nobody could later find. The
 * fallback exists only for a method that names no driver at all, which genuinely is an offline method.
 *
 * Drivers are bound in the container, so phase 19–23 adds one by registering it here and naming it on a
 * payment method — not by editing this class.
 */
final class PaymentGatewayManager
{
    /**
     * `driver name => container binding`.
     *
     * @var array<string, class-string<PaymentGateway>>
     */
    private array $drivers = [
        ManualGateway::NAME => ManualGateway::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<PaymentGateway>  $gateway
     */
    public function extend(string $name, string $gateway): void
    {
        $this->drivers[$name] = $gateway;
    }

    public function driver(?string $name = null): PaymentGateway
    {
        $name = trim((string) $name);

        // No driver named at all is an offline method, which the manual driver models correctly.
        if ($name === '') {
            return $this->container->make(ManualGateway::class);
        }

        if (! array_key_exists($name, $this->drivers)) {
            throw UnknownGatewayDriverException::for($name, array_keys($this->drivers));
        }

        return $this->container->make($this->drivers[$name]);
    }

    public function forMethod(PaymentMethodOption $method): PaymentGateway
    {
        return $this->driver($method->gateway_driver);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }
}
