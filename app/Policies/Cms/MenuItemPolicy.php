<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may manage menu links (phase-03 §7.2). Items belong to the `menus` module:
 * `create` adds, `edit` changes, `change_status` enables/disables (§4.2), `delete` removes.
 *
 * Depth (INV-6), cycles and link-target validation are 422s from `MenuService`, not authorization.
 * No check here queries: the parent menu is consulted only when passed
 * (`can('create', [MenuItem::class, $menu])`) or already loaded.
 */
final class MenuItemPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'menus';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function view(User $user, MenuItem $item): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * `admin.website.menus.items.store` — `menus.create`.
     */
    public function create(User $user, ?Menu $menu = null): bool
    {
        if (! $this->allows($user, Ability::Create)) {
            return false;
        }

        return $menu === null || ! $this->isTrashed($menu);
    }

    public function update(User $user, MenuItem $item): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($item)
            && $this->loadedMenuWritable($item);
    }

    /**
     * `admin.website.menu-items.toggle` — `menus.change_status` (§4.2).
     */
    public function toggle(User $user, MenuItem $item): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($item)
            && $this->loadedMenuWritable($item);
    }

    public function delete(User $user, MenuItem $item): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($item);
    }

    public function restore(User $user, MenuItem $item): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($item)
            && $this->loadedMenuWritable($item);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, MenuItem $item): bool
    {
        return false;
    }

    /**
     * Only when the menu is already in memory — a policy check must not issue a query per row.
     */
    private function loadedMenuWritable(MenuItem $item): bool
    {
        if (! $item->relationLoaded('menu')) {
            return true;
        }

        $menu = $item->getRelation('menu');

        return $menu instanceof Menu && ! $this->isTrashed($menu);
    }
}
