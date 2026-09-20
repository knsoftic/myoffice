<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Models\Hr\EmployeeDocument;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and download an employee's documents (phase-07 §2.6, §9).
 *
 * **A line manager and an Accountant both get nothing here** (§9). A CV, a medical certificate and a
 * national ID card are not payroll and are not absence; the only people who reach them are HR, the
 * employee themselves, and whoever explicitly holds the ability.
 *
 * Bytes are always streamed from the **private** disk by a controller that re-runs this check (D21), and
 * every download writes an activity row naming the document, the actor and the IP **before** streaming.
 */
final class EmployeeDocumentPolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'employee_documents';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, EmployeeDocument $document): bool|Response
    {
        if ($this->isSelf($user, $document->employee)) {
            return true;
        }

        return $this->reaches($user, self::MODULE, Ability::View, $this->seesEmployee($user, $document->employee));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Upload);
    }

    public function download(User $user, EmployeeDocument $document): bool|Response
    {
        if ($this->isSelf($user, $document->employee)) {
            return $this->holds($user, 'employee_self_service', Ability::Download);
        }

        return $this->reaches($user, self::MODULE, Ability::Download, $this->seesEmployee($user, $document->employee));
    }

    public function changeStatus(User $user, EmployeeDocument $document): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::ChangeStatus, $this->seesEmployee($user, $document->employee));
    }

    public function delete(User $user, EmployeeDocument $document): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::Delete, $this->seesEmployee($user, $document->employee));
    }
}
