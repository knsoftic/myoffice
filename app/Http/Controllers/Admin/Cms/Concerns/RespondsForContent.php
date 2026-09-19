<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms\Concerns;

use App\Http\Requests\Cms\ContentListRequest;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every phase-04 admin controller shares, on top of Phase 3's `RespondsForCms` (explicit
 * authorization, one response shape for a form post and for an Alpine `fetch()`, a toast on every
 * write).
 *
 * Phase 4's services refuse a business rule with a domain exception (phase-04 §6.2 "domain exception →
 * 422 toast", §6.3, §6.5, §6.7, §6.8): a term that still has children, a cover that is not attached, a
 * review featured before it is approved, a stage the pipeline does not allow. Anything extending
 * `\DomainException` — or Phase 3's `ContentActionNotAllowedException` — becomes the same 422 (or 403
 * on a protected record) with the service's message, never a stack trace.
 */
trait RespondsForContent
{
    use RespondsForCms {
        attempt as private cmsAttempt;
    }

    /**
     * Run a service call, turning a CMS or Phase 4 domain refusal into a response.
     *
     * @param  Closure(): Response  $action
     */
    protected function attempt(Request $request, Closure $action, int $refusalStatus = 422, string $field = 'action'): Response
    {
        try {
            return $this->cmsAttempt($request, $action, $refusalStatus, $field);
        } catch (DomainException $exception) {
            return $this->refuse($request, $exception->getMessage(), $refusalStatus, $field);
        }
    }

    /**
     * Eager-load the relations a screen renders, skipping any the model does not declare, so a list
     * never issues one query per row for them (§11 test 32) and a renamed relation degrades to a lazy
     * load instead of an exception. The relation names are listed in the integration file.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $relations
     * @return Builder<TModel>
     */
    protected function withAvailable(Builder $query, array $relations): Builder
    {
        $model = $query->getModel();
        $load = [];

        foreach ($relations as $relation) {
            $root = explode('.', $relation)[0];

            if (method_exists($model, $root)) {
                $load[] = $relation;
            }
        }

        return $load === [] ? $query : $query->with($load);
    }

    /**
     * `loadMissing()` for the relations one record's screen renders, skipping undeclared ones.
     *
     * @param  list<string>  $relations
     */
    protected function loadAvailable(Model $model, array $relations): Model
    {
        $load = array_values(array_filter(
            $relations,
            static fn (string $relation): bool => method_exists($model, explode('.', $relation)[0]),
        ));

        return $load === [] ? $model : $model->loadMissing($load);
    }

    /**
     * `withCount()` for the relations the model declares. An entry may alias its count
     * (`'items as portfolio_items_count'`).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $relations
     * @return Builder<TModel>
     */
    protected function withAvailableCounts(Builder $query, array $relations): Builder
    {
        $model = $query->getModel();
        $count = array_values(array_filter(
            $relations,
            static fn (string $relation): bool => method_exists($model, trim(explode(' as ', $relation)[0])),
        ));

        return $count === [] ? $query : $query->withCount($count);
    }

    /**
     * The trashed view of a list is shown only to someone who holds the module's `restore` ability
     * (phase-04 §8.1 "trashed toggle where restore exists").
     */
    protected function wantsTrashed(ContentListRequest $request, User $user, string $module): bool
    {
        return $request->filterBool('trashed') === true && $user->can($module.'.restore');
    }

    /**
     * `security.max_upload_mb` for the upload controls' courtesy hint (the server rule is authoritative).
     */
    protected function maxUploadMb(): int
    {
        $megabytes = setting('security.max_upload_mb', 10);

        return is_numeric($megabytes) ? max(1, (int) $megabytes) : 10;
    }

    /**
     * Count rows per value of one column, for the list screens' tab badges.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array<string, int>
     */
    protected function countBy(Builder $query, string $column): array
    {
        return $query
            ->selectRaw($query->getModel()->qualifyColumn($column).' AS bucket, COUNT(*) AS aggregate')
            ->groupBy($query->getModel()->qualifyColumn($column))
            ->pluck('aggregate', 'bucket')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * A record the user may not see is a 404, never a 403 — ids cannot be probed (resolutions §8 #5).
     */
    protected function abortUnlessVisible(bool $visible): void
    {
        abort_unless($visible, Response::HTTP_NOT_FOUND);
    }

    /**
     * An id route parameter, or a 404 when it is not a positive integer.
     */
    protected function routeId(mixed $value): int
    {
        abort_unless(is_int($value) || (is_string($value) && ctype_digit($value) && $value !== '0'), Response::HTTP_NOT_FOUND);

        return (int) $value;
    }
}
