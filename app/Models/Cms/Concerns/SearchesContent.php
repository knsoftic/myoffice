<?php

declare(strict_types=1);

namespace App\Models\Cms\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin list `search` box of every phase-04 screen (§8): one `LIKE` across the columns the model
 * names in `searchableColumns()`, with `%`, `_` and `\` escaped so a visitor-typed wildcard is a literal.
 *
 * @mixin Model
 */
trait SearchesContent
{
    /**
     * The columns the search box matches, in the order the contract lists them.
     *
     * @return list<string>
     */
    abstract protected function searchableColumns(): array;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $columns = $this->searchableColumns();

        return $query->where(function (Builder $builder) use ($columns, $like): void {
            foreach ($columns as $column) {
                $builder->orWhere($builder->qualifyColumn($column), 'like', $like);
            }
        });
    }
}
