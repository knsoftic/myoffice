<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The commercial standing of a client (phase-05 §3, `clients.status`).
 *
 * Only an `active` client may use the portal ({@see canUsePortal()}). A status that forbids it revokes access on the
 * next request through `EnsureClientContext`, without touching `clients.portal_enabled`, so restoring the status
 * restores access (§6.7).
 */
enum ClientStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Inactive => 'slate',
            self::Suspended => 'amber',
            self::Closed => 'rose',
        };
    }

    /**
     * May a client in this status reach the client panel? Only `active`.
     */
    public function canUsePortal(): bool
    {
        return $this === self::Active;
    }

    /**
     * Moving to this status needs a written reason (§6.7 `changeStatus`: `suspended` and `closed`).
     */
    public function requiresReason(): bool
    {
        return $this === self::Suspended || $this === self::Closed;
    }
}
