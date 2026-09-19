<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\SlugGenerator;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills a model's permalink slug on insert (phase-04 §6.1).
 *
 *   · `creating`: when the slug attribute is empty, it is generated from `sluggableSource()` through
 *     `SlugGenerator::make()` — unique **including soft-deleted rows**, never reserved, never purely
 *     numeric, truncated to the column before any `-2` suffix, with a `{model}-{next id}` fallback when
 *     the source normalises to nothing.
 *   · `updating`: nothing. A slug never changes by itself; an admin edits it explicitly through the form
 *     (validated regex + unique ignoring self + not reserved), and the owning service writes the
 *     `slug_changed` activity entry when the record has ever been published (§12 R2).
 *
 * A concurrent duplicate (two inserts racing for the same slug) surfaces as a 1062 from the single-column
 * unique index; the owning service retries `make()` up to three times (§6.1 invariant 5).
 *
 * @mixin Model
 */
trait HasSlug
{
    /**
     * The human text the slug is derived from (a name or a title).
     */
    abstract public function sluggableSource(): string;

    public static function bootHasSlug(): void
    {
        static::creating(static function (Model $model): void {
            /** @var Model&self $model */
            $column = $model->slugColumn();

            if (trim((string) $model->getAttribute($column)) !== '') {
                return;
            }

            $model->setAttribute($column, SlugGenerator::make(
                $model->sluggableSource(),
                $model->getTable(),
                null,
                $column,
                $model->slugMaxLength(),
            ));
        });
    }

    /**
     * The slug column. Every Phase 4 slug column is `slug`.
     */
    public function slugColumn(): string
    {
        return 'slug';
    }

    /**
     * The slug column's width: `string(180)`, overridden to 200 by `BlogPost` (§2 preamble).
     */
    public function slugMaxLength(): int
    {
        return 180;
    }
}
