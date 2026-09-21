<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\Batch;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may open a batch, and who may put a student in one (§70, phase-14-17 §4.2, §9).
 *
 * **`batches.assign` is the enrolment ability**, and it is deliberately separate from `edit`. The
 * front desk seats students all day and never opens a batch; the coordinator opens batches and rarely
 * seats anybody. A single `edit` covering both would hand each of them the other's job.
 *
 * **A terminal batch is read-only.** Completed and cancelled are where a batch ends: its roster, its
 * register and its reports are history, and an edit afterwards changes a number somebody has read.
 */
final class BatchPolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'batches';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $batch->branch_id === null ? null : (int) $batch->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($batch)
            && ! $batch->status->isTerminal()
            && $this->sharesBranch($user, $batch->branch_id === null ? null : (int) $batch->branch_id);
    }

    public function changeStatus(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($batch)
            && ! $batch->status->isTerminal()
            && $this->sharesBranch($user, $batch->branch_id === null ? null : (int) $batch->branch_id);
    }

    /** Enrol, transfer, drop — every move of a seat. */
    public function assign(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && ! $this->isTrashed($batch)
            && ! $batch->status->isTerminal()
            && $this->sharesBranch($user, $batch->branch_id === null ? null : (int) $batch->branch_id);
    }

    public function print(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print) && $this->view($user, $batch);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    /**
     * Only a batch nobody was ever enrolled in. Once there is a roster, the way out is `cancelled`,
     * which keeps the record of what was opened and why it was not run.
     */
    public function delete(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($batch)
            && $batch->enrollments()->doesntExist()
            && $this->sharesBranch($user, $batch->branch_id === null ? null : (int) $batch->branch_id);
    }

    public function restore(User $user, Batch $batch): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($batch);
    }
}
