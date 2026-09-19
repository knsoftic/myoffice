<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Concerns;

use App\Services\Cms\Data\SeoPayload;
use App\Services\Cms\SeoService;
use App\Services\Cms\SpamGuard;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\Response;

/**
 * How a phase-04 public controller renders a content page inside the Phase 3 site shell (phase-04
 * §8.11, phase-03 §8.14).
 *
 *   · the page view receives `$site` — the same `SitePayload` fields every public page gets (live
 *     header and footer sections, the resolved SEO) — plus its own data, and extends
 *     `site.layouts.public`;
 *   · SEO comes from `SeoService::for()` only (D23): a listing by its route key (`site.blog.index`), a
 *     record by its model with the public path;
 *   · nothing per-visitor is printed into a page that may be cached; the form pages (careers detail,
 *     contact) are not behind `site.cache` and receive the `SpamGuard` render token as data;
 *   · "published" is always the model's `public()` scope (§9.2) — never a `where('status', ...)` here;
 *   · a missing, unpublished or switched-off page is the branded 404 of Phase 3's `RendersPages`.
 *
 * The using class must also use `ComposesSite` and `RendersPages`.
 */
trait ComposesContentPages
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $page  title + slug for the layout (preview ribbon, body class)
     */
    protected function contentPage(string $view, array $data, SeoPayload $seo, string $bodyClass, ?array $page = null, bool $preview = false, int $status = Response::HTTP_OK): Response
    {
        $site = $this->sitePayload($this->chrome(), [], $seo, $page, $preview, $bodyClass);

        return $this->withSiteHeaders(
            response()->view($view, array_merge(['site' => $site, 'page' => $page], $data), $status),
            $seo,
            $preview,
        );
    }

    protected function routeSeo(string $routeName): SeoPayload
    {
        return app(SeoService::class)->for($routeName);
    }

    protected function modelSeo(Model $model, string $path, bool $preview = false): SeoPayload
    {
        return app(SeoService::class)->for($model, $preview, $path);
    }

    /**
     * Eager-load public relations, skipping any the model does not declare. Keys may map to a
     * constraint closure (`['technologies' => fn ($q) => $q->public()]`).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int|string, string|Closure>  $relations
     * @return Builder<TModel>
     */
    protected function eagerPublic(Builder $query, array $relations): Builder
    {
        $model = $query->getModel();
        $load = [];

        foreach ($relations as $key => $value) {
            $name = is_string($key) ? $key : (string) $value;

            if (! method_exists($model, explode('.', $name)[0])) {
                continue;
            }

            if (is_string($key)) {
                $load[$key] = $value;
            } else {
                $load[] = $name;
            }
        }

        return $load === [] ? $query : $query->with($load);
    }

    /**
     * A per-page setting, clamped to its registry bounds (§5: `integer, between:3,48`).
     */
    protected function sitePerPage(string $key, int $default): int
    {
        $value = setting($key, $default);

        return is_numeric($value) ? max(3, min(48, (int) $value)) : $default;
    }

    protected function siteFlag(string $key, bool $default = true): bool
    {
        $value = setting($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * The honeypot field, the token field and a fresh signed render token for a public form (§6.9).
     *
     * @return array{honeypot: string, token_field: string, token: string}
     */
    protected function spamFields(): array
    {
        $guard = app(SpamGuard::class);

        return [
            'honeypot' => $guard->honeypotField(),
            'token_field' => $guard->timestampField(),
            'token' => $guard->signedTimestamp(),
        ];
    }

    /**
     * A money amount the business chose not to publish never reaches the view — not even as an
     * attribute a template could print (§6.4 invariant 1, test 8; §8.8, test 39). The instance is a
     * display copy and is never saved.
     *
     * @param  list<string>  $amountColumns
     */
    protected function withholdAmounts(Model $model, string $visibleFlag, array $amountColumns): Model
    {
        if ((bool) $model->getAttribute($visibleFlag)) {
            return $model;
        }

        foreach ($amountColumns as $column) {
            $model->setAttribute($column, null);
        }

        return $model;
    }
}
