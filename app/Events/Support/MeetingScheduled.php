<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\Meeting;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting was put in the diary (phase-19-23 §6.17 `create()`, §10.3 `meeting.invited`).
 *
 * Listener: the Phase 22 notification slice, which sends `meeting.invited` to every **internal**
 * participant. An external guest has no `users` row and therefore no in-app notification (PH22-37);
 * they are reached by the invitation email and the `.ics` attached to it.
 *
 * **`ShouldDispatchAfterCommit` is the point.** `create()` writes the meeting, its participants and
 * its counts in one transaction; a notification sent from inside it would name a meeting that a
 * later failure rolled back, and the recipient would click a link to a 404.
 */
final class MeetingScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly ?int $actorId = null,
    ) {}
}
