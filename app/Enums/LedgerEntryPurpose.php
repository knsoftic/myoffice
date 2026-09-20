<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a ledger row exists (`collaborator_commission_ledger_entries.purpose`, finance spine §3).
 *
 * **`reversal` and `clawback` are different facts.** A reversal takes back commission that was still
 * sitting in the wallet; a clawback takes back commission that had already been **paid out**, which
 * leaves the partner owing money rather than merely having less. The arithmetic is the same negative
 * row; the conversation is not.
 */
enum LedgerEntryPurpose: string
{
    use HasOptions;

    case StudentCommission = 'student_commission';
    case ProjectCommission = 'project_commission';
    case Reversal = 'reversal';
    case Clawback = 'clawback';
    case ManualAdjustment = 'manual_adjustment';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::StudentCommission => 'Student commission',
            self::ProjectCommission => 'Project commission',
            self::Reversal => 'Reversal',
            self::Clawback => 'Clawback',
            self::ManualAdjustment => 'Manual adjustment',
            self::WriteOff => 'Write-off',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::StudentCommission, self::ProjectCommission => 'emerald',
            self::Reversal, self::Clawback => 'rose',
            self::ManualAdjustment => 'amber',
            self::WriteOff => 'slate',
        };
    }

    /**
     * Did this row put money into the wallet?
     */
    public function isEarning(): bool
    {
        return in_array($this, [self::StudentCommission, self::ProjectCommission], true);
    }

    /**
     * Does this row take money back out?
     */
    public function isUndo(): bool
    {
        return in_array($this, [self::Reversal, self::Clawback, self::WriteOff], true);
    }

    /**
     * The entry type this purpose always carries. A manual adjustment is the only one that can go
     * either way, which is why it answers null and the caller must say.
     */
    public function entryType(): ?LedgerEntryType
    {
        return match (true) {
            $this->isEarning() => LedgerEntryType::Credit,
            $this->isUndo() => LedgerEntryType::Debit,
            default => null,
        };
    }
}
