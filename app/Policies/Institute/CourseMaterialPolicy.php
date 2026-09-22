<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\CourseMaterial;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may share, re-aim or read a material (phase-19-23 §9, requirement §79).
 *
 * **`assign` is a separate right from `edit`, and that is the point of the module having it.** Deciding
 * *who receives* a file is a different act from correcting its title: a coordinator may be trusted to
 * tidy a library without being trusted to push a handout at another batch. Collapsing the two would
 * make the narrower grant impossible to express.
 *
 * **`download` is separate again**, because reading the index is not the same as taking the bytes. A
 * reviewer auditing what was shared needs the list and has no business with the files.
 *
 * **Nothing here decides whether a *student* may read a material.** That is INV-19-3 and it lives in
 * `MaterialAccessService::grantFor()`, which resolves the targets against live enrollment every time.
 * A policy cannot answer it — the question is about an audience, not about a permission — and a second
 * implementation here would be one that drifts.
 */
final class CourseMaterialPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'course_materials';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($material)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    /** Replacing the bytes. Deliberately `upload` and not `edit` — it is a different kind of change. */
    public function upload(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::Upload)
            && ! $this->isTrashed($material)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    /**
     * Staff taking a copy. A student's download runs INV-19-3 instead, and never reaches this.
     */
    public function download(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::Download)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    /** Setting the audience — course, batch or student. */
    public function assign(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && ! $this->isTrashed($material)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    /** Publish, unpublish, archive, restore. */
    public function changeStatus(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($material)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    /**
     * A soft delete. The bytes stay on disk (§2.3) — the row is hidden, not the file, so a material
     * removed by mistake is restored whole rather than re-uploaded from somebody's laptop.
     */
    public function delete(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($material)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    public function restore(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore)
            && $this->isTrashed($material)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    /**
     * The engagement report — who opened what, and who never did.
     *
     * It names individual students, so it is `view_reports` rather than `view`: seeing that a material
     * exists and seeing which of twenty students ignored it are different amounts of access.
     */
    public function viewReports(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewLogs(User $user, CourseMaterial $material): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($material));
    }

    private function branchOf(CourseMaterial $material): ?int
    {
        $branchId = $material->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
