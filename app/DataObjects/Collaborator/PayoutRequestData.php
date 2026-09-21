<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\PayoutMethod;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A request to be paid (spine §6.4.2, phase-10-12 §6.3).
 *
 * **`requestedAmount` is what somebody asked for, and it is never what gets paid.** The payout's own
 * `amount` is derived from the allocations the algorithm actually manages to make (INV-22) — if the
 * available balance moved between the form loading and the submit, the request is refused with the
 * shortfall named rather than quietly paying less than was asked for.
 */
final readonly class PayoutRequestData
{
    public function __construct(
        public string $requestedAmount,
        public PayoutMethod $method,
        public ?int $payoutAccountId = null,
        public ?string $notes = null,
        public ?CarbonInterface $statementFrom = null,
        public ?CarbonInterface $statementTo = null,
        public ?string $idempotencyKey = null,
    ) {
        if (Money::compare(Money::of($this->requestedAmount), Money::ZERO) !== 1) {
            throw new InvalidArgumentException('A payout request is for more than nothing.');
        }
    }

    public function amount(): string
    {
        return Money::of($this->requestedAmount);
    }

    public function key(): string
    {
        $supplied = trim((string) $this->idempotencyKey);

        return $supplied === '' ? (string) Str::ulid() : mb_substr($supplied, 0, 64);
    }
}
