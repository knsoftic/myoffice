<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\BlogCategory;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage blog categories (phase-04 §4, §6.2): `blog_categories` = `CRUD` + `STATUS`.
 *
 * No row-level scope (§9.1). The "has posts, supply reassign_to" refusal is the service's 422.
 */
final class BlogCategoryPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'blog_categories';

    /**
     * The immediate active/inactive toggle (§8.1): `blog_categories.change_status`.
     */
    public function toggleActive(User $user, BlogCategory $category): bool
    {
        return $this->changeStatus($user, $category);
    }
}
