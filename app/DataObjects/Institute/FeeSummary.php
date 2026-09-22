<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\StudentFeeStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A subject's fee position, read-only (phase-18 §6.1, F-4.6).
 *
 * **This exists so no other phase ever sums money itself.** Phases 14–17 and 19–23 all want to show
 * "what does this student owe" — on an enrolment screen, beside an exam eligibility check, on a
 * certificate request. Each of them writing its own `SUM(balance_amount)` is how a system ends up with
 * six subtly different answers to one question, and INV-26 exists because the commission side already
 * learned that lesson. `StudentFeeService::summaryFor()` is the only producer.
 *
 * Every figure is a `Money` string. A float here would make the whole of `App\Support\Money` pointless
 * at the last step, which is exactly where it usually happens.
 */
final readonly class FeeSummary
{
    public function __construct(
        public string $gross,
        public string $discount,
        public string $scholarship,
        public string $net,
        public string $paid,
        public string $refunded,
        public string $balance,
        public StudentFeeStatus $status,
        public ?CarbonImmutable $nextDueDate = null,
        /** How many live (non-cancelled) charges the figures cover. */
        public int $chargeCount = 0,
    ) {}

    public static function empty(): self
    {
        return new self(
            gross: Money::ZERO,
            discount: Money::ZERO,
            scholarship: Money::ZERO,
            net: Money::ZERO,
            paid: Money::ZERO,
            refunded: Money::ZERO,
            balance: Money::ZERO,
            status: StudentFeeStatus::Pending,
        );
    }

    /** Money the business actually holds: received less anything given back. */
    public function netReceived(): string
    {
        return Money::sub($this->paid, $this->refunded);
    }

    /** A negative balance is an advance, not an error — and it is said in words, never as a minus sign. */
    public function isInAdvance(): bool
    {
        return Money::isNegative($this->balance);
    }

    public function owes(): bool
    {
        return Money::isPositive($this->balance);
    }

    /**
     * What a screen prints beside the number, so a negative balance is never read as a debt.
     */
    public function balanceCaption(): string
    {
        return match (true) {
            $this->isInAdvance() => Money::format(Money::abs($this->balance)).' in advance',
            $this->owes() => Money::format($this->balance).' outstanding',
            default => 'Nothing outstanding',
        };
    }
}
