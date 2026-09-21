<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

/**
 * The batch is full (phase-14-17 §6.6, INV-I6).
 *
 * Thrown from inside the enrolment transaction, after a recount under a row lock — never from a
 * reading of `batches.current_students`, which is a cache with no authority (D48). The message names
 * the count and the ceiling, because "batch is full" with no numbers is the kind of refusal somebody
 * argues with rather than acts on.
 */
final class BatchCapacityExceeded extends CourseRuleException
{
    public static function batchIsFull(string $batch, int $active, int $capacity): self
    {
        return self::refuse('batch_id', sprintf(
            '%s is full: %d of %d seats are taken. Raise the capacity, open another batch, or enrol '
            .'with a reason if overbooking is permitted here.',
            $batch,
            $active,
            $capacity,
        ));
    }

    public static function roomIsFull(string $batch, int $active, int $capacity, string $room): self
    {
        return self::refuse('batch_id', sprintf(
            '%s already has %d students and %s seats %d. Somebody would be standing.',
            $batch,
            $active,
            $room,
            $capacity,
        ));
    }

    public static function overbookingIsOff(string $batch): self
    {
        return self::refuse('batch_id', sprintf(
            '%s is full, and overbooking is switched off for this institute. Nobody can exceed a '
            .'batch capacity until somebody turns it on in Settings.',
            $batch,
        ));
    }

    public static function overbookingNeedsAReason(): self
    {
        return self::reasonRequired('overbook_reason',
            'Going over capacity takes a reason. It goes on the enrolment, and it is the first thing '
            .'anybody asks when the room turns out to be short of chairs.');
    }
}
