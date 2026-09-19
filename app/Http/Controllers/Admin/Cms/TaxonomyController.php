<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\DeleteTaxonomyRequest;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StoreTaxonomyRequest;
use App\Http\Requests\Cms\TaxonomyDefinition;
use App\Http\Requests\Cms\UpdateTaxonomyRequest;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\SeoService;
use App\Services\Cms\TaxonomyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The five taxonomy screens of phase-04 §8.1 — service categories, portfolio categories, blog
 * categories, blog tags and technologies — one implementation, five thin subclasses naming their module.
 *
 * Each is a small inline-modal list (§8.1: "4-field forms, a full page is wasteful"), so `create`,
 * `show` and `edit` answer a JSON payload for the modal and, for a plain browser request, redirect to
 * the list with the modal opened (`?create=1` / `?edit={id}`). Writes go to `TaxonomyService` (store,
 * update, toggleActive, delete with reassignment) and `ContentOrderService` (reorder); the three
 * category lists also write their SEO block through `SeoService::save()` (D23).
 *
 * The `{term}` parameter is resolved here against the subclass's model, so a service-category id can
 * never be fed to the technologies routes. A trashed or unknown term is a 404.
 */
abstract class TaxonomyController extends Controller
{
    use RespondsForContent;

    public function __construct(
        protected readonly TaxonomyService $taxonomies,
        protected readonly ContentOrderService $order,
        protected readonly SeoService $seo,
    ) {}

    /**
     * The `TaxonomyDefinition::DEFINITIONS` key this controller serves.
     */
    abstract protected function module(): string;

    public function index(ContentListRequest $request): Response
    {
        $definition = $this->definition();
        $module = $this->module();
        $this->authorize($module.'.view_any');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, $module);
        $state = $request->filterString('state');
        $search = $request->searchTerm();
        $sortable = ['name', 'slug', 'updated_at'];

        if ($definition['sortable']) {
            $sortable[] = 'sort_order';
        }

        $sort = $request->sortColumn($sortable, $definition['sortable'] ? 'sort_order' : 'name');
        $direction = $request->sortDirection('asc');

