<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\DataObjects\Finance\GatewayChargeRequest;
use App\DataObjects\Finance\GatewayEvent;
use App\DataObjects\Finance\GatewayResult;
use Illuminate\Http\Request;

/**
 * What any payment gateway must be able to do (§32, phase-13 §6.6).
 *
 * The interface exists now, with one real implementation, so the shape is **testable rather than
 * imaginary**. §32 asks for a gateway-ready architecture; it does not ask for an integration nobody has
 * paid for, and building one would be invented scope. This mirrors Phase 2's
 * `security.two_factor_enabled`: declared, implemented later.
 *
 * Nothing in this phase routes to a gateway. There is no controller, no webhook endpoint, no redirect
 * flow and no stored card — the money is taken offline and recorded through the normal registers.
 */
interface PaymentGateway
{
    /**
     * Ask the gateway for the money.
     */
    public function charge(GatewayChargeRequest $request): GatewayResult;

    /**
     * Ask the gateway what actually happened to a reference we hold.
     *
     * Separate from `charge()` because the answer to "did this work" must be obtainable without
     * retrying the charge.
     */
    public function verify(string $reference): GatewayResult;

    public function refund(string $reference, string $amount): GatewayResult;

    /**
     * Turn a webhook into something this system may act on — after checking its signature.
     */
    public function webhookPayload(Request $request): GatewayEvent;

    public function supportsRefund(): bool;

    /**
     * The `payment_methods.gateway_driver` value that resolves to this driver.
     */
    public function name(): string;
}
