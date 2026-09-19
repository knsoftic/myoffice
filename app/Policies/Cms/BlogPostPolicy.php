<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may write, edit and publish blog posts (phase-04 §4, §9.1.1):
 * `blog_posts` = `CRUD_FULL` + `STATUS` + `APPROVE` + `FILES` + `REPORTS`.
 *
 * The author / editor split is permission-driven, never a role name:
 *   · `blog_posts.approve` is the **editor** ability — edit, schedule, publish, unpublish and delete any
 *     author's post, and see every post in `BlogPost::visibleTo()`;
 *   · without it a user is an **author**: `edit` / `delete` / `change_status` / `view_reports` reach only
 *     the posts it authored (`author_id`), and another author's post is a 403 (§11 test 30).
 *
 * `view` needs `blog_posts.view` (the signed preview route's `can:view,blogPost`, §11 test 25) plus an
 * editor, the author, or a post that is publicly live. The ability-to-route map is §7.2's.
 */
final class BlogPostPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'blog_posts';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    /**
     * §9.1.1 `view`, and `admin.blog-posts.preview-link` / `site.blog.preview`.
     */
    public function view(User $user, BlogPost $post): bool
    {
        return $this->allows($user, Ability::View)
            && ($this->isEditor($user) || $post->isAuthoredBy($user) || $post->isPubliclyVisible());
    }

    /**
     * A new post is owned by its creator (`author_id`).
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    public function update(User $user, BlogPost $post): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($post)
            && $this->reaches($user, $post);
    }

    public function delete(User $user, BlogPost $post): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($post)
            && $this->reaches($user, $post);
    }

    public function restore(User $user, BlogPost $post): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($post)
            && $this->reaches($user, $post);
    }

    /**
     * A post is never hard-deleted from the UI: deleting soft-deletes and keeps the slug and the views
     * (§6.7 invariant 7).
     */
    public function forceDelete(User $user, BlogPost $post): bool
    {
        return false;
    }

    /**
     * Publish / schedule / unpublish / archive — your own with `change_status`, anyone's with
     * `change_status` + `approve` (§4). The four routes map onto this one ability (§12.2 Q1).
     */
    public function changeStatus(User $user, BlogPost $post): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($post)
            && $this->reaches($user, $post);
    }

    public function publish(User $user, BlogPost $post): bool
    {
        return $this->changeStatus($user, $post);
    }

    public function schedule(User $user, BlogPost $post): bool
    {
        return $this->changeStatus($user, $post);
    }

    public function unpublish(User $user, BlogPost $post): bool
    {
        return $this->changeStatus($user, $post);
    }

    public function archive(User $user, BlogPost $post): bool
    {
        return $this->changeStatus($user, $post);
    }

    /**
     * The per-post views screen and chart (`admin.blog-posts.stats`), through `visibleTo()` (§9.1.1).
     */
    public function viewReports(User $user, BlogPost $post): bool
    {
        return $this->allows($user, Ability::ViewReports)
            && $this->reaches($user, $post);
    }

    /**
     * The calendar (`admin.blog-posts.calendar`) lists what `visibleTo()` returns: `blog_posts.view_any`.
     */
    public function calendar(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    /**
     * The editor's author select (§8.7): only an editor may write a post under someone else's name.
     */
    public function assignAuthor(User $user): bool
    {
        return $this->isEditor($user);
    }

    public function upload(User $user): bool
    {
        return $this->allows($user, Ability::Upload);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, Ability::Print);
    }

    private function isEditor(User $user): bool
    {
        return $this->allows($user, Ability::Approve);
    }

    /**
     * §9.1.1's ownership test: the editor ability, or the post is the user's own.
     */
    private function reaches(User $user, BlogPost $post): bool
    {
        return $this->isEditor($user) || $post->isAuthoredBy($user);
    }
}
