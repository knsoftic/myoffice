<?php

declare(strict_types=1);

namespace App\Policies\Crm;

use App\Enums\Ability;
use App\Models\Crm\LeadImport;
use App\Models\User;
use App\Policies\Crm\Concerns\ChecksCrmPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may stage, run and review a CSV import (phase-05 §7, §9.1, §9.3).
 *
 * Every action needs `leads.import`. A batch — its rows, its progress and its error report included — is visible
 * to the user who created it, or to a `leads.view_any` holder; anyone else holding `leads.import` gets **404**.
 * Whether a batch may run or be cancelled in its current state (`LeadImportStatus::isRunnable()` /
 * `isCancellable()`) is the service's rule.
 */
final class LeadImportPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool
    {
        return $this->holds($user, LeadPolicy::MODULE, Ability::Import);
    }

    public function view(User $user, LeadImport $import): Response|bool
    {
        if (! $this->holds($user, LeadPolicy::MODULE, Ability::Import)) {
            return false;
        }

        return $import->isVisibleTo($user) ? true : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->holds($user, LeadPolicy::MODULE, Ability::Import);
    }

    /**
     * Mapping, validation and the run itself.
     */
    public function run(User $user, LeadImport $import): Response|bool
    {
        return $this->liveBatch($user, $import);
    }

    public function cancel(User $user, LeadImport $import): Response|bool
    {
        return $this->liveBatch($user, $import);
    }

    public function downloadErrors(User $user, LeadImport $import): Response|bool
    {
        return $this->view($user, $import);
    }

    private function liveBatch(User $user, LeadImport $import): Response|bool
    {
        $visible = $this->view($user, $import);

        if ($visible !== true) {
            return $visible;
        }

        return ! $this->isTrashed($import);
    }
}
