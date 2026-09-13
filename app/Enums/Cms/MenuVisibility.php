<?php

declare(strict_types=1);

namespace App\Enums\Cms;

use App\Enums\Concerns\HasOptions;
use App\Models\User;

/**
 * Who sees a menu item (`menu_items.visibility`, phase-03 §2.6/§3).
 *
 * §8's Login button is `guest` so it disappears once signed in; a "My panel" link is `auth`.
 *
 * Visibility is applied **per request, after the cache** (§6.3): the version-stamped public cache
 * stores the resolved tree with every item in it, and the Blade menu component filters through
 * `matches()`. That is why this enum has no query scope — a cached page must never be personalised
 * (R-4), only its render.
 */
enum MenuVisibility: string
{
    use HasOptions;

    case All = 'all';
    case Guest = 'guest';
    case Auth = 'auth';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everyone',
            self::Guest => 'Signed-out visitors only',
            self::Auth => 'Signed-in users only',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::All => 'slate',
            self::Guest => 'cyan',
            self::Auth => 'indigo',
        };
    }

    /**
     * Should the item render for this viewer? Pass the authenticated user, or null for a guest.
     */
    public function matches(?User $user): bool
    {
        return match ($this) {
            self::All => true,
            self::Guest => $user === null,
            self::Auth => $user !== null,
        };
    }
}
