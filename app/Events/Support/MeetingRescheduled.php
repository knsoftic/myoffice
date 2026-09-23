<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\Meeting;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting slipped, and a successor row carries the new date (§6.17 `reschedule()`).
 *
 * **Both rows travel together** because the notification has to say "moved from Tuesday to
 * Thursday", and neither row knows both dates on its own: the old one is `postponed` and holds the
 * old time, the new one holds the new time and points back with `rescheduled_from_id`.
 */
final class MeetingRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Meeting $original,
        public readonly Meeting $replacement,
        public readonly string $reason,
        public readonly ?int $actorId = null,
    ) {}
}
