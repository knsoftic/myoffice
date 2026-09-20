<?php

declare(strict_types=1);

namespace App\Models\Hr\Concerns;

use App\Services\Hr\Exceptions\AppendOnlyRowException;
use Illuminate\Database\Eloquent\Model;

/**
 * "This row is evidence" as a model hook (phase-07 HR-6, HR-7, HR-16, HR-19, D19).
 *
 * Eleven Phase 7 tables carry no `deleted_at` because a nullable one on an append-only payroll, ledger or
 * audit table is an invitation: one `->delete()` from a future controller and a slip vanishes from every
 * total while the money stays paid. The database backs each of them with a `BEFORE DELETE` trigger, but
 * the model throws **first** — the spine's R-5 lesson is that a raw `SQLSTATE '45000'` with no Eloquent
 * explanation is how somebody eventually "fixes" the problem by dropping the trigger.
 *
 * A class using this trait declares {@see updatableColumns()}: the short list of columns that may change
 * after the row exists. Everything else is frozen, and an attempt to move it says which column and why.
 *
 * @mixin Model
 */
trait AppendOnly
{
    /**
     * The only columns an UPDATE may touch. Everything else is evidence.
     *
     * @return list<string>
     */
    abstract protected function updatableColumns(): array;

    public static function bootAppendOnly(): void
    {
        static::updating(static function (Model $model): void {
            /** @var self $model */
            $allowed = array_merge($model->updatableColumns(), ['updated_at', 'updated_by']);

            $touched = array_values(array_diff(array_keys($model->getDirty()), $allowed));

            if ($touched !== []) {
                throw AppendOnlyRowException::cannotUpdate(
                    class_basename($model),
                    $model->getKey() ?? 'new',
                    $touched,
                    $allowed,
                );
            }
        });

        static::deleting(static function (Model $model): void {
            throw AppendOnlyRowException::cannotDelete(class_basename($model), $model->getKey() ?? 'new');
        });
    }
}
