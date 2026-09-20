<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How money moved (phase-07 §3, [D-HR-14], requirement §32).
 *
 * **Declared here because Phase 7 needs it first** — an advance is disbursed and a salary is paid before
 * any later money phase exists. The cases are verbatim from the finance spine §3, and every later phase
 * reuses this enum unchanged (F-5.4).
 *
 * `adjustment` is the one that is not a movement of money at all: it records a balance settled against
 * something else, which is why it is a method rather than a separate flag.
 */
enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cheque = 'cheque';
    case Easypaisa = 'easypaisa';
    case Jazzcash = 'jazzcash';
    case OnlineGateway = 'online_gateway';
    case Adjustment = 'adjustment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Card',
            self::Cheque => 'Cheque',
            self::Easypaisa => 'Easypaisa',
            self::Jazzcash => 'JazzCash',
            self::OnlineGateway => 'Online gateway',
            self::Adjustment => 'Adjustment',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash => 'emerald',
            self::BankTransfer => 'sky',
            self::Card => 'indigo',
            self::Cheque => 'violet',
            self::Easypaisa => 'lime',
            self::Jazzcash => 'amber',
            self::OnlineGateway => 'cyan',
            self::Adjustment => 'slate',
            self::Other => 'zinc',
        };
    }


    /**
     * Did money actually move? An adjustment settles a balance against something else.
     */
    public function movesMoney(): bool
    {
        return $this !== self::Adjustment;
    }

    /**
     * Does this method normally carry a reference somebody could look up later?
     */
    public function expectsReference(): bool
    {
        return in_array($this, [
            self::BankTransfer,
            self::Cheque,
            self::Card,
            self::Easypaisa,
            self::Jazzcash,
            self::OnlineGateway,
        ], true);
    }
}
