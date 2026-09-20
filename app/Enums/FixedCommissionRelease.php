<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * When a **fixed** commission is handed over (`collaborator_commission_settings.fixed_release`,
 * finance spine §3).
 *
 * A percentage settles itself — half the fee earns half the commission. A fixed amount does not, so
 * somebody has to say what a part-payment releases:
 *
 *   · `prorated` — in proportion to what has been collected. The partner carries the collection risk
 *     with the business, and the total can never exceed the promise.
 *   · `on_first_payment` — the whole amount as soon as any money arrives. Simple, and generous: a
 *     student who pays one installment and leaves still costs the full commission.
 *   · `per_payment` — the full amount on **every** receipt. Only sane with a cap, which is why
 *     `isCapped()` exists and why the entitlement row is what stops it running away.
 */
enum FixedCommissionRelease: string
{
    use HasOptions;

    case Prorated = 'prorated';
    case OnFirstPayment = 'on_first_payment';
    case PerPayment = 'per_payment';

    public function label(): string
    {
        return match ($this) {
            self::Prorated => 'In proportion to what is collected',
            self::OnFirstPayment => 'In full on the first payment',
            self::PerPayment => 'In full on every payment',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Prorated => 'emerald',
            self::OnFirstPayment => 'sky',
            self::PerPayment => 'amber',
        };
    }

    /**
     * Does the entitlement's cap have to hold this one back? Every release mode is capped by the
     * entitlement, but `per_payment` is the one that would otherwise pay without limit.
     */
    public function isCapped(): bool
    {
        return true;
    }

    public function releasesEverythingAtOnce(): bool
    {
        return $this !== self::Prorated;
    }
}
