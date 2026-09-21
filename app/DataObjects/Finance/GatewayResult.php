<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

/**
 * What a gateway said (phase-13 §6.6).
 *
 * `succeeded` is never inferred from the absence of an error: a driver says so explicitly, because a
 * charge whose outcome is unknown is a charge nobody should mark an invoice paid against.
 */
final readonly class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw  the driver's own payload, kept for support questions
     */
    public function __construct(
        public bool $succeeded,
        public string $status,
        public ?string $reference = null,
        public ?string $amount = null,
        public ?string $redirectUrl = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public static function failed(string $status, ?string $message = null, array $raw = []): self
    {
        return new self(succeeded: false, status: $status, message: $message, raw: $raw);
    }

    public static function succeeded(string $reference, string $amount, array $raw = []): self
    {
        return new self(
            succeeded: true,
            status: 'succeeded',
            reference: $reference,
            amount: $amount,
            raw: $raw,
        );
    }

    /** A charge the gateway has accepted but not settled. Not a success. */
    public function isPending(): bool
    {
        return ! $this->succeeded && $this->status === 'pending';
    }
}
