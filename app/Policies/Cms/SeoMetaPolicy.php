<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\SeoMeta;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may read and change per-target SEO (phase-03 §4.2, §7.5): `seo` = `READ` + `edit` + `export` +
 * `LOGS`.
 *
 * **No create / delete ability exists** (§4.2): a `seo_meta` row is an attribute of its target, created
 * implicitly on the first save — so creating it is `seo.edit` — and never deleted (decision D19, no
 * `deleted_at`; the model throws on delete). `seo.edit` also covers robots.txt content and "regenerate
 * sitemap" (§4.2). Per-target SEO is live on save (§2.15), which is why the SEO Expert can hold
 * `seo.edit` without any publish right (§9).
 */
final class SeoMetaPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'seo';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny);
    }

    public function view(User $user, SeoMeta $meta): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * The row is created implicitly by the first SEO save of a target (§4.2).
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    public function update(User $user, SeoMeta $meta): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    /**
     * Bulk robots / sitemap-inclusion change (`admin.website.seo.bulk-robots`). Class-level.
     */
    public function bulkUpdate(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    /**
     * robots.txt `custom` mode text (§6.5, §8.12). Class-level.
     */
    public function editRobots(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    /**
     * `admin.website.seo.sitemap.regenerate`. Class-level.
     */
    public function regenerateSitemap(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    /**
     * The SEO audit CSV (`admin.website.seo.export`). Class-level.
     */
    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function viewLogs(User $user): bool
    {
        return $this->allows($user, Ability::ViewLogs);
    }

    /**
     * Never: the row is 1:1 with its target and append-only by category (D19).
     */
    public function delete(User $user, SeoMeta $meta): bool
    {
        return false;
    }

    public function restore(User $user, SeoMeta $meta): bool
    {
        return false;
    }

    public function forceDelete(User $user, SeoMeta $meta): bool
    {
        return false;
    }
}
