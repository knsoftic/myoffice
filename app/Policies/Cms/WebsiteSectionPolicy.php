<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Enums\Cms\ContentStatus;
use App\Models\Cms\WebsiteSection;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may place, edit, publish and remove website sections (phase-03 §4.2, §7.1).
 *
 *   · `create` = place a section; `edit` = write a draft, rename, reorder, manage repeater items;
 *     `change_status` = publish, unpublish, enable/disable, revert, flush the public cache
 *     ([D-W3-10] — an editor can draft all day and put nothing live); `view_logs` = revisions;
 *     `delete` = remove a placed section.
 *   · INV-7: a required type (`header`, `hero`, `footer`) can be disabled but **never deleted** (FT-16).
 *   · INV-2: an orphaned type (a `section_key` the registry no longer declares) cannot be edited,
 *     published or duplicated, but can be disabled and removed so an admin can clean it up.
 *   · A trashed section is read-only until restored; an `archived` one takes no new draft.
 */
final class WebsiteSectionPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'website_sections';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * Place a new section. Unknown / disallowed / duplicate-unique types are refused by the service (422).
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    /**
     * Save a draft, rename, change the anchor.
     */
    public function update(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($section)
            && ! $section->isOrphaned()
            && $this->isEditable($section);
    }

    /**
     * The `#anchor` is read by the public page straight from the row and menu links point at it, so on a
     * **published** section a change is live the moment it is saved: that needs the publish right, as a
     * live page's slug does ([D-W3-10], INV-1). On a draft it is just part of the draft.
     */
    public function changeAnchor(User $user, WebsiteSection $section): bool
    {
        if (! $this->update($user, $section)) {
            return false;
        }

        return $section->status !== ContentStatus::Published || $this->allows($user, Ability::ChangeStatus);
    }

    /**
     * Add, edit, toggle, reorder or delete repeater items (§7.1 — all under `website_sections.edit`).
     */
    public function manageItems(User $user, WebsiteSection $section): bool
    {
        return $this->update($user, $section);
    }

    /**
     * Reorder the sections of a placement. Class-level: the exact-set rule lives in the service (INV-5).
     */
    public function reorder(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    public function publish(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($section)
            && ! $section->isOrphaned();
    }

    /**
     * Unpublishing keeps the snapshot (FT-10); an orphan may be taken off the site too.
     */
    public function unpublish(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($section);
    }

    /**
     * Enable / disable. Always allowed for a required type — disabling is its only off switch (INV-7).
     */
    public function toggle(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($section);
    }

    /**
     * Only a repeatable type can be duplicated; a unique type would collide on `uq_ws_instance`.
     */
    public function duplicate(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::Create)
            && ! $this->isTrashed($section)
            && ! $section->isOrphaned()
            && ! $section->isUnique();
    }

    /**
     * INV-7 / FT-16: a required section type is never deletable — disable it instead.
     */
    public function delete(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($section)
            && ! $section->isRequired();
    }

    public function restore(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($section);
    }

    /**
     * INV-14: no CMS row is ever hard-deleted from the UI.
     */
    public function forceDelete(User $user, WebsiteSection $section): bool
    {
        return false;
    }

    public function viewRevisions(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::ViewLogs);
    }

    /**
     * Revert restores the **draft** from a revision (FT-11), so it is a status act, like publishing.
     */
    public function revert(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($section)
            && ! $section->isOrphaned();
    }

    /**
     * Preview the draft (§6.12) — previewing is `view`.
     */
    public function preview(User $user, WebsiteSection $section): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * `admin.website.cache.flush` (§7.1). Class-level.
     */
    public function flushCache(User $user): bool
    {
        return $this->allows($user, Ability::ChangeStatus);
    }

    /**
     * `ContentStatus::isEditable()` — everything except `archived` takes a draft.
     */
    private function isEditable(WebsiteSection $section): bool
    {
        return ! $section->status instanceof ContentStatus || $section->status->isEditable();
    }
}
