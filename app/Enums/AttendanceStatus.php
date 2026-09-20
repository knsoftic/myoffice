<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The seven attendance states of requirement §26 (phase-07 §2.9, §3, §6.3).
 *
 * {@see defaultPayableFactor()} returns a **decimal string**, never a float: it is the single bridge
 * between attendance and pay (HR-4), and `lop_days = 1 - payable_factor` on a working day. A float here
 * would put rounding error into somebody's salary.
 *
 * The factor is only the **default** — `AttendanceService::resolve()` may narrow it (a half day taken as
 * leave, a settings-driven late penalty), and the row stores what was actually decided.
 */
enum AttendanceStatus: string
{
    use HasOptions;

    case Present = 'present';
    case Late = 'late';
    case HalfDay = 'half_day';
    case EarlyLeave = 'early_leave';
    case Absent = 'absent';
    case OnLeave = 'on_leave';
    case Holiday = 'holiday';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::HalfDay => 'Half day',
            self::EarlyLeave => 'Early leave',
            self::Absent => 'Absent',
            self::OnLeave => 'On leave',
            self::Holiday => 'Holiday',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Present => 'emerald',
            self::Late => 'amber',
            self::HalfDay => 'orange',
            self::EarlyLeave => 'yellow',
            self::Absent => 'rose',
            self::OnLeave => 'sky',
            self::Holiday => 'violet',
        };
    }


    /**
     * Did the person actually turn up? Lateness and an early finish are still attendance.
     */
    public function isPresentState(): bool
    {
        return in_array($this, [self::Present, self::Late, self::EarlyLeave], true);
    }

    /**
     * The default share of the day that is payable, as a decimal string (HR-4).
     *
     * `holiday` and `on_leave` are fully payable: a paid holiday is not a day off the payroll, and an
     * approved paid leave has already been charged against a quota.
     */
    public function defaultPayableFactor(): string
    {
        return match ($this) {
            self::Present, self::Late, self::EarlyLeave, self::OnLeave, self::Holiday => '1.0000',
            self::HalfDay => '0.5000',
            self::Absent => '0.0000',
        };
    }

    /**
     * Does this state count as a worked day in the monthly summary?
     */
    public function countsAsWorkedDay(): bool
    {
        return $this->isPresentState() || $this === self::HalfDay;
    }

    /**
     * Is this state one a human chose, rather than one the engine derived?
     */
    public function isDerived(): bool
    {
        return $this !== self::OnLeave;
    }
}
