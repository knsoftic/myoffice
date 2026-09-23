<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Enums\Ability;
use App\Models\Support\TicketDepartment;
use App\Models\User;
use App\Policies\Support\Concerns\ChecksSupportPermissions;
use Illuminate\Support\Facades\DB;

/**
 * Who may configure the support desks (phase-19-23 §6.16, §4.2).
 *
 * **A department is configuration, not a record**, so unlike a ticket it can be edited and retired
 * freely. What it cannot be is *deleted while it holds tickets* — those tickets would point at
 * nothing, and "which desk was this raised with" is the first question anybody asks about an old
 * ticket. Retiring it (`is_active = false`) takes it off the raise-a-ticket form and leaves the
 * history intact, which is what somebody reaching for delete almost always means.
 *
 * **`default_guard` makes one default per panel a database fact**, so this policy does not have to
 * police it: an attempt to set a second one is refused by the unique index rather than by a check
 * that could be forgotten on one of the two screens that write it.
 */
final class TicketDepartmentPolicy
{
    use ChecksSupportPermissions;

    public const MODULE = 'ticket_departments';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, TicketDepartment $department): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, TicketDepartment $department): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit) && ! $this->isTrashed($department);
    }

    /** Retiring a desk: the honest alternative to deleting one that has history. */
    public function changeStatus(User $user, TicketDepartment $department): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus) && ! $this->isTrashed($department);
    }

    /**
     * Deleting one — only while it has never been used.
     *
     * Checked here as well as wherever the service checks it, because this is what decides whether
     * the button is rendered at all, and a delete button that always fails is worse than none.
     */
    public function delete(User $user, TicketDepartment $department): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($department)
            && ! $this->hasTickets($department);
    }

    public function restore(User $user, TicketDepartment $department): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($department);
    }

    public function forceDelete(User $user, TicketDepartment $department): bool
    {
        return false;
    }

    private function hasTickets(TicketDepartment $department): bool
    {
        // Trashed tickets count: a soft-deleted ticket is still a row pointing at this desk, and
        // the foreign key does not care that it is hidden.
        return DB::table('support_tickets')
            ->where('ticket_department_id', $department->getKey())
            ->exists();
    }
}
