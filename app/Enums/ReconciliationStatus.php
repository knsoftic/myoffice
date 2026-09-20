<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What the nightly wallet check found (`collaborator_wallet_reconciliations.status`, finance spine §3).
 *
 * A wallet is a **cache**: every figure on it must be re-derivable by summing the ledger, and this is
 * the row that proves it was. `drift` does not repair anything by itself — it records that the cache and
 * the truth disagreed, and the screens then show the **derived** figure behind a banner, because a
 * number nobody can reproduce is worse than a number that admits it is wrong.
 */
enum ReconciliationStatus: string
{
    use HasOptions;

    case Ok = 'ok';
    case Drift = 'drift';
    case Repaired = 'repaired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Matches the ledger',
            self::Drift => 'Does not match',
            self::Repaired => 'Repaired',
            self::Failed => 'The check failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'emerald',
            self::Repaired => 'sky',
            self::Drift => 'amber',
            self::Failed => 'rose',
        };
    }

    /**
     * Can the cached figures be trusted on a screen?
     */
    public function isHealthy(): bool
    {
        return in_array($this, [self::Ok, self::Repaired], true);
    }

    /**
     * Should somebody be told?
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Drift, self::Failed], true);
    }
}
