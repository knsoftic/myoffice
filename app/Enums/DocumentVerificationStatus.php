<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether somebody has checked an employee document (phase-07 §2.6, §3).
 */
enum DocumentVerificationStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Verified => 'emerald',
            self::Rejected => 'rose',
            self::Expired => 'zinc',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Expired;
    }
}
