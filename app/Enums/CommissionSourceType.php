<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What produced a ledger entry (`collaborator_commission_ledger_entries.source_type`, finance spine §3).
 *
 * Together with `source_id` this is the key of `uq_cle_source`, which is what makes the engine
 * duplicate-proof: the same receipt can be processed twice — by a retry, by a queued job that ran
 * late, by two requests at once — and the second insert is a 1062 rather than a second payment
 * (`CLAUDE.md` rule 6).
 */
enum CommissionSourceType: string
{
    use HasOptions;

    case StudentFeePayment = 'student_fee_payment';
    case StudentInstallmentPayment = 'student_installment_payment';
    case ProjectPayment = 'project_payment';
    case PaymentReversal = 'payment_reversal';
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::StudentFeePayment => 'Student fee payment',
            self::StudentInstallmentPayment => 'Installment payment',
            self::ProjectPayment => 'Project payment',
            self::PaymentReversal => 'Reversal',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::StudentFeePayment, self::StudentInstallmentPayment => 'violet',
            self::ProjectPayment => 'sky',
            self::PaymentReversal => 'rose',
            self::ManualAdjustment => 'amber',
        };
    }

    public function scope(): ?CommissionScope
    {
        return match ($this) {
            self::StudentFeePayment, self::StudentInstallmentPayment => CommissionScope::Student,
            self::ProjectPayment => CommissionScope::Project,
            self::PaymentReversal, self::ManualAdjustment => null,
        };
    }

    /**
     * Is this a receipt of money, rather than an undo or a hand adjustment?
     */
    public function isReceipt(): bool
    {
        return in_array($this, [
            self::StudentFeePayment,
            self::StudentInstallmentPayment,
            self::ProjectPayment,
        ], true);
    }
}
