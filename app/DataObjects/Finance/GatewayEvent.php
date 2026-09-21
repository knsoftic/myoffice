<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

/**
 * A webhook a gateway sent, after the driver has verified its signature (phase-13 §6.6).
 *
 * `verified` is the whole point: an unverified webhook is an anonymous HTTP request claiming money
 * moved. Nothing in this system may act on one, and the flag makes that visible at the call site
 * rather than buried in a driver.
 */
final readonly class GatewayEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public bool $verified,
        public ?string $reference = null,
        public ?string $amount = null,
        public array $payload = [],
    ) {}

    public static function unverified(string $type = 'unknown', array $payload = []): self
    {
        return new self(type: $type, verified: false, payload: $payload);
    }
}
