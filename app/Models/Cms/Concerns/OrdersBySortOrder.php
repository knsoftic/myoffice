<?php

declare(strict_types=1);

namespace App\Models\Cms\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Display order for the drag-reorderable phase-04 lists (§6.2 `ContentOrderService::reorder()` writes
 * `sort_order` 1..n): `sort_order` ascending, then `id` so equal positions stay deterministic.
 *
 * @mixin Model
 */
trait OrdersBySortOrder
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
