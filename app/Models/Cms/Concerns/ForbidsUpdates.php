<?php

declare(strict_types=1);

namespace App\Models\Cms\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Throwable;

/**
 * A write-once record: inserted, never edited (phase-03 §2.14 — `cms_revisions` and
 * `sitemap_generations` carry `created_by` / `created_at` and deliberately no `updated_*`).
 *
 * `Blameable` cannot be used on these tables because it stamps `updated_by`, which does not exist.
 * This trait stamps `created_by` from the authenticated user on insert (never overwriting an explicit
 * value; null in console, queue and seeder context) and refuses any later update through Eloquent.
 *
 * @mixin Model
 */
trait ForbidsUpdates
{
    public static function bootForbidsUpdates(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('created_by') !== null) {
                return;
            }

            try {
                $actor = auth()->id();
            } catch (Throwable) {
                return;
            }

            if (is_int($actor) || is_string($actor)) {
                $model->setAttribute('created_by', $actor);
            }
        });

        static::updating(static function (Model $model): never {
            throw new LogicException(sprintf(
                '%s #%s is a write-once record and cannot be edited.',
                class_basename($model),
                (string) $model->getKey()
            ));
        });
    }

    /**
     * The user who wrote the record.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
