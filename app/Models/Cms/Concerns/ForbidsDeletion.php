<?php

declare(strict_types=1);

namespace App\Models\Cms\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The model half of the append-only rule for a table that carries **no `deleted_at`** (decision D19,
 * CLAUDE.md §3, phase-03 §2.14): `seo_meta`, `cms_revisions`, `sitemap_generations`.
 *
 * A nullable `deleted_at` would let one `->delete()` hide a row from every read; the column is absent
 * on purpose, so an Eloquent delete is refused loudly instead of silently erasing history.
 *
 * Scope of the guard: model events only. A mass `Model::query()->delete()` or a query-builder delete
 * does not fire `deleting`; the one sanctioned bulk removal (`PruneCmsRevisions`, which never touches a
 * published snapshot) runs through the query builder deliberately and is the only such path.
 *
 * @mixin Model
 */
trait ForbidsDeletion
{
    public static function bootForbidsDeletion(): void
    {
        static::deleting(static function (Model $model): never {
            throw new LogicException(sprintf(
                '%s #%s is an append-only record (no deleted_at, decision D19) and cannot be deleted.',
                class_basename($model),
                (string) $model->getKey()
            ));
        });
    }
}
