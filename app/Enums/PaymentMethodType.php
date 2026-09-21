<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of instrument a configured payment method is (`payment_methods.type`, phase-13 §2.2).
 *
 * Separate from `PaymentMethod`, which is the **snapshot written onto a payment row** and must never
 * change meaning. This enum is about the configured method the business set up: its type decides whether
 * a gateway driver is even possible, and `defaultCode()` is what pre-fills the immutable snapshot when
 * somebody adds one.
 */
enum PaymentMethodType: string
{
    use HasOptions;

    case Cash = 'cash';
    case Bank = 'bank';
    case Card = 'card';
    case MobileWallet = 'mobile_wallet';
    case Cheque = 'cheque';
    case Gateway = 'gateway';
    case Manual = 'manual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank transfer',
            self::Card => 'Card',
            self::MobileWallet => 'Mobile wallet',
            self::Cheque => 'Cheque',
            self::Gateway => 'Online gateway',
            self::Manual => 'Manual / other arrangement',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash => 'emerald',
            self::Bank => 'sky',
            self::Card => 'violet',
            self::MobileWallet => 'amber',
            self::Cheque => 'slate',
            self::Gateway => 'brand',
            self::Manual, self::Other => 'slate',
        };
    }

    /**
     * Can this type ever take money online? Only a gateway can, and `payment_methods.is_online` is
     * refused for anything else — an "online" cash method would put a pay-now button on an invoice that
     * nothing can honour.
     */
    public function isOnlineCapable(): bool
    {
        return $this === self::Gateway;
    }

    /**
     * The `PaymentMethod` snapshot a payment made through this type carries by default.
     */
    public function defaultCode(): PaymentMethod
    {
        return match ($this) {
            self::Cash => PaymentMethod::Cash,
            self::Bank => PaymentMethod::BankTransfer,
            self::Card => PaymentMethod::Card,
            // The wallets are two named cases rather than one generic: a business reconciling a
            // statement needs to know which wallet, and `Easypaisa` is the common one here.
            self::MobileWallet => PaymentMethod::Easypaisa,
            self::Cheque => PaymentMethod::Cheque,
            self::Gateway => PaymentMethod::OnlineGateway,
            self::Manual, self::Other => PaymentMethod::Other,
        };
    }
}
