<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\InquiryType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Http\Requests\Cms\SiteListRequest;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public service catalogue — `site.services.index` / `site.services.show` (phase-04 §7.1, §8.11,
 * §9.2), `site_module:services`.
 *
 * Only `Service::public()` rows render (a draft is a 404 and absent from the list, test 9); categories
 * and technology chips are their `public()` rows. Featured services come first, then `sort_order`. A
 * price the business hid (`price_visible = false`) is withheld from the view entirely (test 8).
 */
final class ServiceController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function index(SiteListRequest $request): Response
    {
        $categories = ServiceCategory::query()
            ->public()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $categorySlug = $request->slug('category');
        $category = $categorySlug === null ? null : $categories->firstWhere('slug', $categorySlug);

        if ($categorySlug !== null && $category === null) {
            return $this->notFound();
        }

        $services = $this->eagerPublic(Service::query()->public(), [
            'category',
            'image',
            'technologies' => static fn ($query) => $query->public(),
        ])
            ->when($category !== null, static fn (Builder $query) => $query->where('service_category_id', $category->getKey()))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->sitePerPage('website.services_per_page', 12))
            ->withQueryString();

        $services->getCollection()->each(fn (Service $service) => $this->withholdAmounts($service, 'price_visible', ['starting_price']));

        return $this->contentPage('site.services.index', [
            'services' => $services,
            'categories' => $categories,
            'activeCategory' => $category,
            'hasServices' => $services->total() > 0 || Service::query()->public()->exists(),
        ], $this->routeSeo('site.services.index'), 'site-services', ['title' => 'Services', 'slug' => 'services']);
    }

    public function show(Service $service): Response
    {
        $public = $this->eagerPublic(Service::query()->public()->whereKey($service->getKey()), [
            'category',
            'image',
            'technologies' => static fn ($query) => $query->public(),
        ])->first();

        if (! $public instanceof Service) {
            return $this->notFound();
        }

        $this->withholdAmounts($public, 'price_visible', ['starting_price']);

        $related = $this->eagerPublic(Service::query()->public(), ['image'])
            ->whereKeyNot($public->getKey())
            ->when($public->service_category_id !== null, static fn (Builder $query) => $query->where('service_category_id', $public->service_category_id))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit(3)
            ->get()
            ->each(fn (Service $item) => $this->withholdAmounts($item, 'price_visible', ['starting_price']));

        $technologyIds = method_exists($public, 'technologies') && $public->relationLoaded('technologies')
            ? $public->technologies->modelKeys()
            : [];

        $portfolio = Modules::enabled('portfolio') && $technologyIds !== []
            ? $this->eagerPublic(PortfolioItem::query()->public(), ['cover', 'category'])
                ->whereHas('technologies', static fn (Builder $query) => $query->whereKey($technologyIds))
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->limit(3)
                ->get()
            : collect();

        $path = route('site.services.show', ['service' => $public->slug], false);

        return $this->contentPage('site.services.show', [
            'service' => $public,
            'relatedServices' => $related,
            'relatedPortfolio' => $portfolio,
            'portfolioDetailEnabled' => $this->siteFlag('website.portfolio_detail_enabled'),
            'inquiryUrl' => Route::has('site.contact.index')
                ? route('site.contact.index', ['type' => InquiryType::Service->value, 'service' => $public->getKey()])
                : null,
        ], $this->modelSeo($public, $path), 'site-service site-service-'.$public->slug, ['title' => $public->name, 'slug' => $public->slug]);
    }
}
