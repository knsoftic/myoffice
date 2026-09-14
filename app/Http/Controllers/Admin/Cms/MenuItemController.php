<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\MenuItemLinkType;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForCms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\StoreMenuItemRequest;
use App\Http\Requests\Cms\ToggleEnabledRequest;
use App\Http\Requests\Cms\UpdateMenuItemRequest;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Services\Cms\MenuService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menu links — `admin.website.menus.items.store`, `admin.website.menu-items.*` (phase-03 §7.2, §6.3).
 *
 * `MenuService` owns every rule that needs the tree under a lock: two levels at most (INV-6, FT-19), no
 * cycles (FT-20), a parent from the same menu, a route that exists, a safe URL. Its refusals come back as
 * a 422 on the field.
 */
final class MenuItemController extends Controller
{
    use RespondsForCms;

    public function __construct(
        private readonly MenuService $menus,
    ) {}

    public function store(StoreMenuItemRequest $request, Menu $menu): Response
    {
        $this->authorize('menus.create');
        $this->authorize('create', [MenuItem::class, $menu]);

        return $this->attempt($request, function () use ($request, $menu): Response {
            $item = $this->menus->storeItem($menu, $request->menuItemPayload());

            return $this->done(
                $request,
                sprintf('"%s" added to the menu.', $item->label),
                redirect()->route('admin.website.menus.show', $menu),
                $this->state($item),
            );
        }, field: 'parent_id');
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $item): Response
    {
        $this->authorize('menus.edit');
        $this->authorize('update', $item);

        // Switching a link on or off is `menus.change_status` (the toggle route's gate), whichever route
        // carries it: the editor posts the current state back, so only an actual change needs the right.
        if ($request->has('is_enabled') && $request->boolean('is_enabled') !== (bool) $item->is_enabled) {
            $this->authorize('menus.change_status');
            $this->authorize('toggle', $item);
        }

        return $this->attempt($request, function () use ($request, $item): Response {
            $item = $this->menus->updateItem($item, $request->menuItemPayload());

            return $this->done(
                $request,
                sprintf('"%s" saved.', $item->label),
                redirect()->route('admin.website.menus.show', (int) $item->menu_id),
                $this->state($item),
            );
        }, field: 'parent_id');
    }

    /**
     * Enable or disable a link (`can:menus.change_status`) — through `updateItem()` with the status alone.
     */
    public function toggle(ToggleEnabledRequest $request, MenuItem $item): Response
    {
        $this->authorize('menus.change_status');
        $this->authorize('toggle', $item);

        return $this->attempt($request, function () use ($request, $item): Response {
            $item = $this->menus->updateItem($item, ['is_enabled' => $request->enabled()]);

            return $this->done(
                $request,
                $request->enabled() ? sprintf('"%s" enabled.', $item->label) : sprintf('"%s" disabled.', $item->label),
                null,
                $this->state($item),
            );
        });
    }

    public function destroy(Request $request, MenuItem $item): Response
    {
        $this->authorize('menus.delete');
        $this->authorize('delete', $item);

        $menuId = (int) $item->menu_id;
        $label = (string) $item->label;

        return $this->attempt($request, function () use ($request, $item, $menuId, $label): Response {
            $this->menus->deleteItem($item);

            return $this->done($request, sprintf('"%s" removed from the menu.', $label), redirect()->route('admin.website.menus.show', $menuId));
        }, Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(MenuItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'menu_id' => (int) $item->menu_id,
            'parent_id' => $item->parent_id === null ? null : (int) $item->parent_id,
            'label' => (string) $item->label,
            'link_type' => $item->link_type instanceof MenuItemLinkType ? $item->link_type->value : (string) $item->link_type,
            'is_enabled' => (bool) $item->is_enabled,
            'depth' => (int) $item->depth,
        ];
    }
}
