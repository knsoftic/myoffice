<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\CtaBlock;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may manage reusable CTA blocks (phase-03 §4.1, §6.13, §7.4): `website_cta_blocks` = `CRUD` +
 * `STATUS` + `LOGS`.
 *
 *   · `delete` is refused while `usage_count > 0` (§4.1) — the controller shows the usage list.
 *   · `key` is immutable once any section references it ({@see self::changeKey()}).
 *   · CTA blocks are status-gated and live (§2.15): `change_status` publishes / drafts a block.
 */
final class CtaBlockPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'website_cta_blocks';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, CtaBlock $block): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Create);
    }

    public function update(User $user, CtaBlock $block): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($block);
    }

    /**
     * The stable `key` may change only while nothing references the block (§6.13).
     */
    public function changeKey(User $user, CtaBlock $block): bool
    {
        return $this->update($user, $block)
            && ! $block->isInUse();
    }

    public function toggle(User $user, CtaBlock $block): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && ! $this->isTrashed($block);
    }

    /**
     * The "used in N places" popover (`admin.website.cta-blocks.usage`).
     */
    public function usage(User $user, CtaBlock $block): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * Refused while any section still points at the block (§4.1, §6.13).
     */
    public function delete(User $user, CtaBlock $block): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($block)
            && ! $block->isInUse();
    }

    public function restore(User $user, CtaBlock $block): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($block);
    }

    /**
     * INV-14: never hard-deleted from the UI.
     */
    public function forceDelete(User $user, CtaBlock $block): bool
    {
        return false;
    }

    public function viewLogs(User $user): bool
    {
        return $this->allows($user, Ability::ViewLogs);
    }
}
