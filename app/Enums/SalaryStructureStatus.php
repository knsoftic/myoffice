<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of one salary structure version (phase-07 §2.19, §3, HR-10).
 *
 * A structure row is **never updated**: a raise closes the open version and writes a successor. These
 * statuses are therefore a history, not a workflow — `superseded` means a later version took over, and
 * at most one version is ever {@see isLive()} for an employee at a time.
 */
enum SalaryStructureStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::Active => 'emerald',
            self::Superseded => 'slate',
            self::Expired => 'zinc',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Is this version the one in force, or about to be?
     */
    public function isLive(): bool
    {
        return $this === self::Active || $this === self::Scheduled;
    }
}
