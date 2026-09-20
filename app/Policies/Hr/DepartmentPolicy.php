<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Models\Hr\Department;
use App\Models\User;
use App\Policies\Hr\Concerns\CataloguePolicy;

/**
 * Who may configure departments (phase-07 §4.1, §9).
 *
 * Departments are configuration, not rows with owners: the whole org chart is visible to anybody who
 * may see the module at all. `assign` (naming the head) is its own ability, because choosing who runs a
 * team is a different decision from renaming one.
 */
final class DepartmentPolicy
{
    use CataloguePolicy;

    public const MODULE = 'departments';

    /**
     * Naming the head of a department (§7.1). Its own ability: choosing who runs a team is a different
     * decision from renaming one, and a business often wants the second without the first.
     */
    public function assign(User $user, Department $department): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign);
    }
}
