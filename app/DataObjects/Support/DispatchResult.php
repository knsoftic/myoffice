<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

/**
 * What one dispatch actually did (phase-19-23 §6.19).
 *
 * **A dispatch that reached nobody is not a failure, and it is not a success either.** A ticket
 * raised in a department with no agents, a commission for a collaborator who has turned that
 * notification off, a fee reminder for a student with no login — all three are ordinary, and all
 * three would look identical to a caller that got back `void`. The reason is here so a command can
 * print it and a test can assert on it.
 *
 * **`skipped` counts people, `reason` explains the whole dispatch.** They answer different
 * questions: "did everybody get it?" and "why did none of them?".
 */
final readonly class DispatchResult
{
    /**
     * @param  list<int>  $recipientIds  user ids that were actually notified
     * @param  array<string, int>  $skipped  reason => how many people it dropped
     */
    public function __construct(
        public string $eventKey,
        public array $recipientIds = [],
        public array $skipped = [],
        public ?string $reason = null,
    ) {}

    public static function delivered(string $eventKey, array $recipientIds, array $skipped = []): self
    {
        return new self($eventKey, array_values(array_unique($recipientIds)), $skipped);
    }

    /** Nothing was sent, and here is the sentence that says why. */
    public static function nothing(string $eventKey, string $reason, array $skipped = []): self
    {
        return new self($eventKey, [], $skipped, $reason);
    }

    public function count(): int
    {
        return count($this->recipientIds);
    }

    public function reachedAnybody(): bool
    {
        return $this->recipientIds !== [];
    }

    public function describe(): string
    {
        if ($this->reachedAnybody()) {
            return sprintf('%s → %d recipient%s', $this->eventKey, $this->count(), $this->count() === 1 ? '' : 's');
        }

        return sprintf('%s → nobody (%s)', $this->eventKey, $this->reason ?? 'no recipients resolved');
    }
}
