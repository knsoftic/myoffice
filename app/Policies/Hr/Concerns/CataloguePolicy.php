<?php

declare(strict_types=1);

namespace App\Policies\Hr\Concerns;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The six identical answers every HR **catalogue** needs (phase-07 §7.2, §7.4, §7.5).
 *
 * Departments, designations, shifts, holidays, leave types and salary components are configuration: there
 * is no per-row visibility question, only "does this user hold the ability, and is the module on?". Giving
 * each of them its own hand-written copy of the same six methods is six places for one of them to drift.
 *
 * Anything with a row rule — an employee, a leave request, a slip — does **not** use this trait, because
 * for those the row question is the whole point.
 *
 * The using class declares `public const MODULE = '<slug>';`.
 */
trait CataloguePolicy
{
    use ChecksHrPermissions;

    public function viewAny(User $user): bool
    {
        return $this->holds($user, static::MODULE, Ability::ViewAny)
            || $this->holds($user, static::MODULE, Ability::View);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->holds($user, static::MODULE, Ability::View)
            || $this->holds($user, static::MODULE, Ability::ViewAny);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, static::MODULE, Ability::Create);
    }

    public function update(User $user, Model $record): bool
    {
        return ! $this->isTrashed($record) && $this->holds($user, static::MODULE, Ability::Edit);
    }

    public function delete(User $user, Model $record): bool
    {
        return ! $this->isTrashed($record) && $this->holds($user, static::MODULE, Ability::Delete);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->holds($user, static::MODULE, Ability::Restore);
    }

    public function changeStatus(User $user, Model $record): bool
    {
        return ! $this->isTrashed($record) && $this->holds($user, static::MODULE, Ability::ChangeStatus);
    }
}
