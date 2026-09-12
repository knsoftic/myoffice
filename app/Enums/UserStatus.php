<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Account state of a user row (users.status).
 */
enum UserStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
            self::Pending => 'Pending',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Inactive => 'slate',
            self::Suspended => 'rose',
            self::Pending => 'amber',
        };
    }

    /**
     * Only an active account may authenticate.
     */
    public function canLogin(): bool
    {
        return $this === self::Active;
    }
}
