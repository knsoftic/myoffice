<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\Meeting;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting was called off (§6.17 `cancel()`, §10.3 `meeting.cancelled`).
 *
 * **The reason travels with the event and is not optional**, because the notification is the reason.
 * "Your 3pm is cancelled" sends somebody to ask why; "Your 3pm is cancelled — the client moved to
 * next week" does not. `chk_me_cancel` refuses the row without one, and this carries it forward.
 *
 * Every participant hears, including those who had declined: a declined invitation is still a
 * commitment somebody made a plan around.
 */
final class MeetingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $reason,
        public readonly ?int $actorId = null,
    ) {}
}
