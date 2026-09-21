<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\DataObjects\Finance\GatewayChargeRequest;
use App\DataObjects\Finance\GatewayEvent;
use App\DataObjects\Finance\GatewayResult;
use App\Services\Finance\Exceptions\GatewayNotConfiguredException;
use Illuminate\Http\Request;

/**
 * The only driver this release registers (phase-13 §6.6).
 *
 * It represents money taken **offline** — cash, a transfer, a cheque — and recorded by a human in the
 * payments register. `charge()` and `refund()` therefore throw rather than returning a failed result:
 * a caller that believed a charge had been attempted could mark an invoice paid on the strength of a
 * no-op, and that is exactly the bug a "harmless" stub introduces.
 *
 * `verify()` is the one method that answers honestly without throwing: the answer to "did this
 * reference settle" for an offline payment is "nobody here knows — look at the register".
 */
final class ManualGateway implements PaymentGateway
{
    public const NAME = 'manual';

    public function charge(GatewayChargeRequest $request): GatewayResult
    {
        throw GatewayNotConfiguredException::for(self::NAME, 'take a payment');
    }

    public function verify(string $reference): GatewayResult
    {
        return GatewayResult::failed(
            'unknown',
            'This payment was taken offline. Its state lives in the payments register, not with a gateway.',
            ['reference' => $reference],
        );
    }

    public function refund(string $reference, string $amount): GatewayResult
    {
        throw GatewayNotConfiguredException::for(self::NAME, 'send a refund');
    }

    /**
     * Nothing sends this driver a webhook. An unverified event is what any caller gets, so nothing can
     * act on a request that merely claims money moved.
     */
    public function webhookPayload(Request $request): GatewayEvent
    {
        return GatewayEvent::unverified('manual.unsupported');
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function name(): string
    {
        return self::NAME;
    }
}
