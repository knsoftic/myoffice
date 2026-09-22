<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * What one run of `fees:mark-overdue` touched (phase-18 §6.6).
 *
 * **One activity row per run, not one per charge.** A two-thousand-row night would otherwise bury every
 * human action in the log for that day, and the question anybody actually asks the log is "what did the
 * sweeper do last night", which is one answer. The ids are carried here so that one row can name them.
 *
 * `$alreadyOverdue` exists to make idempotence visible: a second run of the same night reports rows it
 * looked at and left alone rather than reporting zero and looking like it did not run.
 */
final readonly class OverdueSweepResult
{
    /**
     * @param  list<int>  $chargeIds
     * @param  list<int>  $installmentIds
     */
    public function __construct(
        public array $chargeIds = [],
        public array $installmentIds = [],
        public int $alreadyOverdue = 0,
        public bool $reachedLimit = false,
    ) {}

    public function charges(): int
    {
        return count($this->chargeIds);
    }

    public function installments(): int
    {
        return count($this->installmentIds);
    }

    public function touchedNothing(): bool
    {
        return $this->chargeIds === [] && $this->installmentIds === [];
    }

    public function caption(): string
    {
        if ($this->touchedNothing()) {
            return $this->alreadyOverdue === 0
                ? 'Nothing is overdue.'
                : sprintf('Nothing new — %d row%s already overdue.', $this->alreadyOverdue, $this->alreadyOverdue === 1 ? ' was' : 's were');
        }

        $sentence = sprintf(
            '%d charge%s and %d installment%s marked overdue.',
            $this->charges(),
            $this->charges() === 1 ? '' : 's',
            $this->installments(),
            $this->installments() === 1 ? '' : 's',
        );

        // A run that hit its ceiling has more to do, and the next run will do it — but only if somebody
        // knows to expect that rather than reading the count as the whole backlog.
        return $this->reachedLimit
            ? $sentence.' The run hit its row limit, so more remain for the next pass.'
            : $sentence;
    }
}
