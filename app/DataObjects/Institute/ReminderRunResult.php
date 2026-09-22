<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What one reminder run reached (phase-18 §6.8).
 *
 * **`$skippedDuplicate` is the guard working, not a failure.** `uq_sfr_dedupe` is what stops a retried
 * job, a second scheduler tick and a staff member pressing "Send reminder now" from chasing the same
 * student three times for the same line on the same day. The service inserts **first** and sends only
 * when the INSERT was the winner, so a 1062 means somebody else already told them — which is counted
 * here and is a good outcome.
 *
 * `$skippedNoRecipient` is separated out because it is the opposite: a student nobody can reach is a
 * data problem somebody should fix, and burying it in the duplicate count would hide it.
 */
final readonly class ReminderRunResult
{
    public function __construct(
        public string $runUuid,
        public int $sent = 0,
        public int $skippedDuplicate = 0,
        public int $skippedNoRecipient = 0,
        public int $failed = 0,
        public int $examined = 0,
    ) {}

    public function with(
        int $sent = 0,
        int $skippedDuplicate = 0,
        int $skippedNoRecipient = 0,
        int $failed = 0,
        int $examined = 0,
    ): self {
        return new self(
            runUuid: $this->runUuid,
            sent: $this->sent + $sent,
            skippedDuplicate: $this->skippedDuplicate + $skippedDuplicate,
            skippedNoRecipient: $this->skippedNoRecipient + $skippedNoRecipient,
            failed: $this->failed + $failed,
            examined: $this->examined + $examined,
        );
    }

    public function caption(): string
    {
        if ($this->examined === 0) {
            return 'Nothing was due a reminder.';
        }

        $parts = [sprintf('%d sent', $this->sent)];

        if ($this->skippedDuplicate > 0) {
            $parts[] = sprintf('%d already sent today', $this->skippedDuplicate);
        }

        if ($this->skippedNoRecipient > 0) {
            $parts[] = sprintf('%d with nobody to write to', $this->skippedNoRecipient);
        }

        if ($this->failed > 0) {
            $parts[] = sprintf('%d failed', $this->failed);
        }

        return sprintf('%d due: %s.', $this->examined, implode(', ', $parts));
    }
}
