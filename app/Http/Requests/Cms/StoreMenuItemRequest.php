<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ValidatesMenuItem;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;

/**
 * Add a link to a menu (`admin.website.menus.items.store`, `can:menus.create`, §6.3 `storeItem()`).
 */
final class StoreMenuItemRequest extends CmsFormRequest
{
    use ValidatesMenuItem;

    protected function permission(): string
    {
        return 'menus.create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->menuItemRules(partial: false);
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

    public function menu(): ?Menu
    {
        return $this->boundModel('menu', Menu::class);
    }

    public function currentItem(): ?MenuItem
    {
        return null;
    }
}
