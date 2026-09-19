<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Admin\Cms\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StoreServiceRequest;
use App\Http\Requests\Cms\UpdateContentStatusRequest;
use App\Http\Requests\Cms\UpdateServiceRequest;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\Technology;
use App\Models\User;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\SeoService;
use App\Services\Cms\ServiceContentService;
use App\Support\SlugGenerator;
use Generator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The service catalogue — `admin.services.*` (phase-04 §2.3, §6.4, §7.2, §8.2), `module:services`.
 *
 * Writes: `ServiceContentService` (store, update, changeStatus, toggleFeatured, delete),
 * `ContentOrderService::reorder()` and `SeoService::save()` for the SEO tab (D23). `starting_price` goes
 * from the validated decimal string to the service untouched — never a float (§6.4 invariant 1).
 *
 * A new service is a draft. The editor offers `status` and `is_featured` to `services.change_status`
 * holders; a change to either is authorised against that ability (403 otherwise) and applied through
 * `changeStatus()` / `toggleFeatured()` after the save, in the same transaction — never as a side effect
 * of saving the form.
 */
final class ServiceController extends Controller
{
    use RespondsForContent;
    use StreamsCsv;

    private const SORTABLE = ['name', 'status', 'sort_order', 'starting_price', 'updated_at'];

    public function __construct(
        private readonly ServiceContentService $services,
        private readonly ContentOrderService $order,
        private readonly SeoService $seo,
    ) {}

    public function index(ContentListRequest $request): View
    {
        $this->authorize('services.view_any');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, 'services');
        $sort = $request->sortColumn(self::SORTABLE, 'sort_order');
        $direction = $request->sortDirection('asc');

        $services = $this->withAvailable($this->filteredQuery($request, $trashed), ['category', 'technologies', 'image'])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $filters = $request->activeFilters();
        $byStatus = $this->countBy(Service::query(), 'status');

