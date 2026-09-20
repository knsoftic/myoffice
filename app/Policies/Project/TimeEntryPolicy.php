<?php

declare(strict_types=1);

namespace App\Policies\Project;

use App\Enums\Ability;
use App\Enums\TimeEntrySource;
use App\Models\Project\TimeEntry;
use App\Models\User;
use App\Policies\Project\Concerns\ChecksProjectPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and correct logged time (phase-06 §4.2, §9's "Time entries" row).
 *
 * **`view` is "my time", `view_any` is "everyone's"** — the same split §4.2 gives the module. Without
 * `view_any` the entry has to be the user's own, and anything else answers **404** rather than 403, so a
 * colleague's hours cannot be probed by id (INV-P15).
 *
 * **A timer entry is never editable** (§6.1). It is evidence of when work happened, and correcting it is a
 * discard with a reason plus a fresh manual entry — the same "void and re-enter" discipline the finance
 * spine applies to a mis-keyed receipt. {@see update()} therefore refuses a `timer` entry outright, for
 * everybody, whatever they hold.
 *
 * Editing **somebody else's** manual entry needs `time_tracking.edit` *and* `view_any` together, and the
 * service logs old and new values with the reason.
 */
final class TimeEntryPolicy
{
    use ChecksProjectPermissions;

    public const MODULE = 'time_tracking';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            || $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, TimeEntry $entry): bool|Response
    {
        if ($this->holds($user, self::MODULE, Ability::ViewAny)) {
            return $this->seesProject($user, $entry->project) ? true : Response::denyAsNotFound();
        }

        if (! $this->holds($user, self::MODULE, Ability::View)) {
            return false;
        }

        return $this->isOwn($user, $entry) ? true : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Correcting a manual entry. A timer entry is refused for everyone — discard it instead.
     */
    public function update(User $user, TimeEntry $entry): bool|Response
    {
        if ($this->isTrashed($entry) || $entry->source !== TimeEntrySource::Manual) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::Edit)) {
            return false;
        }

        if ($this->isOwn($user, $entry)) {
            return true;
        }

        if (! $this->holds($user, self::MODULE, Ability::ViewAny)) {
            return Response::denyAsNotFound();
        }

        return $this->seesProject($user, $entry->project) ? true : Response::denyAsNotFound();
    }

    /**
     * Discard — a soft delete with a mandatory reason, never a hard delete (§6.1).
     */
    public function delete(User $user, TimeEntry $entry): bool|Response
    {
        if ($this->isTrashed($entry)) {
            return false;
        }

        if (! $this->holds($user, self::MODULE, Ability::Delete)) {
            return false;
        }

        if ($this->isOwn($user, $entry)) {
            return true;
        }

        if (! $this->holds($user, self::MODULE, Ability::ViewAny)) {
            return Response::denyAsNotFound();
        }

        return $this->seesProject($user, $entry->project) ? true : Response::denyAsNotFound();
    }

    /**
     * Starting, pausing and stopping a timer is `create` — the worker is logging their own time.
     */
    public function runTimer(User $user, TimeEntry $entry): bool|Response
    {
        if (! $this->holds($user, self::MODULE, Ability::Create)) {
            return false;
        }

        return $this->isOwn($user, $entry) ? true : Response::denyAsNotFound();
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewReports);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    private function isOwn(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id !== null && (int) $entry->user_id === (int) $user->getKey();
    }
}
