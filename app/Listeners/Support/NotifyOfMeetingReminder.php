<?php

declare(strict_types=1);

namespace App\Listeners\Support;

use App\DataObjects\Support\AudienceInput;
use App\Events\Support\MeetingReminderDue;
use App\Services\Support\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Your meeting starts shortly" — to everybody who has not declined (§10.3 `meeting.reminder`).
 *
 * **A declined participant is not reminded.** They said they were not coming; reminding them is the
 * system arguing with them.
 *
 * **The organiser *is* reminded**, unlike the invitation. They did not need telling that a meeting
 * they arranged exists; they do need telling that it starts in ten minutes, which is the one thing
 * a diary is for.
 *
 * `reminder_sent_at` was stamped on the meeting before this fired, so nothing here has to guard
 * against a second send — the sweep that produced this event cannot produce it twice.
 */
final class NotifyOfMeetingReminder
{
    use BuildsTicketLinks;

    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(MeetingReminderDue $event): void
    {
        $meeting = $event->meeting;

        $recipients = DB::table('meeting_participants')
            ->where('meeting_id', $meeting->getKey())
            ->whereNotNull('user_id')
            ->where('response', '!=', 'declined')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($recipients === []) {
            return;
        }

        $at = $meeting->getAttribute('scheduled_at');

        $result = $this->notifications->dispatch('meeting.reminder', AudienceInput::of($recipients), [
            'title' => 'Starting soon: '.$meeting->getAttribute('title'),
            'body' => $at === null
                ? 'A meeting you are in is about to start.'
                : Carbon::parse($at)->format('D j M Y, H:i').' · '.$meeting->getAttribute('duration_minutes').' minutes',
            'url' => $this->meetingUrl($meeting),
            'meeting_id' => (int) $meeting->getKey(),
        ]);

        if ($result->recipientIds === []) {
            return;
        }

        // Per participant, so a reminder that reached ten of twelve people says so — the meeting's
        // own `reminder_sent_at` records that the sweep ran, and this records who heard.
        DB::table('meeting_participants')
            ->where('meeting_id', $meeting->getKey())
            ->whereIn('user_id', $result->recipientIds)
            ->update(['reminder_sent_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }
}
