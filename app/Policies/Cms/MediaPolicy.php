<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\MediaAsset;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may use the shared CMS image library (phase-03 §2.13, §4.1, §7.5, decision D24):
 * `website_media` = `READ` + `create` + `edit` + `delete` + `FILES` + `LOGS`.
 *
 * The class name is the contract's (`MediaPolicy`, §2.13), not Laravel's `{Model}Policy` convention, so
 * it must be registered explicitly for `MediaAsset`.
 *
 *   · Uploading is `website_media.upload` (the route of §7.5); `create` resolves to the same rule so a
 *     Digital Marketer holding `view_any/view/upload` (§9) can add images through either check.
 *   · `edit` is alt text, title and caption only, plus "regenerate derivatives" (§4.1).
 *   · FT-38: `delete` returns false while `usage_count > 0`. The count is a cache
 *     (`MediaService::recountUsage()`); the service re-checks live usage before deleting.
 *   · A delete is a soft delete and the files stay (INV-14, [D-W3-17]); nothing is hard-deleted here.
 */
final class MediaPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'website_media';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->upload($user);
    }

    /**
     * `admin.website.media.store` — `website_media.upload`.
     */
    public function upload(User $user): bool
    {
        return $this->allows($user, Ability::Upload);
    }

    /**
     * Alt text, title and caption only (§4.1).
     */
    public function update(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Ability::Edit)
            && ! $this->isTrashed($asset);
    }

    /**
     * Re-run the derivative pipeline. A video has no derivatives (`skipped`), so only images qualify.
     */
    public function regenerate(User $user, MediaAsset $asset): bool
    {
        return $this->update($user, $asset)
            && $asset->isImage();
    }

    /**
     * The "used in N places" list (`admin.website.media.usage`).
     */
    public function usage(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function download(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Ability::Download);
    }

    /**
     * FT-38 / §2.13: refused while anything still uses the asset.
     */
    public function delete(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Ability::Delete)
            && ! $this->isTrashed($asset)
            && ! $asset->isInUse();
    }

    public function restore(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Ability::Restore)
            && $this->isTrashed($asset);
    }

    /**
     * No binary is ever removed automatically or from the UI ([D-W3-17], INV-14).
     */
    public function forceDelete(User $user, MediaAsset $asset): bool
    {
        return false;
    }

    public function viewLogs(User $user): bool
    {
        return $this->allows($user, Ability::ViewLogs);
    }
}
