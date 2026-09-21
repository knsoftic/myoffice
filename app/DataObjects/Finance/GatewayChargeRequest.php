<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Support\Money;

/**
 * What a gateway is asked to charge (phase-13 §6.6).
 *
 * Deliberately **no card data of any kind**. A gateway integration that passed a PAN through this
 * application would drag every future phase into PCI scope; the driver's job is to hand the payer to
 * the gateway and read the answer back, not to hold an instrument.
 */
final readonly class GatewayChargeRequest
{
    /**
     * @param  string  $amount  a `Money` string — never a float
     * @param  array<string, scalar|null>  $metadata  echoed back by the gateway, so a webhook can find the row again
     */
    public function __construct(
        public string $amount,
        public string $currency,
        public string $reference,
        public ?string $description = null,
        public ?string $payerName = null,
        public ?string $payerEmail = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
    ) {}

    public function normalisedAmount(): string
    {
        return Money::of($this->amount);
    }
}
