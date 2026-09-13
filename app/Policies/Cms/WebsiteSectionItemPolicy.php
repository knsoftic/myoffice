<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\WebsiteSection;
use App\Models\Cms\WebsiteSectionItem;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Repeater items are edited inside the section form under `website_sections.edit` (§4.1, §7.1): there is
 * no module and no permission of their own.
 *
 * The repeater's `min` / `max` and the auto-needs-a-metric rule are validation (422), enforced by
 * `SectionService`, not authorization. A policy check here never queries: the parent section is
 * consulted only when the caller passes it (`can('create', [WebsiteSectionItem::class, $section])`) or
 * has already loaded it.
 */
final class WebsiteSectionItemPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'website_sections';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function view(User $user, WebsiteSectionItem $item): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * Add an item to a section (`admin.website.sections.items.store`).
     */
    public function create(User $user, ?WebsiteSection $section = null): bool
    {
        if (! $this->allows($user, Ability::Edit)) {
            return false;
        }

        return $section === null || $this->sectionWritable($section);
    }

    public function update(User $user, WebsiteSectionItem $item): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($item)
            && $this->loadedSectionWritable($item);
    }

    /**
     * Hide or show one item — an item toggle is a draft edit, not a publish (§7.1).
     */
    public function toggle(User $user, WebsiteSectionItem $item): bool
    {
        return $this->update($user, $item);
    }

    /**
     * Reorder one group of a section. Class-level; the exact-set rule lives in the service.
     */
    public function reorder(User $user, ?WebsiteSection $section = null): bool
    {
        return $this->create($user, $section);
    }

    public function delete(User $user, WebsiteSectionItem $item): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($item)
            && $this->loadedSectionWritable($item);
    }

    public function restore(User $user, WebsiteSectionItem $item): bool
    {
        return $this->allows($user, Ability::Edit)
            && $this->isTrashed($item)
            && $this->loadedSectionWritable($item);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, WebsiteSectionItem $item): bool
    {
        return false;
    }

    private function sectionWritable(WebsiteSection $section): bool
    {
        return ! $this->isTrashed($section) && ! $section->isOrphaned();
    }

    /**
     * Only when the parent is already in memory — a policy check must not issue a query per row.
     */
    private function loadedSectionWritable(WebsiteSectionItem $item): bool
    {
        if (! $item->relationLoaded('section')) {
            return true;
        }

        $section = $item->getRelation('section');

        return $section instanceof WebsiteSection && $this->sectionWritable($section);
    }
}
