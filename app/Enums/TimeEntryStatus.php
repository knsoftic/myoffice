<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The life of one time entry (phase-06 §3, `time_entries.status`), transitions per §2.13.4.
 *
 * At most one entry per worker may be {@see isLive()} at a time, and that is decided by the unique index
 * `uq_te_running` over the generated `running_guard` column — never by a SELECT-then-INSERT (INV-P4).
 * `stopped` is terminal: a correction is a discard plus a new entry, never an edit of the old one.
 */
enum TimeEntryStatus: string
{
    use HasOptions;

    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Paused => 'Paused',
            self::Stopped => 'Stopped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Running => 'emerald',
            self::Paused => 'amber',
            self::Stopped => 'slate',
        };
    }

    /**
     * Is this entry still the worker's open one — running or merely paused?
     *
     * Both hold the `uq_te_running` slot: pausing does not free a worker to start a second timer.
     */
    public function isLive(): bool
    {
        return $this !== self::Stopped;
    }

    /**
     * The §2.13.4 transition table.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Running => [self::Paused, self::Stopped],
            self::Paused => [self::Running, self::Stopped],
            self::Stopped => [],
        };
    }

    /**
     * Is `$to` listed in {@see allowedTransitions()}?
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
