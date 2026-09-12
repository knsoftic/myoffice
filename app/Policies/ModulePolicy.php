<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ability;
use App\Models\Module;
use App\Models\User;
use App\Support\Modules;

/**
 * Who may view and toggle modules.
 *
 * The `modules` module declares only `view_any`, `view` and `change_status` (PermissionRegistry),
 * so toggling is gated by `modules.change_status`. Core modules can never be toggled — the
 * registry decides what is core, not the database row (D5) — and modules are never created or
 * deleted through the UI: they come from `PermissionRegistry` via `ModuleSeeder`.
 */
final class ModulePolicy
{
    private const MODULE = 'modules';

    public function viewAny(User $user): bool
    {
        return $user->can($this->permission(Ability::ViewAny));
    }

    public function view(User $user, Module $module): bool
    {
        return $user->can($this->permission(Ability::View));
    }

    /**
     * Enable or disable a module.
     */
    public function toggle(User $user, Module $module): bool
    {
        if (! $user->can($this->permission(Ability::ChangeStatus))) {
            return false;
        }

        return ! $this->isCore($module);
    }

    /**
     * Editing a module row means flipping its switch, so it follows the same rule as toggle().
     */
    public function update(User $user, Module $module): bool
    {
        return $this->toggle($user, $module);
    }

    public function enable(User $user, Module $module): bool
    {
        return $this->toggle($user, $module);
    }

    public function disable(User $user, Module $module): bool
    {
        return $this->toggle($user, $module);
    }

    /**
     * Modules are declared in code and seeded; they are never created from the UI.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Disabling a module keeps its data; deleting the row is never offered.
     */
    public function delete(User $user, Module $module): bool
    {
        return false;
    }

    public function forceDelete(User $user, Module $module): bool
    {
        return false;
    }

    /**
     * Core modules are immune to toggling (registry first, row flag as a fallback).
     */
    private function isCore(Module $module): bool
    {
        $slug = $module->slug ?? null;

        if (is_string($slug) && $slug !== '' && Modules::isCore($slug)) {
            return true;
        }

        return (bool) ($module->is_core ?? false);
    }

    /**
     * "modules.{ability}" — built from the Ability enum, never a string literal.
     */
    private function permission(Ability $ability): string
    {
        return self::MODULE.'.'.$ability->value;
    }
}
