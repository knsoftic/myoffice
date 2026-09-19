<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Cms\SiteListRequest;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Technology;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public portfolio — `site.portfolio.index` / `site.portfolio.show` (phase-04 §7.1, §8.11, §9.2),
 * `site_module:portfolio`.
 *
 * Only `PortfolioItem::public()` rows render. `website.portfolio_detail_enabled = false` makes every
 * detail page a 404 and tells the grid not to link. The gallery is the item's attached media in pivot
 * order, cover first, library assets only (D24).
 */
final class PortfolioController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function index(SiteListRequest $request): Response
    {
        $categories = PortfolioCategory::query()->public()->orderBy('sort_order')->orderBy('name')->get();
        $technologies = Technology::query()->public()->orderBy('sort_order')->orderBy('name')->get();

        $categorySlug = $request->slug('category');
        $technologySlug = $request->slug('technology');
        $category = $categorySlug === null ? null : $categories->firstWhere('slug', $categorySlug);
        $technology = $technologySlug === null ? null : $technologies->firstWhere('slug', $technologySlug);

        if (($categorySlug !== null && $category === null) || ($technologySlug !== null && $technology === null)) {
            return $this->notFound();
        }

        $items = $this->eagerPublic(PortfolioItem::query()->public(), [
            'category',
            'cover',
            'technologies' => static fn ($query) => $query->public(),
        ])
            ->when($category !== null, static fn (Builder $query) => $query->where('portfolio_category_id', $category->getKey()))
            ->when($technology !== null, static fn (Builder $query) => $query->whereHas('technologies', static fn (Builder $inner) => $inner->whereKey($technology->getKey())))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('completion_date')
            ->paginate($this->sitePerPage('website.portfolio_per_page', 12))
            ->withQueryString();

        return $this->contentPage('site.portfolio.index', [
            'items' => $items,
            'categories' => $categories,
            'technologies' => $technologies,
            'activeCategory' => $category,
            'activeTechnology' => $technology,
            'detailEnabled' => $this->siteFlag('website.portfolio_detail_enabled'),
        ], $this->routeSeo('site.portfolio.index'), 'site-portfolio', ['title' => 'Portfolio', 'slug' => 'portfolio']);
    }

    public function show(PortfolioItem $portfolioItem): Response
    {
        if (! $this->siteFlag('website.portfolio_detail_enabled')) {
            return $this->notFound();
        }

        $item = $this->eagerPublic(PortfolioItem::query()->public()->whereKey($portfolioItem->getKey()), [
            'category',
            'cover',
            'technologies' => static fn ($query) => $query->public(),
        ])->first();

        if (! $item instanceof PortfolioItem) {
            return $this->notFound();
        }

        $ordered = PortfolioItem::query()->public()->orderBy('sort_order')->orderBy('id')->pluck('slug', 'id')->all();
        $ids = array_keys($ordered);
        $position = array_search($item->getKey(), $ids, true);

        $neighbour = static function (int|false $index) use ($ids, $ordered): ?array {
            if ($index === false || ! isset($ids[$index])) {
                return null;
            }

            return ['id' => $ids[$index], 'slug' => $ordered[$ids[$index]]];
        };

        $related = $this->eagerPublic(PortfolioItem::query()->public(), ['cover', 'category'])
            ->whereKeyNot($item->getKey())
            ->when($item->portfolio_category_id !== null, static fn (Builder $query) => $query->where('portfolio_category_id', $item->portfolio_category_id))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit(3)
            ->get();

        $path = route('site.portfolio.show', ['portfolioItem' => $item->slug], false);

        return $this->contentPage('site.portfolio.show', [
            'item' => $item,
            'gallery' => $this->gallery($item),
            'previous' => $position === false ? null : $neighbour($position - 1),
            'next' => $position === false ? null : $neighbour($position + 1),
            'related' => $related,
        ], $this->modelSeo($item, $path), 'site-portfolio-item site-portfolio-'.$item->slug, ['title' => $item->title, 'slug' => $item->slug]);
    }

    /**
     * Cover first, then the attached images in pivot order, each with its per-placement caption.
     *
     * @return list<array{asset: MediaAsset, caption: string|null}>
     */
    private function gallery(PortfolioItem $item): array
    {
        $rows = DB::table('portfolio_item_media')
            ->where('portfolio_item_id', $item->getKey())
            ->orderBy('sort_order')
            ->orderBy('media_asset_id')
            ->get(['media_asset_id', 'caption']);

        $assets = MediaAsset::query()->whereIn('id', $rows->pluck('media_asset_id')->all())->get()->keyBy('id');
        $cover = $item->cover_media_id === null ? null : (int) $item->cover_media_id;
        $gallery = [];

        foreach ($rows as $row) {
            $asset = $assets->get((int) $row->media_asset_id);

            if (! $asset instanceof MediaAsset) {
                continue;
            }

            $entry = ['asset' => $asset, 'caption' => $row->caption];

            if ((int) $row->media_asset_id === $cover) {
                array_unshift($gallery, $entry);
            } else {
                $gallery[] = $entry;
            }
        }

        return $gallery;
    }
}