        $query = $this->query()
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($state === 'active', static fn (Builder $query) => $query->where('is_active', true))
            ->when($state === 'inactive', static fn (Builder $query) => $query->where('is_active', false))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('slug', 'like', $this->like($search));
            }));

        if ($definition['image_column'] !== null) {
            $this->withAvailable($query, [$definition['image_field'] === 'logo' ? 'logo' : 'image']);
        }

        $terms = $this->withAvailableCounts($query, $definition['children'])
            ->orderBy($sort, $direction)
            ->orderBy('name')
            ->paginate($this->perPage())
            ->withQueryString();

        $filters = $request->activeFilters();
        $active = $this->countBy($this->query(), 'is_active');

        // The list view sets its own `$taxonomy` presentation array; the controller supplies the data.
        return response()->view($definition['route'].'.index', [
            'terms' => $terms,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $trashed,
            'counts' => [
                'all' => array_sum($active),
                'active' => $active['1'] ?? 0,
                'inactive' => $active['0'] ?? 0,
            ],
            // Every live term: the reassign dialog lists all of them except the row being deleted.
            'reassignOptions' => $this->query()->orderBy('name')->pluck('name', 'id')->all(),
            'mediaLibrary' => $definition['image_column'] !== null && $user->can($module.'.edit') ? $this->mediaLibrary() : [],
            'maxUploadMb' => $this->maxUploadMb(),
            // Reordering posts the exact visible set: only from an unfiltered, single-page list.
            'canReorder' => $definition['sortable'] && ! $trashed && $filters === [] && $terms->lastPage() === 1 && $user->can($module.'.edit'),
            'creating' => $request->query('create') === '1' && $user->can($module.'.create'),
            'editingId' => $this->queryId($request->query('edit')),
            'can' => [
                'create' => $user->can($module.'.create'),
                'edit' => $user->can($module.'.edit'),
                'delete' => $user->can($module.'.delete'),
                'changeStatus' => $user->can($module.'.change_status'),
                'restore' => $user->can($module.'.restore'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($this->module().'.create');

        if ($request->expectsJson()) {
            return new JsonResponse(['term' => $this->payloadFor(null), 'taxonomy' => $this->describe()]);
        }

        return redirect()->route($this->definition()['route'].'.index', ['create' => 1]);
    }

    public function store(StoreTaxonomyRequest $request): Response
    {
        $definition = $this->definition();
        $this->authorize($this->module().'.create');

        return $this->attempt($request, function () use ($request, $definition): Response {
            $term = DB::transaction(function () use ($request, $definition): Model {
                $term = $this->taxonomies->store($definition['model'], $request->termPayload(), $request->termImage());

                if ($definition['seo'] && $request->seoPayload() !== null) {
                    $this->seo->save($term, $request->seoPayload());
                }

                return $term;
            });

            return $this->done(
                $request,
                sprintf('"%s" was added.', (string) $term->getAttribute('name')),
                redirect()->route($definition['route'].'.index'),
                // `id` and `name` at the top level for the inline "create technology" of the service editor.
                ['id' => (int) $term->getKey(), 'name' => (string) $term->getAttribute('name'), 'term' => $this->payloadFor($term)],
            );
        }, field: 'name');
    }

    public function show(Request $request, string $term): Response
    {
        $this->authorize($this->module().'.view');
        $model = $this->term($term);
        $this->authorize('view', $model);

        return $this->modalResponse($request, $model);
    }

    /**
     * The three categories carry SEO, so they have a full editor page; tags and technologies are edited
     * in the list's dialog (JSON for the dialog, or back to the list with it open).
     */
    public function edit(Request $request, string $term): Response
    {
        $definition = $this->definition();
        $this->authorize($this->module().'.edit');
        $model = $this->term($term);
        $this->authorize('update', $model);

        if (! $definition['seo'] || $request->expectsJson()) {
            return $this->modalResponse($request, $model);
        }

        if ($definition['image_column'] !== null) {
            $this->loadAvailable($model, [$definition['image_field'] === 'logo' ? 'logo' : 'image']);
        }

        return response()->view($definition['route'].'.edit', [
            'term' => $model,
            'seoMeta' => $this->seo->meta($model),
            'seoInherited' => $this->seo->for($model),
            'seoCompleteness' => $this->seo->completeness($model),
            'publicUrl' => $this->publicUrl($model),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'can' => [
                'delete' => $this->actor($request)->can($this->module().'.delete'),
                'changeStatus' => $this->actor($request)->can($this->module().'.change_status'),
            ],
        ]);
    }

    /**
     * Flip `is_active` from the list's inline switch (§8.1) — `{module}.change_status`, through
     * `TaxonomyService::toggleActive()`. The same switch also works through `update` with `is_active` alone.
     */
    public function toggle(Request $request, string $term): Response
    {
        $this->authorize($this->module().'.change_status');
        $model = $this->term($term);
        $this->authorize('toggleActive', $model);

        return $this->attempt($request, function () use ($request, $model): Response {
            $model = $this->taxonomies->toggleActive($model);
            $active = (bool) $model->getAttribute('is_active');

            return $this->done(
                $request,
                sprintf('"%s" is now %s.', (string) $model->getAttribute('name'), $active ? 'active' : 'hidden from the website'),
                null,
                ['id' => (int) $model->getKey(), 'is_active' => $active],
            );
        }, field: 'is_active');
    }

    public function update(UpdateTaxonomyRequest $request, string $term): Response
    {
        $definition = $this->definition();
        $this->authorize($this->module().'.edit');
        $model = $this->term($term);
        $this->authorize('update', $model);

        // Showing or hiding a term on the website is `{module}.change_status` (§4 `STATUS`), whether it
        // comes from the list's inline switch or from the edit modal.
        $payload = $request->termPayload();

        if (array_key_exists('is_active', $payload) && (bool) $payload['is_active'] !== (bool) $model->getAttribute('is_active')) {
            $this->authorize($this->module().'.change_status');
            $this->authorize('toggleActive', $model);
        }

        return $this->attempt($request, function () use ($request, $definition, $model): Response {
            // The list's inline switch posts `is_active` alone (§8.1).
            if ($request->isActiveToggleOnly()) {
                $wanted = (bool) ($request->termPayload()['is_active'] ?? false);

                if ((bool) $model->getAttribute('is_active') !== $wanted) {
                    $model = $this->taxonomies->toggleActive($model);
                }

                return $this->done(
                    $request,
                    sprintf('"%s" is now %s.', (string) $model->getAttribute('name'), $wanted ? 'active' : 'hidden from the website'),
                    null,
                    ['term' => $this->payloadFor($model)],
                );
            }

            $model = DB::transaction(function () use ($request, $definition, $model): Model {
                $model = $this->taxonomies->update($model, $request->termPayload(), $request->termImage());

                if ($definition['seo'] && $request->seoPayload() !== null) {
                    $this->seo->save($model, $request->seoPayload());
                }

                return $model;
            });

            return $this->done(
                $request,
                sprintf('"%s" was saved.', (string) $model->getAttribute('name')),
                redirect()->route($definition['route'].'.index'),
                ['term' => $this->payloadFor($model)],
            );
        }, field: 'name');
    }

    /**
     * Soft delete. A term that still has children is refused unless `reassign_to` names another term of
     * the same list, which receives every child first (§6.2, test 60).
     */
    public function destroy(DeleteTaxonomyRequest $request, string $term): Response
    {
        $definition = $this->definition();
        $this->authorize($this->module().'.delete');
        $model = $this->term($term);
        $this->authorize('delete', $model);

        $name = (string) $model->getAttribute('name');

        return $this->attempt($request, function () use ($request, $definition, $model, $name): Response {
            $this->taxonomies->delete($model, $request->reassignTo());

            return $this->done(
                $request,
                $request->reassignTo() === null
                    ? sprintf('"%s" was deleted.', $name)
                    : sprintf('"%s" was deleted and its linked records were moved.', $name),
                redirect()->route($definition['route'].'.index'),
            );
        }, field: 'reassign_to');
    }

    /**
     * Drag-and-drop order: `sort_order` 1..n in one transaction and one activity entry (§6.2).
     */
    public function reorder(ReorderRequest $request): Response
    {
        $definition = $this->definition();
        $this->authorize($this->module().'.edit');
        abort_unless($definition['sortable'], Response::HTTP_NOT_FOUND);

        return $this->attempt($request, function () use ($request, $definition): Response {
            $this->order->reorder($definition['model'], $request->orderedIds());

            return $this->done($request, 'Order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        $definition = TaxonomyDefinition::for($this->module());

        abort_if($definition === null, Response::HTTP_NOT_FOUND);

        return $definition;
    }

    /**
     * @return Builder<Model>
     */
    protected function query(): Builder
    {
        /** @var class-string<Model> $model */
        $model = $this->definition()['model'];

        return $model::query();
    }

    protected function term(string $id): Model
    {
        $model = $this->query()->find($this->routeId($id));

        abort_unless($model instanceof Model, Response::HTTP_NOT_FOUND);

        return $model;
    }

    /**
     * What the views and the modal need to know about the list, without the model class.
     *
     * @return array<string, mixed>
     */
    protected function describe(): array
    {
        $definition = $this->definition();

        return [
            'module' => $this->module(),
            'label' => $definition['label'],
            'plural' => $definition['plural'],
            'route' => $definition['route'],
            'fields' => [
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'color' => $definition['color'],
                'sort_order' => $definition['sortable'],
                'image' => $definition['image_field'],
                'seo' => $definition['seo'],
            ],
            'children' => $definition['children'],
        ];
    }

    /**
     * Where an active category is seen on the website, when its public route exists.
     */
    private function publicUrl(Model $term): ?string
    {
        if (! (bool) $term->getAttribute('is_active')) {
            return null;
        }

        $slug = (string) $term->getAttribute('slug');

        return match ($this->module()) {
            'blog_categories' => Route::has('site.blog.category') ? route('site.blog.category', ['blogCategory' => $slug]) : null,
            'blog_tags' => Route::has('site.blog.tag') ? route('site.blog.tag', ['blogTag' => $slug]) : null,
            'service_categories' => Route::has('site.services.index') ? route('site.services.index', ['category' => $slug]) : null,
            'portfolio_categories' => Route::has('site.portfolio.index') ? route('site.portfolio.index', ['category' => $slug]) : null,
            default => null,
        };
    }

    private function modalResponse(Request $request, Model $model): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['term' => $this->payloadFor($model), 'taxonomy' => $this->describe()]);
        }

        return redirect()->route($this->definition()['route'].'.index', ['edit' => $model->getKey()]);
    }

    /**
     * The term as the modal edits it: only the columns this list has, plus its SEO values.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(?Model $term): array
    {
        $definition = $this->definition();

        $data = [
            'id' => $term?->getKey(),
            'name' => $term?->getAttribute('name'),
            'slug' => $term?->getAttribute('slug'),
            'is_active' => $term === null ? true : (bool) $term->getAttribute('is_active'),
        ];

        foreach (['description', 'icon', 'color'] as $field) {
            if ($definition[$field]) {
                $data[$field] = $term?->getAttribute($field);
            }
        }

        if ($definition['sortable']) {
            $data['sort_order'] = $term === null ? 0 : (int) $term->getAttribute('sort_order');
        }

        if ($definition['image_column'] !== null) {
            $data[$definition['image_column']] = $term?->getAttribute($definition['image_column']);
        }

        if ($definition['seo']) {
            $meta = $term === null ? null : $this->seo->meta($term);
            $data['seo'] = $meta?->only(SeoService::WRITABLE);
        }

        return $data;
    }

    private function queryId(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && $value !== '0' ? (int) $value : null;
    }
}
