<?php

declare(strict_types=1);

namespace App\Models\Cms\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The draft / published boundary shared by the two snapshot-published tables, `website_sections` and
 * `pages` (phase-03 §2.15, decision **D22**, INV-1, INV-4).
 *
 * The using model declares `public const PUBLIC_COLUMNS` — the only columns an anonymous visitor may
 * read (§9). Everything here is built on that list:
 *
 *   · `publishedSnapshot()` restricts the SELECT to it, so the draft column is not even fetched and a
 *     renderer that reaches for `content` finds nothing rather than shipping an unfinished edit;
 *   · `restrictToSnapshotColumns()` applies the same restriction **only when the caller has not already
 *     chosen columns**. That is what lets `published()` read the snapshot by default while staying safe
 *     inside `whereHas()` / `withCount()` constraints, where Eloquent has already set the column list
 *     (`*` or `count(*)`) and replacing it would turn a count into an id;
 *   · `has_unpublished_changes` is a STORED generated column (INV-4). It is never fillable, and a dirty
 *     value is stripped before any save, so no code path can ask MariaDB to write it.
 *
 * Columns are qualified with the table name because both tables are joined by `belongsToMany`
 * relations whose pivots carry their own `sort_order` / `updated_at`.
 *
 * @mixin Model
 */
trait PublishesSnapshots
{
    /** The generated column of INV-4. */
    public const UNPUBLISHED_CHANGES_COLUMN = 'has_unpublished_changes';

    public static function bootPublishesSnapshots(): void
    {
        static::saving(static function (Model $model): void {
            if ($model->isDirty(self::UNPUBLISHED_CHANGES_COLUMN)) {
                // Derived by the database from the two hashes; a written value would be rejected.
                $model->offsetUnset(self::UNPUBLISHED_CHANGES_COLUMN);
            }
        });
    }

    /**
     * INV-4: derived from `content_hash <> published_hash` by the database, never set by code.
     */
    public function hasUnpublishedChanges(): bool
    {
        return (bool) $this->getAttribute(self::UNPUBLISHED_CHANGES_COLUMN);
    }

    /**
     * Was this row loaded with its draft columns? False for a row read through the public path.
     */
    public function draftLoaded(): bool
    {
        return array_key_exists('content', $this->getAttributes());
    }

    /**
     * Restrict the SELECT to the snapshot columns (INV-1, D22). Unconditional.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublishedSnapshot(Builder $query): Builder
    {
        return $query->select($query->getModel()->qualifyColumns(static::PUBLIC_COLUMNS));
    }

    /**
     * Apply {@see self::scopePublishedSnapshot()} unless the query already selects columns.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function restrictToSnapshotColumns(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->qualifyColumns(static::PUBLIC_COLUMNS));
        }

        return $query;
    }
}
