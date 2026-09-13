<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\FaqCategory;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may manage FAQ groups (phase-03 §4.1, §7.4): `faq_categories` = `CRUD` + `STATUS`, mirroring
 * Phase 1's split of `blog_categories` from `blog_posts`.
 *
 * Deleting a category never deletes its questions: the UI soft-deletes, and a hard delete would null
 * `faqs.faq_category_id` (uncategorised is a supported bucket, §6.13).
 */
final class FaqCategoryPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'faq_categories';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, FaqCategory $category): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    public function update(User $user, FaqCategory $category): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($category);
    }

    /**
     * Enable / disable the group (§8.11 category rail toggle).
     */
    public function toggle(User $user, FaqCategory $category): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($category);
    }

    /**
     * Reorder the category rail. Class-level.
     */
    public function reorder(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    public function delete(User $user, FaqCategory $category): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($category);
    }

    public function restore(User $user, FaqCategory $category): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($category);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, FaqCategory $category): bool
    {
        return false;
    }
}
