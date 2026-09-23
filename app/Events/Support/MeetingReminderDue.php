<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\Meeting;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting is about to start (§10.5 `meetings:send-reminders`, §10.3 `meeting.reminder`).
 *
 * **Fired at most once per meeting, ever.** `reminder_sent_at` is stamped inside the transaction
 * that selects the row and this is dispatched after that transaction commits — so a crash between
 * the two loses one reminder and never sends two, which is the right way round. The sweep runs every
 * five minutes and a second run selects nothing.
 *
 * **Everybody who has not declined.** Somebody who said they were not coming does not need telling
 * that the thing they are not coming to is starting.
 */
final class MeetingReminderDue implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
    ) {}
}
