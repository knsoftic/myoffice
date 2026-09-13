<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuLocation;
use App\Enums\Cms\MenuVisibility;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\CmsListRequest;
use App\Http\Requests\Cms\ReorderMenuRequest;
use App\Http\Requests\Cms\UpdateMenuRequest;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\MenuService;
use App\Services\Cms\SectionValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Menus — `admin.website.menus.*` (phase-03 §7.2, §8.9): one card per layout slot, the two-level tree
 * builder, rename / activate, reorder and the link check.
 *
 * URLs are **resolved, never stored** (`MenuService::resolveUrl()`, FT-29), so the builder shows what a
 * visitor would get today. A `page` item whose page is a draft, scheduled or trashed is marked broken
 * (rose chip) and is omitted from the public site rather than rendered dead (R-3, FT-28).
 */
final class MenuController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly MenuService $menus,
    ) {}

    public function index(CmsListRequest $request): View
    {
        $this->authorize('menus.view_any');

        $search = $request->searchTerm();

        $menus = Menu::query()
            ->withCount([
                'items',
                'items as enabled_items_count' => static fn (Builder $query) => $query->where('is_enabled', true),
                'items as child_items_count' => static fn (Builder $query) => $query->whereNotNull('parent_id'),
            ])
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('slug', 'like', $this->like($search))
                    ->orWhere('description', 'like', $this->like($search));
            }))
            ->orderBy('location')
            ->orderBy('name')
            ->paginate($this->perPage())
            ->withQueryString();

        $taken = Menu::query()->pluck('location')
            ->map(static fn (mixed $location): string => $location instanceof MenuLocation ? $location->value : (string) $location)
            ->all();

        return view('admin.cms.menus.index', [
            'menus' => $menus,
            // §8.9: a location with no menu yet renders a "not created yet" card (integration G-1).
            'missingLocations' => array_values(array_filter(
                MenuLocation::cases(),
                static fn (MenuLocation $location): bool => ! in_array($location->value, $taken, true),
            )),
            'filters' => $request->activeFilters(),
        ]);
    }

    /**
     * The tree builder for one menu (`admin.website.menus.show`).
     */
    public function show(Request $request, Menu $menu): View
    {
        $this->authorize('menus.view');
        $this->authorize('view', $menu);

        $tree = $menu->rootItems()
            ->with(['children', 'page:id,title,slug,status', 'children.page:id,title,slug,status'])
            ->get();

        $all = $tree->flatMap(static fn (MenuItem $item): Collection => collect([$item])->merge($item->children));
        $user = $this->actor($request);

        return view('admin.cms.menus.show', [
            'menu' => $menu,
            'tree' => $tree,
            'urls' => $all->mapWithKeys(fn (MenuItem $item): array => [(int) $item->getKey() => $this->resolvedUrl($item)])->all(),
            'broken' => $this->brokenReasons($menu),
            'options' => [
                'link_types' => MenuItemLinkType::options(),
                'visibility' => MenuVisibility::options(),
                'pages' => Page::query()->orderBy('title')->get(['id', 'title', 'slug', 'status']),
                'anchors' => WebsiteSection::query()->whereNotNull('anchor')->distinct()->orderBy('anchor')->pluck('anchor')->all(),
                'routes' => $this->linkableRoutes(),
                'icons' => app(SectionValidator::class)->icons() ?? [],
                'parents' => $tree->map(static fn (MenuItem $item): array => ['id' => (int) $item->getKey(), 'label' => (string) $item->label])->values()->all(),
            ],
            'can' => [
                'create' => $user->can('menus.create'),
                'edit' => $user->can('menus.edit'),
                'toggle' => $user->can('menus.change_status'),
                'delete' => $user->can('menus.delete'),
            ],
        ]);
    }

    /**
     * Rename, describe or (de)activate a menu (`admin.website.menus.update`).
     */
    public function update(UpdateMenuRequest $request, Menu $menu): Response
    {
        $this->authorize('menus.edit');
        $this->authorize('update', $menu);

        return $this->attempt($request, function () use ($request, $menu): Response {
            $this->menus->updateMenu($menu, $request->payload());

            return $this->done($request, 'Menu saved.', redirect()->route('admin.website.menus.show', $menu));
        });
    }

    /**
     * Rebuild the two-level tree from the full nested order (`admin.website.menus.reorder`, INV-6).
     */
    public function reorder(ReorderMenuRequest $request, Menu $menu): Response
    {
        $this->authorize('menus.edit');
        $this->authorize('reorder', $menu);

        return $this->attempt($request, function () use ($request, $menu): Response {
            $this->menus->reorder($menu, $request->tree());

            return $this->done($request, 'Menu order saved.', null, ['tree' => $request->tree()]);
        }, field: 'tree');
    }

    /**
     * Every broken item of a menu at once (`admin.website.menus.link-check`, §8.9).
     */
    public function linkCheck(Request $request, Menu $menu): View|JsonResponse
    {
        $this->authorize('menus.view');
        $this->authorize('linkCheck', $menu);

        $reasons = $this->brokenReasons($menu);

        $items = MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->whereIn('id', array_keys($reasons))
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->get(['id', 'label', 'link_type', 'parent_id', 'is_enabled'])
            ->map(static fn (MenuItem $item): array => [
                'id' => (int) $item->getKey(),
                'label' => (string) $item->label,
                'link_type' => $item->link_type instanceof MenuItemLinkType ? $item->link_type->value : (string) $item->link_type,
                'is_enabled' => (bool) $item->is_enabled,
                'reason' => $reasons[(int) $item->getKey()] ?? 'broken target',
            ])
            ->values();

        if ($request->expectsJson()) {
            return new JsonResponse(['menu' => (int) $menu->getKey(), 'items' => $items->all()]);
        }

        return view('admin.cms.menus.link-check', [
            'menu' => $menu,
            'items' => $items,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Item id => the human reason its link is broken (§8.9's rose chip).
     *
     * @return array<int, string>
     */
    private function brokenReasons(Menu $menu): array
    {
        $reasons = [];

        $items = MenuItem::query()
            ->where('menu_id', $menu->getKey())
            ->with(['page' => static fn ($query) => $query->withTrashed()->select(['id', 'title', 'status', 'deleted_at'])])
            ->get(['id', 'link_type', 'page_id', 'route_name', 'url', 'anchor']);

        foreach ($items as $item) {
            $type = $item->link_type instanceof MenuItemLinkType ? $item->link_type : MenuItemLinkType::tryFrom((string) $item->link_type);
            $id = (int) $item->getKey();

            $reason = match ($type) {
                MenuItemLinkType::Page => match (true) {
                    $item->page === null => 'the page no longer exists',
                    $item->page->trashed() => 'the page is in the trash',
                    ! $item->page->isPublic() => 'the page is not published',
                    default => null,
                },
                MenuItemLinkType::Route => blank($item->route_name)
                    ? 'no route chosen'
                    : (Route::has((string) $item->route_name) ? null : 'the route no longer exists'),
                MenuItemLinkType::Url => blank($item->url) ? 'no address entered' : null,
                MenuItemLinkType::SectionAnchor => blank($item->anchor)
                    ? 'no section chosen'
                    : (WebsiteSection::query()->where('anchor', ltrim((string) $item->anchor, '#'))->exists() ? null : 'no section uses this anchor'),
                MenuItemLinkType::None => null,
                null => 'unknown link type',
            };

            if ($reason !== null) {
                $reasons[$id] = $reason;
            }
        }

        return $reasons;
    }

    private function resolvedUrl(MenuItem $item): ?string
    {
        try {
            return $this->menus->resolveUrl($item);
        } catch (Throwable) {
            // A route that disappeared must never break the builder (FT-29); it shows as broken instead.
            return null;
        }
    }

    /**
     * Named public GET routes an item may link to: the site's own pages, never a panel, preview or API.
     *
     * @return array<string, string>
     */
    private function linkableRoutes(): array
    {
        $routes = [];

        /** @var RouteDefinition $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! str_starts_with($name, 'site.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (str_starts_with($name, 'site.preview') || in_array($name, ['site.robots', 'site.sitemap', 'site.sitemap.chunk', 'site.page'], true)) {
                continue;
            }

            $routes[$name] = '/'.ltrim($route->uri(), '/');
        }

        ksort($routes);

        return $routes;
    }
}
