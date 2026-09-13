<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesMenuItem;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;

/**
 * Update a menu link (`admin.website.menu-items.update`, `can:menus.edit`, §6.3 `updateItem()`).
 *
 * The item's own menu scopes the parent lookup, so a parent from another menu is refused here and again
 * by the service (cycles and re-parenting an item that has children are the service's, FT-19, FT-20).
 */
final class UpdateMenuItemRequest extends CmsFormRequest
{
    use ValidatesMenuItem;

    private ?Menu $resolvedMenu = null;

    protected function permission(): string
    {
        return 'menus.edit';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->menuItemRules(partial: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->menuItemMessages();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn ($validator) => $this->menuItemAfter($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['label', 'route_name', 'url', 'anchor', 'icon']);
    }

    public function currentItem(): ?MenuItem
    {
        return $this->boundModel('item', MenuItem::class);
    }

    public function menu(): ?Menu
    {
        if ($this->resolvedMenu !== null) {
            return $this->resolvedMenu;
        }

        $menuId = $this->currentItem()?->getAttribute('menu_id');

        return $this->resolvedMenu = $menuId === null ? null : Menu::query()->find((int) $menuId);
    }
}
