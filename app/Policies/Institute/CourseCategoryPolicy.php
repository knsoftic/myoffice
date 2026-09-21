<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\CourseCategory;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may manage the catalogue's top level (§64, phase-14-17 §4.2).
 *
 * **A category holding courses is never deleted.** The FK refuses it anyway; this is what lets the
 * screen grey the button and say why before the database has to, and what makes "move the courses
 * first" the obvious next step rather than a mystery.
 *
 * Reordering is `edit`, not a new ability: §4 is explicit that no new `Ability` case is invented for
 * this phase, and "arrange the list" is plainly a kind of editing it.
 */
final class CourseCategoryPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'course_categories';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, CourseCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, CourseCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit) && ! $this->isTrashed($category);
    }

    /**
     * Reordering the list is editing it.
     */
    public function reorder(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit);
    }

    public function delete(User $user, CourseCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete) && ! $category->isInUse();
    }

    public function restore(User $user, CourseCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    public function changeStatus(User $user, CourseCategory $category): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }
}
