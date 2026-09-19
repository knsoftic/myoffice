<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Enums\Ability;
use App\Models\Cms\ContactInquiry;
use App\Models\User;
use App\Policies\Cms\Concerns\ChecksCmsPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may work the contact-inquiry queue (phase-04 §4, §9.1.2):
 * `contact_inquiries` = `READ` + `edit` + `delete` + `STATUS` + `ASSIGN` + `export` + `print` + `view_logs`.
 *
 * **Row scope (§9.1.2).** `contact_inquiries.view_any` reaches every row; anyone else only the rows
 * `assigned_to` it — the same rule as `ContactInquiry::scopeVisibleTo()`. The referral snapshot
 * (`collaborator_id`, `referral_code`, `referral_visit_id`) is never consulted: a snapshot grants nothing
 * (D37, §11 test 64).
 *
 * **Failure codes.** Missing the permission is a 403; holding it without the row is a **404**
 * (`Response::denyAsNotFound()`), so inquiry ids cannot be probed (resolutions §8 row 5).
 *
 * **Routing** (`Route now`, retry, `route-pending`) and spam flags are `contact_inquiries.change_status`
 * (§4, §12.2 Q1). **The technical / PII block** needs `contact_inquiries.view_logs` (F-12.4); without it
 * the columns are left out of the query by `ContactInquiry::scopeSelectVisibleColumns()`.
 */
final class ContactInquiryPolicy
{
    use ChecksCmsPermissions;

    private const MODULE = 'contact_inquiries';

    /**
     * The queue lists `visibleTo()`, so a reviewer holding only `view` sees exactly its assigned rows
     * (§11 test 55).
     */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewAny)
            || $this->allows($user, Ability::View);
    }

    public function view(User $user, ContactInquiry $inquiry): Response|bool
    {
        if (! $this->allows($user, Ability::View)) {
            return false;
        }

        return $this->reaches($user, $inquiry);
    }

    /**
     * Response notes (`admin.contact-inquiries.update`).
     */
    public function update(User $user, ContactInquiry $inquiry): Response|bool
    {
        if (! $this->allows($user, Ability::Edit) || $this->isTrashed($inquiry)) {
            return false;
        }

        return $this->reaches($user, $inquiry);
    }

    public function delete(User $user, ContactInquiry $inquiry): Response|bool
    {
        if (! $this->allows($user, Ability::Delete) || $this->isTrashed($inquiry)) {
            return false;
        }

        return $this->reaches($user, $inquiry);
    }

    public function restore(User $user, ContactInquiry $inquiry): Response|bool
    {
        if (! $this->allows($user, Ability::Restore) || ! $this->isTrashed($inquiry)) {
            return false;
        }

        return $this->reaches($user, $inquiry);
    }

    /**
     * The inquiry is the permanent record of a submission; nothing hard-deletes it from the UI.
     */
    public function forceDelete(User $user, ContactInquiry $inquiry): bool
    {
        return false;
    }

    /**
     * Mark read / in progress / responded / closed.
     */
    public function changeStatus(User $user, ContactInquiry $inquiry): Response|bool
    {
        if (! $this->allows($user, Ability::ChangeStatus) || $this->isTrashed($inquiry)) {
            return false;
        }

        return $this->reaches($user, $inquiry);
    }

    /**
     * `admin.contact-inquiries.route` — Route now / Retry routing (§4: routing changes `routing_status`).
     * An unavailable target is not a denial: the button is disabled and the router leaves it pending.
     */
    public function route(User $user, ContactInquiry $inquiry): Response|bool
    {
        return $this->changeStatus($user, $inquiry);
    }

    /**
     * `admin.contact-inquiries.route-pending` walks the whole backlog, including rows the user is not
     * assigned, so it needs the queue-wide reach as well as `change_status`.
     */
    public function routePending(User $user): bool
    {
        return $this->allows($user, Ability::ChangeStatus)
            && $this->allows($user, Ability::ViewAny);
    }

    public function markSpam(User $user, ContactInquiry $inquiry): Response|bool
    {
        return $this->changeStatus($user, $inquiry);
    }

    public function markNotSpam(User $user, ContactInquiry $inquiry): Response|bool
    {
        return $this->changeStatus($user, $inquiry);
    }

    /**
     * Set `assigned_to` — the basis of reviewer row scoping.
     */
    public function assign(User $user, ContactInquiry $inquiry): Response|bool
    {
        if (! $this->allows($user, Ability::Assign) || $this->isTrashed($inquiry)) {
            return false;
        }

        return $this->reaches($user, $inquiry);
    }

    /**
     * The technical / PII block (F-12.4): class-level without an inquiry (may the columns be selected at
     * all), row-level with one (may this metadata panel render).
     */
    public function viewLogs(User $user, ?ContactInquiry $inquiry = null): bool
    {
        if (! $this->allows($user, Ability::ViewLogs)) {
            return false;
        }

        return $inquiry === null || $this->reaches($user, $inquiry) === true;
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Ability::Export);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, Ability::Print);
    }

    /**
     * §9.1.2: `view_any`, or the row is assigned to the user. Nothing else — never the snapshot.
     */
    private function reaches(User $user, ContactInquiry $inquiry): Response|bool
    {
        if ($this->allows($user, Ability::ViewAny) || $inquiry->isAssignedTo($user)) {
            return true;
        }

        return Response::denyAsNotFound();
    }
}
