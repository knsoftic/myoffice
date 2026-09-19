<?php

declare(strict_types=1);

namespace App\Models\Project\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * "This column has exactly one writer" as a model hook (phase-06 INV-P1, INV-P8, INV-P13).
 *
 * Three Phase 6 guarantees are of the same shape: a set of columns may only be written by one named service,
 * because the column is money, an audit trail hangs off it, or it is derived. Rather than trusting every
 * future controller to remember, the model refuses the write and names the service that owns it.
 *
 * The owning service brackets its own write with {@see unlock()}, which is deliberately awkward to call by
 * accident: it takes the column group and a callback, restores the lock in a `finally`, and nests safely.
 *
 * This is a `LogicException`, not a validation error: reaching it means code took the wrong path, so it
 * belongs in the log with a stack trace rather than in a 422 to the user.
 *
 * @mixin Model
 */
trait GuardsServiceOwnedColumns
{
    /**
     * Column groups currently unlocked, as `ClassName::group` => depth.
     *
     * @var array<string, int>
     */
    private static array $unlockedGroups = [];

    /**
     * Run `$callback` with one column group writable. The lock is always restored, including on a throw.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function unlock(string $group, callable $callback): mixed
    {
        $key = static::class.'::'.$group;

        self::$unlockedGroups[$key] = (self::$unlockedGroups[$key] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            if (--self::$unlockedGroups[$key] <= 0) {
                unset(self::$unlockedGroups[$key]);
            }
        }
    }

    public static function isUnlocked(string $group): bool
    {
        return (self::$unlockedGroups[static::class.'::'.$group] ?? 0) > 0;
    }

    /**
     * Refuse a dirty column from a locked group. Called from the model's `updating` / `creating` hooks.
     *
     * @param  list<string>  $columns
     */
    protected function refuseGuardedColumns(string $group, array $columns, string $owner, string $invariant): void
    {
        if (static::isUnlocked($group)) {
            return;
        }

        $touched = array_values(array_filter($columns, fn (string $column): bool => $this->isDirty($column)));

        if ($touched === []) {
            return;
        }

        throw new LogicException(sprintf(
            '%s #%s: %s %s written only by %s (phase-06 %s). This write changed it outside that service.',
            class_basename($this),
            (string) ($this->getKey() ?? 'new'),
            implode(', ', $touched),
            count($touched) === 1 ? 'is' : 'are',
            $owner,
            $invariant
        ));
    }
}
