<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StorePortfolioItemRequest;
use App\Http\Requests\Cms\UpdateContentStatusRequest;
use App\Http\Requests\Cms\UpdatePortfolioItemRequest;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Technology;
use App\Models\User;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\PortfolioService;
use App\Services\Cms\SeoService;
use App\Support\SlugGenerator;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portfolio case studies — `admin.portfolio.*` (phase-04 §2.7, §6.3, §7.2, §8.3), `module:portfolio`.
 *
 * Writes: `PortfolioService` (store with the create form's images, update, changeStatus, toggleFeatured,
 * delete), `ContentOrderService::reorder()`, and `SeoService::save()` for the SEO tab (D23). The gallery
 * manager on the edit screen talks to `PortfolioImageController`.
 *
 * The editor offers `status` and `is_featured` to `portfolio.change_status` holders; a change is authorised
 * against that ability and applied through the service after the save. Publishing still requires alt text
 * on every gallery image — the service refuses otherwise (§6.3 invariant 2).
 */
final class PortfolioItemController extends Controller
{
    use RespondsForContent;

    /** §6.3 invariant 3. */
    private const MAX_IMAGES = 20;

    private const SORTABLE = ['title', 'client_name', 'completion_date', 'status', 'sort_order', 'updated_at'];

    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly ContentOrderService $order,
        private readonly SeoService $seo,
    ) {}

    public function index(ContentListRequest $request): View
    {
        $this->authorize('portfolio.view_any');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, 'portfolio');
        $sort = $request->sortColumn(self::SORTABLE, 'sort_order');
        $direction = $request->sortDirection('asc');
        $search = $request->searchTerm();
        $category = $request->filterId('category');
        $status = $request->filterEnum('status', ContentStatus::class);
        $featured = $request->filterBool('featured');
        $technology = $request->filterId('technology');
        $year = $request->filterId('year');

        $query = PortfolioItem::query()
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($category !== null, static fn (Builder $query) => $query->where('portfolio_category_id', $category))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($technology !== null, static fn (Builder $query) => $query->whereHas('technologies', static fn (Builder $inner) => $inner->whereKey($technology)))
            ->when($year !== null, static fn (Builder $query) => $query->whereYear('completion_date', $year))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', $this->like($search))
                    ->orWhere('slug', 'like', $this->like($search))
                    ->orWhere('client_name', 'like', $this->like($search));
            }))
            // "7 / 20 images": counted from the pivot in the same query, one subselect, no N+1.
            ->select('portfolio_items.*')
            ->addSelect([
                'images_count' => DB::table('portfolio_item_media')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('portfolio_item_media.portfolio_item_id', 'portfolio_items.id'),
            ]);

        $items = $this->withAvailable($query, ['category', 'technologies', 'cover'])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $filters = $request->activeFilters();
        $byStatus = $this->countBy(PortfolioItem::query(), 'status');

        return view('admin.portfolio.index', [
            'items' => $items,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $trashed,
            'categoryOptions' => PortfolioCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'technologyOptions' => Technology::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'yearOptions' => $this->yearOptions(),
            'statusOptions' => ContentStatus::options(),
            'counts' => [
                'all' => array_sum($byStatus),
                'published' => $byStatus[ContentStatus::Published->value] ?? 0,
                'draft' => $byStatus[ContentStatus::Draft->value] ?? 0,
                'archived' => $byStatus[ContentStatus::Archived->value] ?? 0,
                'featured' => PortfolioItem::query()->where('is_featured', true)->count(),
                'trashed' => $user->can('portfolio.restore') ? PortfolioItem::query()->onlyTrashed()->count() : 0,
            ],
            'canReorder' => ! $trashed && $filters === [] && $items->lastPage() === 1 && $user->can('portfolio.edit'),
            'can' => [
                'create' => $user->can('portfolio.create'),
                'edit' => $user->can('portfolio.edit'),
                'delete' => $user->can('portfolio.delete'),
                'changeStatus' => $user->can('portfolio.change_status'),
                'upload' => $user->can('portfolio.upload'),
                'restore' => $user->can('portfolio.restore'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('portfolio.create');

        return view('admin.portfolio.create', array_merge($this->formData($this->actor($request)), [
            'item' => (new PortfolioItem)->forceFill(['status' => ContentStatus::Draft->value, 'sort_order' => 0]),
            'gallery' => collect(),
            'seoMeta' => null,
            'seoInherited' => null,
            'seoCompleteness' => null,
            'publicUrl' => null,
        ]));
    }

    public function store(StorePortfolioItemRequest $request): Response
    {
        $this->authorize('portfolio.create');

        if ($request->galleryUploads() !== []) {
            $this->authorize('portfolio.upload');
        }

        $status = $request->requestedStatus();
        $publish = $status !== null && $status !== ContentStatus::Draft;
        $feature = $request->requestedFeatured() === true;

        if ($publish || $feature) {
            $this->authorize('portfolio.change_status');
        }

        return $this->attempt($request, function () use ($request, $status, $publish, $feature): Response {
            $item = DB::transaction(function () use ($request, $status, $publish, $feature): PortfolioItem {
                $item = $this->portfolio->store($request->portfolioPayload(), $request->galleryUploads(), $request->technologyIds());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($item, $request->seoPayload());
                }

                if ($publish && $status !== null) {
                    $item = $this->portfolio->changeStatus($item, $status);
                }

                if ($feature && ! (bool) $item->is_featured) {
                    $item = $this->portfolio->toggleFeatured($item);
                }

                return $item;
            });

            return $this->done(
                $request,
                sprintf('"%s" was saved. Add its gallery below.', $item->title),
                redirect()->route('admin.portfolio.edit', $item),
                ['id' => (int) $item->getKey()],
            );
        }, field: 'images');
    }

    public function show(Request $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.view');
        $this->authorize('view', $item);

        return redirect()->route('admin.portfolio.edit', $item);
    }

    public function edit(Request $request, PortfolioItem $item): View
    {
        $this->authorize('portfolio.edit');
        $this->authorize('update', $item);

        $this->loadAvailable($item, ['category', 'technologies', 'cover', 'editor']);
        $user = $this->actor($request);

        return view('admin.portfolio.edit', array_merge($this->formData($user), [
            'item' => $item,
            // Collection<MediaAsset> in pivot order, each with ->pivot->caption / ->pivot->sort_order.
            'gallery' => $item->media()->withTrashed()->get(),
            'maxImages' => self::MAX_IMAGES,
            'seoMeta' => $this->seo->meta($item),
            'seoInherited' => $this->seo->for($item),
            'seoCompleteness' => $this->seo->completeness($item),
            'publicUrl' => $this->publicUrl($item),
            'can' => [
                'edit' => $user->can('update', $item),
                'delete' => $user->can('portfolio.delete') && $user->can('delete', $item),
                'changeStatus' => $user->can('portfolio.change_status'),
                'upload' => $user->can('portfolio.upload'),
                'mediaLibrary' => $user->can('website_media.view_any'),
            ],
        ]));
    }

    public function update(UpdatePortfolioItemRequest $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.edit');
        $this->authorize('update', $item);

        $current = $item->status instanceof ContentStatus ? $item->status : ContentStatus::tryFrom((string) $item->status);
        $status = $request->requestedStatus();
        $featured = $request->requestedFeatured();
        $statusChanges = $status !== null && $status !== $current;
        $featuredChanges = $featured !== null && $featured !== (bool) $item->is_featured;

        if ($statusChanges || $featuredChanges) {
            $this->authorize('portfolio.change_status');
            $this->authorize('changeStatus', $item);
        }

        return $this->attempt($request, function () use ($request, $item, $status, $statusChanges, $featuredChanges): Response {
            $item = DB::transaction(function () use ($request, $item, $status, $statusChanges, $featuredChanges): PortfolioItem {
                $item = $this->portfolio->update(
                    $item,
                    $request->portfolioPayload(),
                    $request->hasTechnologies() ? $request->technologyIds() : $this->currentTechnologyIds($item),
                );

                if ($request->seoPayload() !== null) {
                    $this->seo->save($item, $request->seoPayload());
                }

                if ($statusChanges && $status !== null) {
                    $item = $this->portfolio->changeStatus($item, $status);
                }

                if ($featuredChanges) {
                    $item = $this->portfolio->toggleFeatured($item);
                }

                return $item;
            });

            return $this->done($request, sprintf('"%s" was saved.', $item->title), redirect()->route('admin.portfolio.edit', $item));
        }, field: 'title');
    }

    /**
     * Soft delete: every attachment and every binary stays (§6.3 invariant 4).
     */
    public function destroy(Request $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.delete');
        $this->authorize('delete', $item);

        $title = $item->title;

        return $this->attempt($request, function () use ($request, $item, $title): Response {
            $this->portfolio->delete($item);

            return $this->done($request, sprintf('"%s" was deleted. Its images stay in the media library.', $title), redirect()->route('admin.portfolio.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function reorder(ReorderRequest $request): Response
    {
        $this->authorize('portfolio.edit');

        return $this->attempt($request, function () use ($request): Response {
            $this->order->reorder(PortfolioItem::class, $request->orderedIds());

            return $this->done($request, 'Project order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    public function status(UpdateContentStatusRequest $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.change_status');
        $this->authorize('changeStatus', $item);

        $status = $request->contentStatus();

        return $this->attempt($request, function () use ($request, $item, $status): Response {
            $item = $this->portfolio->changeStatus($item, $status);

            return $this->done(
                $request,
                sprintf('"%s" is now %s.', $item->title, mb_strtolower($status->label())),
                null,
                ['id' => (int) $item->getKey(), 'status' => $status->value],
            );
        }, field: 'status');
    }

    public function featured(Request $request, PortfolioItem $item): Response
    {
        $this->authorize('portfolio.change_status');
        $this->authorize('toggleFeatured', $item);

        return $this->attempt($request, function () use ($request, $item): Response {
            $item = $this->portfolio->toggleFeatured($item);
            $featured = (bool) $item->is_featured;

            return $this->done(
                $request,
                sprintf('"%s" is %s.', $item->title, $featured ? 'now featured' : 'no longer featured'),
                null,
                ['id' => (int) $item->getKey(), 'is_featured' => $featured],
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function formData(User $user): array
    {
        return [
            'categoryOptions' => PortfolioCategory::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'technologyOptions' => Technology::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all(),
            'statusOptions' => ContentStatus::options(),
            'reservedSlugs' => SlugGenerator::RESERVED,
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'canChangeStatus' => $user->can('portfolio.change_status'),
            'canCreateTechnology' => $user->can('technologies.create'),
        ];
    }

    /**
     * Completion years present in the table, newest first.
     *
     * @return array<int, int>
     */
    private function yearOptions(): array
    {
        $years = [];

        foreach (PortfolioItem::query()->whereNotNull('completion_date')->pluck('completion_date') as $date) {
            $year = (int) substr((string) ($date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date), 0, 4);

            if ($year > 0) {
                $years[$year] = $year;
            }
        }

        krsort($years);

        return $years;
    }

    /**
     * @return list<int>
     */
    private function currentTechnologyIds(PortfolioItem $item): array
    {
        return array_map('intval', $item->technologies()->pluck('technologies.id')->all());
    }

    private function publicUrl(PortfolioItem $item): ?string
    {
        $status = $item->status instanceof ContentStatus ? $item->status : ContentStatus::tryFrom((string) $item->status);

        if ($status !== ContentStatus::Published || ! Route::has('site.portfolio.show')) {
            return null;
        }

        return route('site.portfolio.show', ['portfolioItem' => $item->slug]);
    }
}
