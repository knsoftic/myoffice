<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\ServiceCategory;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage service categories (phase-04 §4, §6.2): `service_categories` = `CRUD` + `STATUS`.
 *
 * A taxonomy carries no row-level scope (§9.1). Deleting a category that still has services is refused
 * by `TaxonomyService::delete()` (a 422 reassign dialog), not by this policy.
 */
final class ServiceCategoryPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'service_categories';

    /**
     * The immediate active/inactive toggle (§8.1): `service_categories.change_status`.
     */
    public function toggleActive(User $user, ServiceCategory $category): bool
    {
        return $this->changeStatus($user, $category);
    }
}
