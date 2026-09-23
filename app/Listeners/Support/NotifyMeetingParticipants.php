<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Events\Support\MeetingCancelled;
use App\Events\Support\MeetingRescheduled;
use App\Events\Support\MeetingScheduled;
use App\Events\Support\MeetingUpdated;
use App\Models\Support\Meeting;
use App\Services\Support\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The diary tells the room (§10.3 `meeting.invited` / `.updated` / `.cancelled`).
 *
 * **One listener, four events**, because the audience question is the same every time — who is on
 * the guest list — and only the wording changes. Four listeners would be four places to forget that
 * an external guest has no `users` row.
 *
 * **An external guest gets no in-app notification, by construction** (PH22-37): they have no
 * account, so there is nothing to put a bell row on. `NotificationService` counts them under
 * `no_account` and moves on; they are reached by the invitation email and the `.ics` with it.
 *
 * **The organiser is not told about their own meeting.** They arranged it, moved it or cancelled
 * it; a notification would be the system reporting their own action back to them.
 *
 * **`notified_at` is stamped for the people who were actually told**, so the reminder sweep can
 * tell an invitation that went out from one that never did.
 */
final class NotifyMeetingParticipants
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function invited(MeetingScheduled $event): void
    {
        $this->send($event->meeting, 'meeting.invited', [
            'title' => 'You are invited: '.$event->meeting->getAttribute('title'),
            'body' => $this->when($event->meeting),
        ], $event->actorId);
    }

    public function updated(MeetingUpdated $event): void
    {
        $this->send($event->meeting, 'meeting.updated', [
            'title' => 'A meeting has changed: '.$event->meeting->getAttribute('title'),
            'body' => $this->when($event->meeting).' — your answer has been reset, so please accept or decline again.',
            'changes' => $event->materialChanges,
        ], $event->actorId);
    }

    public function rescheduled(MeetingRescheduled $event): void
    {
        // The successor carries the guest list, so it is the row people need to open — but the
        // message has to name the old time as well, or "it moved" answers nothing.
        $this->send($event->replacement, 'meeting.updated', [
            'title' => 'A meeting has moved: '.$event->replacement->getAttribute('title'),
            'body' => sprintf(
                'Moved from %s to %s. %s',
                $this->stamp($event->original),
                $this->stamp($event->replacement),
                $event->reason,
            ),
            'previous_meeting_id' => (int) $event->original->getKey(),
        ], $event->actorId);
    }

    public function cancelled(MeetingCancelled $event): void
    {
        $this->send($event->meeting, 'meeting.cancelled', [
            'title' => 'Cancelled: '.$event->meeting->getAttribute('title'),
            // The reason IS the message. "Your 3pm is off" sends everybody to ask why.
            'body' => $event->reason,
        ], $event->actorId, includeDeclined: true);
    }

    // ===============================================================================================

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(Meeting $meeting, string $eventKey, array $payload, ?int $actorId, bool $includeDeclined = true): void
    {
        $organizerId = (int) $meeting->getAttribute('organizer_id');

        $recipients = DB::table('meeting_participants')
            ->where('meeting_id', $meeting->getKey())
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $organizerId)
            ->when(! $includeDeclined, static fn ($q) => $q->where('response', '!=', 'declined'))
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($recipients === []) {
            return;
        }

        $result = $this->notifications->dispatch($eventKey, AudienceInput::of($recipients), array_merge($payload, [
            'url' => $this->meetingUrl($meeting),
            'meeting_id' => (int) $meeting->getKey(),
            'scheduled_at' => $this->stamp($meeting),
        ]), null);

        if ($result->recipientIds === []) {
            return;
        }

        DB::table('meeting_participants')
            ->where('meeting_id', $meeting->getKey())
            ->whereIn('user_id', $result->recipientIds)
            ->update(['notified_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    private function when(Meeting $meeting): string
    {
        return $this->stamp($meeting).' ('.$meeting->getAttribute('duration_minutes').' minutes)';
    }

    private function stamp(Meeting $meeting): string
    {
        $at = $meeting->getAttribute('scheduled_at');

        return $at === null ? 'a time yet to be set' : Carbon::parse($at)->format('D j M Y, H:i');
    }
}
