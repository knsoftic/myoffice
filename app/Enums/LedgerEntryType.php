<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The direction of a ledger row (phase-07 §3, [D-HR-14]).
 *
 * **Declared here because Phase 7 migrates first.** The finance spine's §3 defines exactly these two
 * cases, and the spine, phases 10-12, 13, 14-17 and 18 **reuse this enum rather than redeclaring it** —
 * two declarations of one `App\Enums` name is a merge conflict, not a style question (F-5.4).
 *
 * Amounts are stored as positive magnitudes and the direction lives here; the only column anything sums
 * is the generated `signed_*` column ([D-HR-4]).
 */
enum LedgerEntryType: string
{
    use HasOptions;

    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Credit => 'emerald',
            self::Debit => 'rose',
        };
    }


    /**
     * +1 for a credit, -1 for a debit — the multiplier behind every `signed_*` generated column.
     */
    public function sign(): int
    {
        return $this === self::Credit ? 1 : -1;
    }
}
