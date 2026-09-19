<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\PortfolioCategory;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage portfolio categories (phase-04 §4, §6.2): `portfolio_categories` = `CRUD` + `STATUS`.
 *
 * No row-level scope (§9.1). The "has children, supply reassign_to" refusal is the service's 422.
 */
final class PortfolioCategoryPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'portfolio_categories';

    /**
     * The immediate active/inactive toggle (§8.1): `portfolio_categories.change_status`.
     */
    public function toggleActive(User $user, PortfolioCategory $category): bool
    {
        return $this->changeStatus($user, $category);
    }
}
