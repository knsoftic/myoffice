<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Does a commission need a signature before it becomes available?
 * (`collaborator.commission_approval_mode`, finance spine §3.)
 *
 * `automatic` does **not** mean "no record": the entry is still written, still audited and still held
 * for `collaborator.commission_hold_days` before it can be paid. It means nobody has to click.
 */
enum CommissionApprovalMode: string
{
    use HasOptions;

    case Automatic = 'automatic';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Approve automatically',
            self::Manual => 'Approve by hand',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Automatic => 'emerald',
            self::Manual => 'amber',
        };
    }

    /**
     * The status a brand-new earning entry starts in.
     */
    public function initialStatus(): CommissionStatus
    {
        return $this === self::Automatic ? CommissionStatus::Approved : CommissionStatus::Pending;
    }
}
