<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * Stamps `created_by` / `updated_by` from the authenticated user (phase-01 §3).
 *
 *   creating → created_by (and updated_by, so a fresh row is never half-stamped)
 *   saving   → updated_by
 *
 * Null-safe everywhere: in console, queue, seeder and migration context `auth()->id()`
 * resolves to null and the columns are simply left alone. An explicitly assigned value is
 * never overwritten, so seeders and imports can attribute rows to whoever they like.
 *
 * Only apply this trait to tables that actually carry both columns (every business table
 * does — see CLAUDE.md §3). There is deliberately no schema lookup here: one extra query
 * per model boot is not worth paying for on every request.
 */
trait Blameable
{
    /**
     * Register the model events. Called automatically by Eloquent (boot{TraitName}).
     */
    public static function bootBlameable(): void
    {
        static::creating(function (Model $model): void {
            $actor = static::blameableActorId();

            if ($actor === null) {
                return;
            }

            foreach ([static::createdByColumn(), static::updatedByColumn()] as $column) {
                if ($model->getAttribute($column) === null) {
                    $model->setAttribute($column, $actor);
                }
            }
        });

        static::saving(function (Model $model): void {
            // An untouched existing row must not become dirty just to restamp updated_by.
            if ($model->exists && ! $model->isDirty()) {
                return;
            }

            $column = static::updatedByColumn();

            // Respect a value the caller set on purpose.
            if ($model->isDirty($column)) {
                return;
            }

            $actor = static::blameableActorId();

            if ($actor === null) {
                return;
            }

            $model->setAttribute($column, $actor);
        });
    }

    /**
     * The user who created the row.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, static::createdByColumn());
    }

    /**
     * The user who last changed the row.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, static::updatedByColumn());
    }

    /**
     * Column holding the creator id. Override to rename it for one model.
     */
    protected static function createdByColumn(): string
    {
        return 'created_by';
    }

    /**
     * Column holding the last editor id. Override to rename it for one model.
     */
    protected static function updatedByColumn(): string
    {
        return 'updated_by';
    }

    /**
     * The authenticated user id, or null when there is nobody to blame
     * (console, seeder, queue worker, unauthenticated request).
     */
    protected static function blameableActorId(): int|string|null
    {
        try {
            $id = auth()->id();
        } catch (Throwable) {
            // No auth binding yet (early boot / migration context).
            return null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }
}
