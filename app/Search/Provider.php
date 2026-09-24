<?php

declare(strict_types=1);

namespace App\Search;

use App\DataObjects\Search\SearchHit;
use App\Models\User;
use App\Search\Contracts\SearchProvider;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What all eleven providers share (phase-19-23 §6.23).
 *
 * The parts worth having in one place are the ones that are easy to get subtly wrong in eleven
 * separate files:
 *
 * **Matching.** {@see self::match()} builds `LIKE %term%` across the declared columns inside a
 * single grouped `where`. The grouping is not cosmetic: without it, an `orWhere` chain escapes the
 * scope that was applied before it, and a provider that scoped to one branch would return every
 * branch the moment somebody typed a name. That is the classic way an isolation rule is lost, and
 * it produces no error — just more results than there should be.
 *
 * **Linking.** {@see self::urlFor()} returns the route only when the viewer may open it, so a hit
 * they matched but cannot reach arrives without a link rather than being dropped (§6.23).
 *
 * **Escaping.** A term containing `%` or `_` is escaped before it reaches `LIKE`, so searching for
 * `50%` finds "50% discount" rather than everything.
 */
abstract class Provider implements SearchProvider
{
    public function weight(): int
    {
        return $this->type()->weight();
    }

    public function permission(): string
    {
        return $this->type()->permission();
    }

    public function module(): string
    {
        return $this->type()->module();
    }

    /**
     * @return list<string>
     */
    public function exactColumns(): array
    {
        return [];
    }

    /**
     * Apply the free-text match across this provider's columns.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>|null  $columns  defaults to `columns()`
     * @return Builder<TModel>
     */
    protected function match(Builder $query, string $term, ?array $columns = null): Builder
    {
        $columns ??= $this->columns();
        $pattern = '%'.$this->escape($term).'%';

        // Grouped, deliberately — see the class note.
        return $query->where(static function (Builder $inner) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $inner->where($column, 'like', $pattern)
                    : $inner->orWhere($column, 'like', $pattern);
            }
        });
    }

    /**
     * `%` and `_` are wildcards in LIKE. A person searching "50%" means the characters.
     */
    protected function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term));
    }

    /**
     * The route for a hit, or null when this viewer may not open it.
     *
     * Null is a real answer here, not a failure: §6.23 says a hit the viewer cannot open is shown
     * without a link so the count never lies about what matched.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function urlFor(string $route, array $parameters, User $viewer, ?string $ability = null): ?string
    {
        // **The panel gate comes first, and it is the one that is easy to miss.** A student holds
        // `students.view` so that they can read their own record in the portal - and that ability
        // alone would otherwise satisfy the permission check below and hand them an `/admin/...`
        // link they cannot open. The palette would look correct and every result would 403.
        //
        // A person reaches an `admin.*` route only if one of their roles is on the Admin panel.
        if (str_starts_with($route, 'admin.') && ! $this->reachesAdmin($viewer)) {
            return null;
        }

        $ability ??= $this->module().'.view';

        $gate = app(Gate::class)->forUser($viewer);

        if (! $gate->allows($ability) && ! $gate->allows($this->module().'.view_any')) {
            return null;
        }

        try {
            return route($route, $parameters);
        } catch (\Throwable) {
            // A route a later phase has not declared yet. A hit with no link is still a hit; a
            // palette that 500s because one route is missing is not.
            return null;
        }
    }

    /**
     * Is this person on the staff panel at all?
     *
     * Asked of the roles rather than of a permission, because panel membership is what a route
     * group's middleware actually enforces - `PanelType::Admin` on a role is the thing that lets
     * somebody past `/admin`. A permission can be granted to a portal role for portal reasons.
     */
    protected function reachesAdmin(User $viewer): bool
    {
        return $viewer->panels()->contains(
            static fn (\App\Enums\PanelType $panel): bool => $panel === \App\Enums\PanelType::Admin,
        );
    }

    /**
     * Does this viewer hold the ability, on this provider's module?
     */
    protected function can(User $viewer, string $ability): bool
    {
        return app(Gate::class)->forUser($viewer)->allows($this->module().'.'.$ability);
    }

    /**
     * Find one record by an exact document number.
     *
     * Used by the `exactMatch()` fast path. Returns null when this provider declares no document
     * columns, which most do not.
     */
    public function exact(string $term, User $viewer): ?SearchHit
    {
        $columns = $this->exactColumns();

        if ($columns === [] || trim($term) === '') {
            return null;
        }

        $model = $this->exactQuery($term, $viewer, $columns);

        return $model === null ? null : $this->present($model, $viewer);
    }

    /**
     * The scoped lookup behind {@see self::exact()}.
     *
     * A provider overrides this only if its exact lookup needs a different scope from its search —
     * none currently do, which is the point: pasting a number must not reach a record the same
     * person could not have found by typing a name.
     *
     * @param  list<string>  $columns
     */
    protected function exactQuery(string $term, User $viewer, array $columns): ?Model
    {
        $rows = $this->query($term, $viewer, 25);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (strcasecmp((string) $row->getAttribute($column), trim($term)) === 0) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<SearchHit>
     */
    public function presentAll(Collection $models, User $viewer): array
    {
        return $models->map(fn (Model $model): SearchHit => $this->present($model, $viewer))->values()->all();
    }
}
