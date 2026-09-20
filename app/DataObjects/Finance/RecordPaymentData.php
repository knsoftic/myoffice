<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Enums\PaymentMethod;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Money arriving against a charge or a project (spine §2.5, §2.6, phase-10-12 §6.3).
 *
 * **`idempotencyKey` is the layer-0 duplicate guard**, and it is a real column with a UNIQUE index, not
 * a convention. The record-payment wizard mints one when it opens, so a double-clicked Save, a browser
 * retry and a redelivered API call all arrive with the same key and produce **one** receipt. When a
 * caller supplies none, one is generated here — which protects nothing across retries, so every
 * interactive caller supplies its own.
 *
 * `confirmDuplicate` is the separate, human-facing guard: two receipts for the same student, amount and
 * day are usually a mistake and occasionally real (a student paying two fee heads in one visit). The
 * flag is what a cashier ticks after being shown the earlier receipt number, and the override is
 * logged with both numbers.
 */
final readonly class RecordPaymentData
{
    public function __construct(
        public string $amount,
        public PaymentMethod $method,
        public ?CarbonInterface $paidOn = null,
        public ?int $installmentId = null,
        public ?int $milestoneId = null,
        public ?int $invoiceId = null,
        public ?string $referenceNo = null,
        public ?string $gatewayTxnId = null,
        public ?string $notes = null,
        public ?int $receivedBy = null,
        public ?string $receivedByName = null,
        public ?int $paymentMethodId = null,
        public ?int $branchId = null,
        public ?string $idempotencyKey = null,
        public bool $confirmDuplicate = false,
    ) {
        if (Money::compare(Money::of($this->amount), Money::ZERO) !== 1) {
            throw new InvalidArgumentException(
                'A receipt records money that arrived, so its amount is greater than zero — '
                .'`chk_sfp_amount` refuses anything else. A correction is a reversal, not a negative '
                .'receipt.'
            );
        }
    }

    /**
     * The key this receipt will be guarded by. Generated only when the caller had none: a generated
     * key is unique per call and therefore guards nothing, which is exactly why an interactive caller
     * mints its own when the form opens rather than when it is submitted.
     */
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
