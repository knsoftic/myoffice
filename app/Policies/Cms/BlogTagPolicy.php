<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Models\Cms\BlogTag;
use App\Models\User;
use App\Policies\Cms\Concerns\AuthorizesContentModule;

/**
 * Who may manage blog tags (phase-04 §4, §6.2): `blog_tags` = `CRUD` + `STATUS`.
 *
 * No row-level scope (§9.1). Creating a tag inline from the post editor (create-on-type) is
 * `BlogService::syncTags()` under the post's own `edit` check, not `blog_tags.create`.
 */
final class BlogTagPolicy
{
    use AuthorizesContentModule;

    private const MODULE = 'blog_tags';

    /**
     * The immediate active/inactive toggle (§8.1): `blog_tags.change_status`.
     */
    public function toggleActive(User $user, BlogTag $tag): bool
    {
        return $this->changeStatus($user, $tag);
    }
}
