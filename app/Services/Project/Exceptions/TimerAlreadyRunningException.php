<?php

declare(strict_types=1);

namespace App\Services\Project\Exceptions;

/**
 * The worker already has a timer running (phase-06 §6.4, INV-P4, P6-28).
 *
 * `TimerService::start()` does not look before it inserts: it INSERTs and lets `uq_te_running` decide, then
 * re-reads the worker's live entry and throws this. That is what makes two browser tabs resolve to **one**
 * timer and a clear message rather than two clocks — a SELECT-then-INSERT would race between its own two
 * statements, and the second clock would be invisible until someone read the hours back.
 *
 * `$runningEntryId` and `$runningOn` let the screen offer "switch to this task?", which is
 * `TimerService::switchTo()` — the only safe way to move a timer, because a separate stop and start would
 * race the same guard again.
 */
final class TimerAlreadyRunningException extends ProjectRuleException
{
    public ?int $runningEntryId = null;

    public ?string $runningOn = null;

    public static function on(?int $entryId, ?string $what): self
    {
        $exception = self::refuse('timer', $what === null
            ? 'You already have a timer running. Stop it, or switch it to this work.'
            : sprintf('Your timer is already running on %s. Stop it, or switch it to this work.', $what));

        $exception->runningEntryId = $entryId;
        $exception->runningOn = $what;

        return $exception;
    }
}
