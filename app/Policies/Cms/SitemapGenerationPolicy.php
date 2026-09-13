<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\SitemapGeneration;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Sitemap build history (phase-03 §2.14, §4.1, §7.5). No module of its own: the history is read under
 * `seo.view` (the `admin.website.seo.sitemap.history` route) and a new build is `seo.edit`
 * (`admin.website.seo.sitemap.regenerate`).
 *
 * A build row is a write-once log line with no `deleted_at` (D19): `SitemapGenerator::regenerate()` is
 * its only writer, so update / delete are refused for everyone this policy sees.
 */
final class SitemapGenerationPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'seo';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::View);
    }

    public function view(User $user, SitemapGeneration $generation): bool
    {
        return $this->allows($user, Ability::View);
    }

    /**
     * Trigger a rebuild — the row itself is written by the generator.
     */
    public function create(User $user): bool
    {
        return $this->allows($user, Ability::Edit);
    }

    public function update(User $user, SitemapGeneration $generation): bool
    {
        return false;
    }

    public function delete(User $user, SitemapGeneration $generation): bool
    {
        return false;
    }

    public function restore(User $user, SitemapGeneration $generation): bool
    {
        return false;
    }

    public function forceDelete(User $user, SitemapGeneration $generation): bool
    {
        return false;
    }
}
