<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\Menu;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may manage navigation containers (phase-03 §4.2, §7.2): `menus` = `CRUD` + `STATUS`.
 *
 * A menu is bound to one layout slot (`uq_menus_location`); the seeder creates one per `MenuLocation`.
 * Items are authorized by {@see MenuItemPolicy}. A trashed menu is read-only until restored.
 */
final class MenuPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'menus';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, Menu $menu): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    public function update(User $user, Menu $menu): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($menu);
    }

    /**
     * Rewrite the two-level tree (`admin.website.menus.reorder`, INV-5/INV-6 in `MenuService`).
     */
    public function reorder(User $user, Menu $menu): bool
    {
        return $this->update($user, $menu);
    }

    /**
     * Activate / deactivate the whole menu.
     */
    public function toggle(User $user, Menu $menu): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($menu);
    }

    /**
     * List every item whose target is broken (`admin.website.menus.link-check`, §8.9).
     */
    public function linkCheck(User $user, Menu $menu): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function delete(User $user, Menu $menu): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($menu);
    }

    public function restore(User $user, Menu $menu): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($menu);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, Menu $menu): bool
    {
        return false;
    }
}
