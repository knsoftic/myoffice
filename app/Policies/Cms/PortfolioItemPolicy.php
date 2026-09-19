<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\PortfolioItem;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage portfolio case studies and their galleries (phase-04 §4, §6.3, §7.2):
 * `portfolio` = `CRUD_FULL` + `STATUS` + `FILES`.
 *
 * No row-level scope (§9.1). Gallery rules, per the §7.2 route table:
 *   · attaching images (upload or library pick) — `portfolio.upload`;
 *   · detaching, reordering and setting the cover — `portfolio.edit`.
 * "Not attached to this item" and "more than 20 images" are `PortfolioService` 422s, not 403s.
 * `forceDelete` stays refused here: `PortfolioService::forceDelete()` has no route in Phase 4.
 */
final class PortfolioItemPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'portfolio';

    /**
     * `admin.portfolio.featured` — `portfolio.change_status`.
     */
    public function toggleFeatured(User $user, PortfolioItem $item): bool
    {
        return $this->changeStatus($user, $item);
    }

    /**
     * `admin.portfolio.images.store` — `portfolio.upload` on a live item.
     */
    public function addImages(User $user, PortfolioItem $item): bool
    {
        return $this->allows($user, Ability::Upload)
            && ! $this->isTrashed($item);
    }

    /**
     * `admin.portfolio.images.destroy` / `.reorder` / `.cover` — `portfolio.edit` on a live item.
     */
    public function manageImages(User $user, PortfolioItem $item): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($item);
    }
}
