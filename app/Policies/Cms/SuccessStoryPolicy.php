<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\SuccessStory;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage success stories (phase-04 §4, §7.2): `success_stories` = `CRUD` + `STATUS` + `FILES`.
 *
 * Staff-authored with no approval queue, so there is no `approve` action here; publishing and featuring
 * are `success_stories.change_status`, reordering `success_stories.edit`. No row-level scope (§9.1).
 */
final class SuccessStoryPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'success_stories';

    /**
     * `admin.success-stories.featured` — `success_stories.change_status`.
     */
    public function toggleFeatured(User $user, SuccessStory $story): bool
    {
        return $this->changeStatus($user, $story);
    }
}
