<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Enums\ReversalType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Money going back (spine §2.7, phase-10-12 §6.3, F-4.2 / R7).
 *
 * **This DTO is the only form `refund()` accepts, and that is a deliberate refusal of the convenient
 * one.** The obvious signature is `refund($payment, string $amount, string $reason, ...)` — two
 * adjacent strings, in an order nobody can remember, either of which is accepted silently in the
 * other's place. `refund($p, '500.00', 'cheque bounced')` and `refund($p, 'cheque bounced', '500.00')`
 * both compile; one of them refunds zero and files the amount as the reason. Named fields make the
 * mistake impossible to write rather than merely unlikely.
 *
 * The reason is mandatory because a refund is money leaving, and "why" is the first question anybody
 * asks about it — six weeks later, of a row nobody remembers creating.
 */
final readonly class RefundData
{
    public function __construct(
        public string $amount,
        public string $reason,
        public ReversalType $type,
        public ?string $method = null,
        public ?string $idempotencyKey = null,
        public ?CarbonInterface $refundedOn = null,
        public ?string $referenceNo = null,
        public ?string $notes = null,
    ) {
        if (Money::compare(Money::of($this->amount), Money::ZERO) !== 1) {
            throw new InvalidArgumentException(
                'A reversal returns money, so its amount is greater than zero — `chk_pr_amount` refuses '
                .'anything else. The direction is in the row, not in the sign.'
            );
        }

        if (trim($this->reason) === '') {
            throw new InvalidArgumentException(
                'A refund needs a reason. It is money leaving the business, and the reason is the whole '
                .'of what the reversal row can be asked afterwards.'
            );
        }
    }

    public function key(): string
    {
        $supplied = trim((string) $this->idempotencyKey);

        return $supplied === '' ? (string) Str::ulid() : mb_substr($supplied, 0, 64);
    }

    public function amount(): string
    {
        return Money::of($this->amount);
    }
}
