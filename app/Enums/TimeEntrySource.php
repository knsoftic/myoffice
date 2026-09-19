<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a time entry came into being (phase-06 §3, `time_entries.source`).
 *
 * A `timer` entry is built from append-only `time_entry_segments` (INV-P5) and is the only kind that can
 * be `running` or `paused`; a `manual` entry is created already `stopped` with a single closed segment, so
 * every duration in the system is still the SUM of segments (INV-P6).
 */
enum TimeEntrySource: string
{
    use HasOptions;

    case Timer = 'timer';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Timer => 'Timer',
            self::Manual => 'Manual entry',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Timer => 'sky',
            self::Manual => 'slate',
        };
    }

    /**
     * Was this entry driven by the start/pause/stop timer?
     */
    public function isTimer(): bool
    {
        return $this === self::Timer;
    }
}
