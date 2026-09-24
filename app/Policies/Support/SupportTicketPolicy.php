<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Enums\Ability;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Policies\Support\Concerns\ChecksSupportPermissions;
use App\Support\ClientContext;

/**
 * Who may see, answer, move and close a ticket (phase-19-23 §9.4, requirement §93).
 *
 * **`view` has two completely different answers and both are here.** A holder of
 * `support_tickets.view_any` sees the queue, branch-scoped. Anybody else — including staff who hold
 * only `support_tickets.view` — sees the tickets they are *in*: raised, assigned, or (for a client)
 * belonging to their company. Writing that as one condition is what produces a policy where a
 * support agent can read a client's private ticket because the client clause happened to be checked
 * first.
 *
 * **A client's own ticket is not automatically their colleague's.** `is_private_to_creator` narrows
 * it to the person who raised it, which is phase-05 §12 Q3's shape: a company shares an account,
 * not a diary.
 *
 * **Nothing here can delete a ticket, because nothing can** (INV-22-1). `SupportTicket`'s `deleting`
 * hook throws unconditionally, below the gate, so `delete()` returning false is the polite half of
 * a refusal the model makes absolute.
 */
final class SupportTicketPolicy
{
    use ChecksSupportPermissions;

    public const MODULE = 'support_tickets';

    /**
     * May this person open a ticket list at all?
     *
     * **A portal user holds neither `support_tickets.view_any` nor `.view`** — their access runs
     * through `{panel}_portal.support_tickets`, exactly as `create()` already allowed for. Leaving
     * them out here meant every portal's ticket list answered 403 while the *create* form and the
     * *detail* page beside it both worked, which is the kind of gap a permission table cannot show
     * you and a rendered screen can.
     *
     * What each of them then *sees* is decided by the query, not by this: §9.4 gives `view_any` the
     * queue and everybody else their own rows.
     */
    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View)
            || $this->onAPortal($user);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        if ($this->holds($user, self::MODULE, Ability::ViewAny)) {
            return $this->sharesBranch($user, $this->idOf($ticket->getAttribute('branch_id')));
        }

        return $this->isInvolved($user, $ticket);
    }

    public function create(User $user): bool
    {
        // Every panel may raise one; which desks accept them is `TicketService::assertMayRaise()`,
        // because that answer depends on the department and a policy is handed no department.
        return $this->holds($user, self::MODULE, Ability::Create) || $this->onAPortal($user);
    }

    /**
     * Replying is `view` plus being involved, not `edit`.
     *
     * A client may never `edit` a ticket and must always be able to answer one; making the reply
     * composer depend on `edit` is how a portal ends up read-only by accident.
     */
    public function reply(User $user, SupportTicket $ticket): bool
    {
        if (! $this->view($user, $ticket)) {
            return false;
        }

        // A closed ticket takes no further reply from the requester — reopening is the route, and
        // §2.28.7 gives it a window. Staff may always add a note.
        return $ticket->status->requesterCanReply() || $this->isStaff($user);
    }

    /** An internal note is staff-only, always, whatever else somebody holds. */
    public function addInternalNote(User $user, SupportTicket $ticket): bool
    {
        return $this->isStaff($user) && $this->view($user, $ticket);
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($ticket)
            && $this->sharesBranch($user, $this->idOf($ticket->getAttribute('branch_id')));
    }

    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $this->holds($user, self::MODULE, Ability::Assign)
            && $this->sharesBranch($user, $this->idOf($ticket->getAttribute('branch_id')));
    }

    /**
     * Moving the status.
     *
     * **A requester may reopen their own ticket and do nothing else.** That is the one status move
     * §2.28.7 gives a portal, and it is the reason this is not a flat `change_status` check.
     */
    public function changeStatus(User $user, SupportTicket $ticket): bool
    {
        if ($this->holds($user, self::MODULE, Ability::ChangeStatus)) {
            return $this->sharesBranch($user, $this->idOf($ticket->getAttribute('branch_id')));
        }

        return $this->isRequester($user, $ticket) && $ticket->status->isResolvedOrClosed();
    }

    public function changePriority(User $user, SupportTicket $ticket): bool
    {
        // §12.2 Q6: a requester who could declare "urgent" would, every time, and within a month
        // the word would mean nothing.
        return $this->isStaff($user) && $this->changeStatus($user, $ticket);
    }

    public function changeDepartment(User $user, SupportTicket $ticket): bool
    {
        return $this->isStaff($user) && $this->holds($user, self::MODULE, Ability::Edit);
    }

    /**
     * Nothing may delete a ticket (INV-22-1).
     *
     * Stated as an explicit false rather than omitted, so a screen never renders the button and a
     * reader never has to check whether the omission was deliberate. The model refuses it anyway.
     */
    public function delete(User $user, SupportTicket $ticket): bool
    {
        return false;
    }

    public function forceDelete(User $user, SupportTicket $ticket): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export)
            && $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    // ===============================================================================================

    private function isStaff(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    /**
     * Does this person reach tickets through a portal rather than through the module?
     *
     * Built from the panel list rather than written out, so the next panel added does not need this
     * method edited — and so `viewAny()` and `create()` cannot come to disagree about who counts,
     * which is what happened when they were two separate lists.
     */
    private function onAPortal(User $user): bool
    {
        foreach (['client', 'student', 'teacher', 'collaborator'] as $panel) {
            if ($user->can($panel.'_portal.support_tickets')) {
                return true;
            }
        }

        return false;
    }

    private function isRequester(User $user, SupportTicket $ticket): bool
    {
        return (int) $ticket->getAttribute('user_id') === (int) $user->getKey();
    }

    /**
     * §9.4's non-`view_any` clause, in full.
     *
     * The client branch resolves through `ClientContext` rather than reading a posted or session
     * value, and it is checked **last**: a staff member who also happens to be a portal contact
     * should be reached by the staff clauses first, and a client whose ticket is private to its
     * creator must not be let in by the company clause.
     */
    private function isInvolved(User $user, SupportTicket $ticket): bool
    {
        $id = (int) $user->getKey();

        if ($this->isRequester($user, $ticket)) {
            return true;
        }

        if (! $this->holds($user, self::MODULE, Ability::View)) {
            return $this->isClientColleague($user, $ticket);
        }

        // The [D-P5-8] shape: assigned to me, raised by me, or created by me.
        return (int) $ticket->getAttribute('assigned_to') === $id
            || (int) $ticket->getAttribute('created_by') === $id
            || $this->isClientColleague($user, $ticket);
    }

    private function isClientColleague(User $user, SupportTicket $ticket): bool
    {
        $clientId = $this->idOf($ticket->getAttribute('client_id'));

        if ($clientId === null || ! $user->can('client_portal.support_tickets')) {
            return false;
        }

        if ((bool) $ticket->getAttribute('is_private_to_creator')) {
            return false;
        }

        $context = app(ClientContext::class);

        return $context->has() && $context->clientId() === $clientId;
    }
}
