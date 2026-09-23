<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Models\Support\Meeting;
use App\Services\Support\MeetingService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting changed in a way its participants need to hear about (§6.17 `update()`, §10.3
 * `meeting.updated`).
 *
 * **`$materialChanges` is why this event exists rather than a blanket "it was edited".** Correcting a
 * typo in the title should not reset twelve people's acceptances and post twelve notifications;
 * moving the meeting an hour later should do exactly that. The service decides which it was and
 * fires this only for the second kind, naming the fields — so the notification can say *what*
 * changed instead of "this meeting was updated".
 *
 * @see MeetingService::MATERIAL
 */
final class MeetingUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $materialChanges  the changed fields that made this worth sending
     */
    public function __construct(
        public readonly Meeting $meeting,
        public readonly array $materialChanges,
        public readonly ?int $actorId = null,
    ) {}
}
