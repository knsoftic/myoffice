<?php

declare(strict_types=1);

namespace App\Policies\Support;

use App\Enums\Ability;
use App\Enums\MeetingStatus;
use App\Models\Support\Meeting;
use App\Models\User;
use App\Policies\Support\Concerns\ChecksSupportPermissions;
use App\Support\Institute\TeacherScope;
use Illuminate\Support\Facades\DB;

/**
 * Who may see, run and answer a meeting (phase-19-23 §9.4, §6.17, requirement §95).
 *
 * **Being in the room is the main right, and it is not a permission.** A client invited to a
 * kick-off holds no `meetings.*` permission at all — their access runs through
 * `client_portal.meetings` — so every check here starts with "are they on the guest list" and only
 * then asks what the permission adds. A policy built the other way round locks the people the
 * meeting is *for* out of it.
 *
 * **`view` is live, never snapshotted** (§6.17). Somebody removed from the guest list loses access
 * the moment the row goes, which is why this reads `meeting_participants` rather than anything
 * recorded when they were invited.
 *
 * **Notes are the exception to "a participant may see everything about their meeting".** A client
 * reads the minutes only once the meeting is `completed`, and a portal user never writes them
 * (PH22-38) — the write half is enforced in `MeetingService::saveNotes()` as well, because
 * `Gate::before` walks a Super Admin past this file (D124, D140).
 */
final class MeetingPolicy
{
    use ChecksSupportPermissions;

    public const MODULE = 'meetings';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View)
            || $this->onAPortal($user);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if ($this->holds($user, self::MODULE, Ability::ViewAny)) {
            return $this->sharesBranch($user, $this->idOf($meeting->getAttribute('branch_id')));
        }

        if ($this->isInTheRoom($user, $meeting)) {
            return true;
        }

        // A teacher sees a meeting about one of their own batches whether or not somebody
        // remembered to invite them: a meeting about a batch is a meeting its teacher is in.
        return $this->teachesTheBatch($user, $meeting);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /** Only a live meeting is editable; the route to a new time is `reschedule`. */
    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Scheduled
            && ! $this->isTrashed($meeting)
            && ($this->isOrganizer($user, $meeting) || $this->holds($user, self::MODULE, Ability::Edit))
            && $this->sharesBranch($user, $this->idOf($meeting->getAttribute('branch_id')));
    }

    public function reschedule(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function cancel(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    /** Inviting and removing people. */
    public function assign(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Scheduled
            && ($this->isOrganizer($user, $meeting) || $this->holds($user, self::MODULE, Ability::Assign));
    }

    /**
     * Accepting or declining — **participants only**, whatever permissions anybody holds.
     *
     * An organiser answering their own invitation is not offered the buttons: `create()` seats them
     * as `accepted` because they chose the time.
     */
    public function respond(User $user, Meeting $meeting): bool
    {
        return $meeting->status === MeetingStatus::Scheduled
            && ! $this->isOrganizer($user, $meeting)
            && $this->isInTheRoom($user, $meeting);
    }

    public function markAttendance(User $user, Meeting $meeting): bool
    {
        return ! in_array($meeting->status, [MeetingStatus::Cancelled, MeetingStatus::Postponed], true)
            && ($this->isOrganizer($user, $meeting) || $this->holds($user, self::MODULE, Ability::Edit));
    }

    /**
     * Reading the minutes.
     *
     * A portal participant sees them only on a `completed` meeting (§9.4): notes written during a
     * meeting that has not finished are working notes, and a client reading a half-finished
     * sentence about their own project is worse than waiting a day.
     */
    public function viewNotes(User $user, Meeting $meeting): bool
    {
        if (! $this->view($user, $meeting)) {
            return false;
        }

        if ($this->holds($user, self::MODULE, Ability::ViewAny) || $this->isOrganizer($user, $meeting)) {
            return true;
        }

        return $meeting->status === MeetingStatus::Completed;
    }

    /** Writing them. Staff only — `MeetingService::saveNotes()` says the same below the gate. */
    public function saveNotes(User $user, Meeting $meeting): bool
    {
        return $this->markAttendance($user, $meeting)
            && ! $this->isTrashed($meeting);
    }

    public function downloadIcs(User $user, Meeting $meeting): bool
    {
        return (bool) setting('support.meeting_ics_enabled', true) && $this->view($user, $meeting);
    }

    /**
     * A cancelled or postponed meeting can be tidied away; a completed one is a record.
     *
     * Soft-delete only — `Meeting` uses `SoftDeletes`, so this hides a row from the diary and keeps
     * the attendance and the minutes behind it.
     */
    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $meeting->status->isTerminal()
            && $meeting->status !== MeetingStatus::Completed
            && ! $this->isTrashed($meeting);
    }

    public function restore(User $user, Meeting $meeting): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore) && $this->isTrashed($meeting);
    }

    public function forceDelete(User $user, Meeting $meeting): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export)
            && $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    // ===============================================================================================

    private function isOrganizer(User $user, Meeting $meeting): bool
    {
        return (int) $meeting->getAttribute('organizer_id') === (int) $user->getKey();
    }

    private function isInTheRoom(User $user, Meeting $meeting): bool
    {
        return $this->isOrganizer($user, $meeting) || $meeting->includes($user);
    }

    private function onAPortal(User $user): bool
    {
        foreach (['client', 'student', 'teacher', 'collaborator'] as $panel) {
            if ($user->can($panel.'_portal.meetings')) {
                return true;
            }
        }

        return false;
    }

    private function teachesTheBatch(User $user, Meeting $meeting): bool
    {
        $batchId = $this->idOf($meeting->getAttribute('batch_id'));

        if ($batchId === null) {
            return false;
        }

        $teacherId = DB::table('teachers')
            ->where('user_id', $user->getKey())
            ->whereNull('deleted_at')
            ->value('id');

        return $teacherId !== null && TeacherScope::owns((int) $teacherId, $batchId);
    }
}
