<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\CmsRevision;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;

/**
 * Who may read and revert content revisions (phase-03 §2.14, §4.1, §7.1, §7.3).
 *
 * `cms_revisions` has **no module of its own** (§4.1): a revision is read under the revisionable's
 * `view_logs` (`website_sections.view_logs` or `pages.view_logs`) and reverted under its
 * `change_status`. The module comes from {@see CmsRevision::moduleSlug()}, which uses Phase 1's
 * model => module resolver, so a later draft/publish entity needs no change here. A revision whose
 * owner cannot be resolved is refused (fail closed).
 *
 * A revision is **write-once and never deleted** (D19): `RevisionRecorder` is its only writer, so
 * create / update / delete are refused for everyone this policy sees. Route handlers must still assert
 * the revision belongs to the bound target (`RevisionRecorder::assertBelongsTo()`, integration M-19).
 */
final class CmsRevisionPolicy
{
    use ChecksCmsPermissions;

    /** Default module for the class-level trait helper; instance checks use the revisionable's module. */
    private const MODULE = 'website_sections';

    /** The Phase 3 revisionable modules, for the class-level `viewAny` question. */
    private const REVISIONABLE_MODULES = ['website_sections', 'pages'];

    /**
     * Class-level: may the user read revision history anywhere in the CMS?
     */
    public function viewAny(User $user): bool
    {
        foreach (self::REVISIONABLE_MODULES as $module) {
            if ($this->allows($user, Ability::ViewLogs, $module)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, CmsRevision $revision): bool
    {
        $module = $revision->moduleSlug();

        return $module !== null && $this->allows($user, Ability::ViewLogs, $module);
    }

    /**
     * Restore the revision into the **draft** of its target (FT-11) — a status act.
     */
    public function revert(User $user, CmsRevision $revision): bool
    {
        $module = $revision->moduleSlug();

        return $module !== null && $this->allows($user, Ability::ChangeStatus, $module);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CmsRevision $revision): bool
    {
        return false;
    }

    public function delete(User $user, CmsRevision $revision): bool
    {
        return false;
    }

    public function restore(User $user, CmsRevision $revision): bool
    {
        return false;
    }

    public function forceDelete(User $user, CmsRevision $revision): bool
    {
        return false;
    }
}
