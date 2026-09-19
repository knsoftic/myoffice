<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a timer segment was closed (phase-06 §3, `time_entry_segments.end_reason`).
 *
 * Only `pause` leaves the entry live ({@see closesEntry()}): the segment closes, the entry becomes
 * `paused` and still holds the worker's single-timer slot. `auto_stop` is written by
 * `projects:auto-stop-timers` once a timer passes `projects.timer_max_hours`, and `switched` when the
 * worker starts a timer on different work and the previous one is closed for them.
 */
enum TimerStopReason: string
{
    use HasOptions;

    case Pause = 'pause';
    case Stop = 'stop';
    case AutoStop = 'auto_stop';
    case Switched = 'switched';

    public function label(): string
    {
        return match ($this) {
            self::Pause => 'Paused',
            self::Stop => 'Stopped',
            self::AutoStop => 'Stopped automatically',
            self::Switched => 'Switched to other work',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pause => 'amber',
            self::Stop => 'slate',
            self::AutoStop => 'rose',
            self::Switched => 'sky',
        };
    }

    /**
     * Does closing a segment for this reason also end the entry? Everything but `pause` (§3).
     */
    public function closesEntry(): bool
    {
        return $this !== self::Pause;
    }

    /**
     * Was the segment closed by the system rather than by the worker?
     */
    public function isAutomatic(): bool
    {
        return $this === self::AutoStop || $this === self::Switched;
    }
}
