<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Support\Format;
use Carbon\CarbonInterface;

/**
 * A lead already has its one `pending` follow-up (phase-05 §6.3 `schedule()`, test 26). `uq_lfu_open` rejected
 * the insert; this is that 1062 as a domain error naming the existing row's date and owner — a 422, never a 500.
 */
final class FollowUpAlreadyOpenException extends CrmRuleException
{
    public ?int $existingFollowUpId = null;

    public static function for(?int $existingId, ?CarbonInterface $scheduledAt, ?string $ownerName): self
    {
        $when = $scheduledAt === null ? 'an earlier date' : Format::dateTime($scheduledAt);

        $exception = self::refuse('follow_up', sprintf(
            'This lead already has an open follow-up on %s%s. Complete, reschedule or cancel it first.',
            $when,
            $ownerName === null || $ownerName === '' ? '' : ' for '.$ownerName,
        ));

        $exception->existingFollowUpId = $existingId;

        return $exception;
    }
}
