<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Module;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A module was switched on or off (phase-02 §3, `ModuleService::toggle()`).
 *
 * Fired once per module whose state actually moved, after the transaction that moved it has
 * committed — so a listener can safely read the row, warm a cache or notify someone without
 * ever observing a state the database later rolled back. A toggle that changes nothing fires
 * nothing.
 *
 * Deliberately carries no behaviour of its own: the cache flush, the audit entry and the
 * dependency rules are the service's job, because they must happen whether or not anyone is
 * listening. Later phases subscribe here to react to a module coming or going (rebuilding a
 * navigation cache, pausing a scheduled job, invalidating a public-site snapshot).
 *
 * **Disabling a module never touches its data.** Nothing listening to this event may delete,
 * truncate or anonymise a row belonging to `$module`: the whole guarantee of the switchboard is
 * that flipping it back on restores the module exactly as it was.
 */
final class ModuleStateChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Module  $module  the module whose switch moved (already refreshed)
     * @param  bool  $enabled  the state it moved **to**
     * @param  string|null  $reason  the audited explanation, as stored in `disable_reason`
     * @param  int|null  $actorId  `users.id` of whoever flipped it, null for console/seeder runs
     * @param  bool  $cascaded  true when this module was taken down as a dependent of another
     * @param  string|null  $because  the slug that cascaded onto this one, when `$cascaded`
     */
    public function __construct(
        public readonly Module $module,
        public readonly bool $enabled,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
        public readonly bool $cascaded = false,
        public readonly ?string $because = null,
    ) {}

    /**
     * The module's slug — the value every listener actually keys off.
     */
    public function slug(): string
    {
        return (string) $this->module->slug;
    }

    public function wasEnabled(): bool
    {
        return $this->enabled;
    }

    public function wasDisabled(): bool
    {
        return ! $this->enabled;
    }
}
