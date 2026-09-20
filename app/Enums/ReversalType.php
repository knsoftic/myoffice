<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why money went back (`payment_reversals.reversal_type`, finance spine §3).
 *
 * Six named reasons rather than one "refund", because they mean different things to the business and to
 * the partner whose commission is being taken back: a **void** says the receipt should never have been
 * entered, a **bounced instrument** says the money was never really there, and a **refund** says it was
 * and has now been returned. Only the wording of the clawback differs — the arithmetic does not — but
 * the wording is what somebody reads six months later.
 */
enum ReversalType: string
{
    use HasOptions;

    case FullRefund = 'full_refund';
    case PartialRefund = 'partial_refund';
    case Cancellation = 'cancellation';
    case Void = 'void';
    case BouncedInstrument = 'bounced_instrument';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::FullRefund => 'Full refund',
            self::PartialRefund => 'Partial refund',
            self::Cancellation => 'Cancellation',
            self::Void => 'Void',
            self::BouncedInstrument => 'Bounced instrument',
            self::Correction => 'Correction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PartialRefund => 'amber',
            self::FullRefund, self::BouncedInstrument => 'rose',
            self::Cancellation, self::Void => 'slate',
            self::Correction => 'sky',
        };
    }

    /**
     * Does this take back the whole receipt? A partial refund is the only one that does not.
     */
    public function isFullVoid(): bool
    {
        return $this !== self::PartialRefund;
    }

    /**
     * The status the receipt ends in.
     */
    public function resultingPaymentStatus(bool $isPartial): ReceivedPaymentStatus
    {
        if ($isPartial) {
            return ReceivedPaymentStatus::PartiallyRefunded;
        }

        return match ($this) {
            self::Void, self::Cancellation, self::Correction => ReceivedPaymentStatus::Voided,
            self::BouncedInstrument => ReceivedPaymentStatus::Bounced,
            self::FullRefund, self::PartialRefund => ReceivedPaymentStatus::Refunded,
        };
    }
}
