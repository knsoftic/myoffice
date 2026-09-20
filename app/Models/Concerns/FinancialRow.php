<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * "This row is money" as a model hook (finance spine INV-4, INV-5, INV-8, INV-21, [D-IMP-2]).
 *
 * Three guarantees, all of them stated here so twelve models do not each carry their own version:
 *
 *   1. **A row is inserted only by its owning service.** The service is what assigns the number under a
 *      lock, snapshots the attribution, writes the cache delta and dispatches the follow-up work. A row
 *      created anywhere else has none of that and looks completely normal, which is why the refusal is
 *      structural and not a convention.
 *   2. **Only a short whitelist of columns may ever change.** Everything else on a money row is
 *      evidence: what was earned, from which receipt, at which rate, on which date. A wrong figure is
 *      corrected by a reversing row that references the original (`CLAUDE.md` rule 3), never by an edit.
 *   3. **Nothing is ever deleted.** The database backs this with a `BEFORE DELETE` trigger, but the
 *      model throws **first** — the spine's R-5 lesson is that a bare `SQLSTATE 45000` with no Eloquent
 *      explanation is how somebody eventually "fixes" the problem by dropping the trigger.
 *
 * A class using this trait says which columns may move and which service owns it. The escape hatch is
 * deliberately one greppable call: `allowDirectWrites()`, for factories, seeders and a historical
 * import, and CI greps for it outside `database/` and `tests/`.
 *
 * @mixin Model
 */
trait FinancialRow
{
    /**
     * True while the owning service is writing. Static because the guard has to hold across every
     * instance in the process, not just the one the service happens to be holding.
     */
    private static bool $writing = false;

    /**
     * The only columns an UPDATE may touch. Everything else is evidence.
     *
     * @return list<string>
     */
    abstract protected function mutableColumns(): array;

    /**
     * The service that is allowed to insert this row — named in the refusal, so the message points
     * somewhere rather than merely saying no.
     */
    abstract protected function owningService(): string;

    public static function bootFinancialRow(): void
    {
        static::creating(static function (Model $model): void {
            if (self::$writing) {
                return;
            }

            /** @var self $model */
            throw new LogicException(sprintf(
                'A %s is inserted only by %s. That is what assigns the number under a lock, snapshots '
                .'the attribution, writes the balance delta and schedules the follow-up work in one '
                .'transaction — a row created any other way would have none of that and would look '
                .'perfectly normal in the table. Use the service, or %s::allowDirectWrites() in a '
                .'factory or seeder.',
                class_basename($model),
                $model->owningService(),
                class_basename($model),
            ));
        });

        static::updating(static function (Model $model): void {
            /** @var self $model */
            $allowed = array_merge($model->mutableColumns(), ['updated_at', 'updated_by']);
            $touched = array_values(array_diff(array_keys($model->getDirty()), $allowed));

            if ($touched === []) {
                return;
            }

            throw $model->immutableAttributeException($touched, $allowed);
        });

        static::deleting(static function (Model $model): void {
            /** @var self $model */
            throw new LogicException($model->noDeleteMessage());
        });
    }

    /**
     * Run a callback with the insert guard lifted.
     *
     * Restored in a `finally`, so a throwing factory cannot leave the guard open for the rest of the
     * process — which would turn a test failure into a silent hole in production code paths that share
     * the container.
     */
    public static function allowDirectWrites(Closure $callback): mixed
    {
        $previous = self::$writing;
        self::$writing = true;

        try {
            return $callback();
        } finally {
            self::$writing = $previous;
        }
    }

    /**
     * Whether the owning service currently has the guard lifted — what a service's own `isWriting()`
     * reports.
     */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * The refusal for an edit. Overridden where a model has a more specific exception to raise.
     *
     * @param  list<string>  $touched
     * @param  list<string>  $allowed
     */
    protected function immutableAttributeException(array $touched, array $allowed): LogicException
    {
        return new LogicException(sprintf(
            '%s #%s: %s cannot change once the row exists. A money row is evidence — a wrong figure is '
            .'corrected by a reversing row that references it, never by an edit. Only these may move: %s.',
            class_basename($this),
            (string) ($this->getKey() ?? 'new'),
            implode(', ', $touched),
            implode(', ', $allowed),
        ));
    }

    /**
     * Why this row cannot be deleted. Overridden to say the specific thing that would break.
     */
    protected function noDeleteMessage(): string
    {
        return sprintf(
            '%s #%s cannot be deleted. It is money that changed hands, and the tables that explain it '
            .'point back at this row.',
            class_basename($this),
            (string) ($this->getKey() ?? 'new'),
        );
    }
}
