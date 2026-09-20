<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a partner is actually paid (`collaborator_payouts.payout_method`, finance spine §3).
 *
 * Deliberately **not** the same enum as {@see PaymentMethod}: that one is how money comes **in** and
 * carries cases like `adjustment` and `online_gateway` that make no sense going out. Which of these a
 * business offers is `collaborator.payout_methods`.
 */
enum PayoutMethod: string
{
    use HasOptions;

    case BankTransfer = 'bank_transfer';
    case Easypaisa = 'easypaisa';
    case JazzCash = 'jazzcash';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Easypaisa => 'Easypaisa',
            self::JazzCash => 'JazzCash',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BankTransfer => 'sky',
            self::Easypaisa, self::JazzCash => 'emerald',
            self::Cash => 'amber',
            self::Cheque => 'violet',
            self::Other => 'slate',
        };
    }

    /**
     * Does paying this way need a stored destination (an account number, a wallet number)?
     *
     * Cash does not, which is why a payout account is not demanded for it — and why a cash payout needs
     * a transaction reference of its own instead.
     */
    public function needsAnAccount(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * The `collaborator.payout_methods` value that switches this on.
     */
    public function settingValue(): string
    {
        return match ($this) {
            self::BankTransfer => 'bank',
            default => $this->value,
        };
    }
}