        return view('admin.services.index', [
            'services' => $services,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $trashed,
            'categoryOptions' => ServiceCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'technologyOptions' => Technology::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'statusOptions' => ContentStatus::options(),
            'counts' => [
                'all' => array_sum($byStatus),
                'published' => $byStatus[ContentStatus::Published->value] ?? 0,
                'draft' => $byStatus[ContentStatus::Draft->value] ?? 0,
                'archived' => $byStatus[ContentStatus::Archived->value] ?? 0,
                'featured' => Service::query()->where('is_featured', true)->count(),
                'trashed' => $user->can('services.restore') ? Service::query()->onlyTrashed()->count() : 0,
            ],
            'canReorder' => ! $trashed && $filters === [] && $services->lastPage() === 1 && $user->can('services.edit'),
            'can' => [
                'create' => $user->can('services.create'),
                'edit' => $user->can('services.edit'),
                'delete' => $user->can('services.delete'),
                'changeStatus' => $user->can('services.change_status'),
                'export' => $user->can('services.export'),
                'restore' => $user->can('services.restore'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('services.create');

        return view('admin.services.create', array_merge($this->formData($this->actor($request)), [
            'service' => (new Service)->forceFill(['price_visible' => true, 'status' => ContentStatus::Draft->value, 'sort_order' => 0]),
            'seoMeta' => null,
            'seoInherited' => null,
            'seoCompleteness' => null,
            'publicUrl' => null,
        ]));
    }

    public function store(StoreServiceRequest $request): Response
    {
        $this->authorize('services.create');

        $status = $request->requestedStatus();
        $publish = $status !== null && $status !== ContentStatus::Draft;
        $feature = $request->requestedFeatured() === true;

        if ($publish || $feature) {
            $this->authorize('services.change_status');
        }

        return $this->attempt($request, function () use ($request, $status, $publish, $feature): Response {
            $service = DB::transaction(function () use ($request, $status, $publish, $feature): Service {
                $service = $this->services->store($request->servicePayload(), $request->uploadedImage('image'), $request->technologyIds());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($service, $request->seoPayload());
                }

                if ($publish && $status !== null) {
                    $service = $this->services->changeStatus($service, $status);
                }

                if ($feature && ! (bool) $service->is_featured) {
                    $service = $this->services->toggleFeatured($service);
                }

                return $service;
            });

            return $this->done(
                $request,
                sprintf('"%s" was saved.', $service->name),
                redirect()->route('admin.services.edit', $service),
                ['id' => (int) $service->getKey()],
            );
        }, field: 'name');
    }

    /**
     * There is no separate read-only screen: the editor is the detail view.
     */
    public function show(Request $request, Service $service): Response
    {
        $this->authorize('services.view');
        $this->authorize('view', $service);

        return redirect()->route('admin.services.edit', $service);
    }

    public function edit(Request $request, Service $service): View
    {
        $this->authorize('services.edit');
        $this->authorize('update', $service);

        $this->loadAvailable($service, ['category', 'technologies', 'image', 'editor']);
        $user = $this->actor($request);

        return view('admin.services.edit', array_merge($this->formData($user), [
            'service' => $service,
            'seoMeta' => $this->seo->meta($service),
            'seoInherited' => $this->seo->for($service),
            'seoCompleteness' => $this->seo->completeness($service),
            'publicUrl' => $this->publicUrl($service),
            'can' => [
                'edit' => $user->can('update', $service),
                'delete' => $user->can('services.delete') && $user->can('delete', $service),
                'changeStatus' => $user->can('services.change_status'),
                'createTechnology' => $user->can('technologies.create'),
            ],
        ]));
    }

    public function update(UpdateServiceRequest $request, Service $service): Response
    {
        $this->authorize('services.edit');
        $this->authorize('update', $service);

        $current = $service->status instanceof ContentStatus ? $service->status : ContentStatus::tryFrom((string) $service->status);
        $status = $request->requestedStatus();
        $featured = $request->requestedFeatured();
        $statusChanges = $status !== null && $status !== $current;
        $featuredChanges = $featured !== null && $featured !== (bool) $service->is_featured;

        if ($statusChanges || $featuredChanges) {
            $this->authorize('services.change_status');
            $this->authorize('changeStatus', $service);
        }

        return $this->attempt($request, function () use ($request, $service, $status, $statusChanges, $featuredChanges): Response {
            $service = DB::transaction(function () use ($request, $service, $status, $statusChanges, $featuredChanges): Service {
                $service = $this->services->update(
                    $service,
                    $request->servicePayload(),
                    $request->uploadedImage('image'),
                    $request->hasTechnologies() ? $request->technologyIds() : $this->currentTechnologyIds($service),
                );

                if ($request->seoPayload() !== null) {
                    $this->seo->save($service, $request->seoPayload());
                }

                if ($statusChanges && $status !== null) {
                    $service = $this->services->changeStatus($service, $status);
                }

                if ($featuredChanges) {
                    $service = $this->services->toggleFeatured($service);
                }

                return $service;
            });

            return $this->done($request, sprintf('"%s" was saved.', $service->name), redirect()->route('admin.services.edit', $service));
        }, field: 'name');
    }

    public function destroy(Request $request, Service $service): Response
    {
        $this->authorize('services.delete');
        $this->authorize('delete', $service);

        $name = $service->name;

        return $this->attempt($request, function () use ($request, $service, $name): Response {
            $this->services->delete($service);

            return $this->done($request, sprintf('"%s" was deleted.', $name), redirect()->route('admin.services.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function reorder(ReorderRequest $request): Response
    {
        $this->authorize('services.edit');

        return $this->attempt($request, function () use ($request): Response {
            $this->order->reorder(Service::class, $request->orderedIds());

            return $this->done($request, 'Service order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    public function status(UpdateContentStatusRequest $request, Service $service): Response
    {
        $this->authorize('services.change_status');
        $this->authorize('changeStatus', $service);

        $status = $request->contentStatus();

        return $this->attempt($request, function () use ($request, $service, $status): Response {
            $service = $this->services->changeStatus($service, $status);

            return $this->done(
                $request,
                sprintf('"%s" is now %s.', $service->name, mb_strtolower($status->label())),
                null,
                ['id' => (int) $service->getKey(), 'status' => $status->value],
            );
        }, field: 'status');
    }

    public function featured(Request $request, Service $service): Response
    {
        $this->authorize('services.change_status');
        $this->authorize('toggleFeatured', $service);

        return $this->attempt($request, function () use ($request, $service): Response {
            $service = $this->services->toggleFeatured($service);
            $featured = (bool) $service->is_featured;

            return $this->done(
                $request,
                sprintf('"%s" is %s.', $service->name, $featured ? 'now featured' : 'no longer featured'),
                null,
                ['id' => (int) $service->getKey(), 'is_featured' => $featured],
            );
        });
    }

    /**
     * CSV of the filtered list. The price is the stored decimal string, never re-formatted through a float.
     */
    public function export(ContentListRequest $request): StreamedResponse
    {
        $this->authorize('services.export');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, 'services');

        $rows = function () use ($request, $trashed): Generator {
            $query = $this->withAvailable($this->filteredQuery($request, $trashed), ['category'])
                ->orderBy('sort_order')
                ->orderBy('name');

            foreach ($query->cursor() as $service) {
                yield [
                    $service->getKey(),
                    $service->name,
                    $service->slug,
                    $service->relationLoaded('category') ? $service->category?->name : null,
                    $service->starting_price,
                    (bool) $service->price_visible,
                    $service->status,
                    (bool) $service->is_featured,
                    $service->sort_order,
                    $service->updated_at,
                ];
            }
        };

        return $this->csv(
            'services-'.Carbon::now()->format('Y-m-d').'.csv',
            ['ID', 'Name', 'Slug', 'Category', 'Starting price', 'Price visible', 'Status', 'Featured', 'Sort order', 'Updated at (UTC)'],
            $rows(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The §8.2 filters, shared by the list and the export.
     *
     * @return Builder<Service>
     */
    private function filteredQuery(ContentListRequest $request, bool $trashed): Builder
    {
        $search = $request->searchTerm();
        $category = $request->filterId('category');
        $status = $request->filterEnum('status', ContentStatus::class);
        $featured = $request->filterBool('featured');
        $technology = $request->filterId('technology');

        return Service::query()
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($category !== null, static fn (Builder $query) => $query->where('service_category_id', $category))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($technology !== null, static fn (Builder $query) => $query->whereHas('technologies', static fn (Builder $inner) => $inner->whereKey($technology)))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('slug', 'like', $this->like($search))
                    ->orWhere('short_description', 'like', $this->like($search));
            }));
    }

    /**
     * The option lists the create and edit forms share.
     *
     * @return array<string, mixed>
     */
    private function formData(User $user): array
    {
        return [
            'categoryOptions' => ServiceCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'technologyOptions' => Technology::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'statusOptions' => ContentStatus::options(),
            'reservedSlugs' => SlugGenerator::RESERVED,
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'canChangeStatus' => $user->can('services.change_status'),
            'canCreateTechnology' => $user->can('technologies.create'),
        ];
    }

    /**
     * An update form that does not post `technology_ids` keeps the current chips (the §6.4 signature
     * takes the full list, so it is re-sent unchanged).
     *
     * @return list<int>
     */
    private function currentTechnologyIds(Service $service): array
    {
        return array_map('intval', $service->technologies()->pluck('technologies.id')->all());
    }

    private function publicUrl(Service $service): ?string
    {
        $status = $service->status instanceof ContentStatus ? $service->status : ContentStatus::tryFrom((string) $service->status);

        if ($status !== ContentStatus::Published || ! Route::has('site.services.show')) {
            return null;
        }

        return route('site.services.show', ['service' => $service->slug]);
    }
}
