<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\ExamResult;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may enter, check, publish or amend a mark (phase-19-23 §9, §4.2).
 *
 * **Five abilities, five different acts, and the split is the point.** `create` enters a sheet; `edit`
 * amends one with a reason; `approve` is the §2.28.4 second pair of eyes; `change_status` publishes and
 * withdraws; `print` produces the card. An institute that wants a marker who cannot publish, or a head
 * who checks but never enters, can say so — which is impossible while all five live under one `edit`.
 *
 * **`delete` and `forceDelete` return false — and that is not what protects a mark.** `Gate::before`
 * allows a Super Admin everything *before* a policy is consulted, so `can('delete', …)` is true for
 * them however this is written. The model's `deleting` hook is what actually holds the line, and it
 * refuses for everyone. These return false so every *other* role is refused at the cheapest layer, and
 * because an ability that is never registered cannot be granted by mistake. That distinction cost
 * Phase 19 two tests to learn (D124), and it is written down here so it does not cost a third.
 *
 * **A student reaches their own result through the portal, not here**, and another student's id is a
 * **404** rather than a 403.
 */
final class ExamResultPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'results';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, ExamResult $result): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($result));
    }

    /** Entering a sheet. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Amending a mark. The service adds what a policy cannot: a mandatory reason, the ceiling against
     * the row's own snapshot, and an activity row carrying old and new.
     */
    public function update(User $user, ExamResult $result): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && $this->sharesBranch($user, $this->branchOf($result));
    }

    /**
     * The second pair of eyes (§2.28.4).
     *
     * **This cannot express "not the person who entered it"**, because a policy sees one row and the
     * rule is about the whole sheet — a sheet entered by two people needs a third. `ExamResultService::verify()`
     * checks it per row, which is the only place that can.
     */
    public function approve(User $user, ExamResult $result): bool
    {
        return $this->holds($user, self::MODULE, Ability::Approve)
            && $this->sharesBranch($user, $this->branchOf($result));
    }

    public function reject(User $user, ExamResult $result): bool
    {
        return $this->approve($user, $result);
    }

    /** Publishing and withdrawing. */
    public function changeStatus(User $user, ExamResult $result): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && $this->sharesBranch($user, $this->branchOf($result));
    }

    /** The printable result card. */
    public function print(User $user, ExamResult $result): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print)
            && $this->sharesBranch($user, $this->branchOf($result));
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function import(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Import);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function viewLogs(User $user, ExamResult $result): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($result));
    }

    /**
     * Never — for every role this policy is actually consulted for. A Super Admin skips it entirely
     * and is stopped by the model instead. See the class note.
     */
    public function delete(User $user, ExamResult $result): bool
    {
        return false;
    }

    /** Never — same reason. */
    public function forceDelete(User $user, ExamResult $result): bool
    {
        return false;
    }

    /** Never — there is nothing to restore, because nothing is ever deleted. */
    public function restore(User $user, ExamResult $result): bool
    {
        return false;
    }

    /**
     * A result carries no branch of its own; it inherits the exam's, which was copied from the batch.
     * Reading it through the relation keeps one answer rather than a denormalised second.
     */
    private function branchOf(ExamResult $result): ?int
    {
        $branchId = $result->exam?->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
