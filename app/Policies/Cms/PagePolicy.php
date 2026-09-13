<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Enums\Cms\ContentStatus;
use App\Models\Cms\Page;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may create, edit, publish and delete custom pages (phase-03 §4.2, §6.4, §7.3):
 * `pages` = `CRUD_FULL` + `STATUS` + `LOGS` (+ the Phase 1 `restore` kept by the additive registry).
 *
 *   · Publishing, scheduling, unpublishing and reverting are `change_status` ([D-W3-10]); the SEO Expert
 *     holds `pages.edit` but not `pages.change_status`, so an SEO edit goes live when a publisher
 *     publishes it (§9).
 *   · FT-17: an `is_system` page (the four policy pages) keeps editable content but is **never
 *     deletable**, and its slug changes only with `pages.change_status` (§6.4).
 *   · A trashed page is read-only until restored (`pages.restore`); nothing is hard-deleted (INV-14).
 */
final class PagePolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'pages';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    /**
     * Save a draft (content, banner, template, excerpt).
     */
    public function update(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($page)
            && (! $page->status instanceof ContentStatus || $page->status->isEditable());
    }

    /**
     * A slug change breaks inbound links (R-5); on a system page it also needs the publish right (§6.4).
     */
    public function changeSlug(User $user, Page $page): bool
    {
        if (! $this->update($user, $page)) {
            return false;
        }

        return ! $page->isSystem() || $this->allows($user, Ability::ChangeStatus);
    }

    public function publish(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($page);
    }

    public function schedule(User $user, Page $page): bool
    {
        return $this->publish($user, $page);
    }

    public function unpublish(User $user, Page $page): bool
    {
        return $this->publish($user, $page);
    }

    /**
     * The copy is a new draft, never a system page (§6.4).
     */
    public function duplicate(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::Create)
            && ! $this->isTrashed($page);
    }

    /**
     * FT-17: a system page cannot be deleted, whatever the permission.
     */
    public function delete(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($page)
            && ! $page->isSystem();
    }

    public function restore(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($page);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, Page $page): bool
    {
        return false;
    }

    public function viewRevisions(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::ViewLogs);
    }

    /**
     * Revert restores the draft from a revision (never straight to live), a status act.
     */
    public function revert(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($page);
    }

    /**
     * Preview the draft and mint a signed share link (§6.12, `admin.website.pages.preview-link`).
     */
    public function preview(User $user, Page $page): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * Manage the placed sections of a `sections`-layout page — authorized by the sections module too.
     */
    public function manageSections(User $user, Page $page): bool
    {
        return $this->update($user, $page)
            && $page->usesSections()
            && $this->allows($user, Ability::Edit, 'website_sections');
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, Ability::Print);
    }
}
