<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What one student did about one class (`student_attendances.status`, §75).
 *
 * **Exactly the four states the requirement names**, and deliberately not a fifth. Every extra state
 * anybody is tempted to add — "excused", "half day", "left early" — is either `leave` with a remark or
 * a different question, and each one would have to be given a place in the percentage formula, on the
 * marking control, in the monthly matrix and in the certificate rule.
 *
 * **It is not named `AttendanceStatus`.** Phase 7 owns employee attendance with a different case set
 * (§26 adds half day and early leave), and two enums with one name in one namespace is a merge
 * conflict waiting to happen.
 *
 * **`late` counts as present and `leave` is excused.** A student who arrived is a student who
 * attended; the lateness is recorded in `minutes_late` for whoever cares about punctuality, and it
 * does not cost them the class. `leave` enters the denominator only when the institute says it
 * should (`institute.attendance_leave_counts_in_denominator`), because whether an approved absence
 * dilutes a percentage is a policy choice and not an arithmetic one.
 */
enum StudentAttendanceStatus: string
{
    use HasOptions;

    case Present = 'present';
    case Absent = 'absent';
    case Leave = 'leave';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Leave => 'Leave',
            self::Late => 'Late',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Present => 'emerald',
            self::Absent => 'rose',
            self::Leave => 'sky',
            self::Late => 'amber',
        };
    }

    /**
     * The single letter the marking control and the monthly matrix show.
     *
     * A glyph as well as a colour, never colour alone: a register that can only be read in colour
     * cannot be read by everybody, and cannot be photocopied.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::Absent => 'A',
            self::Leave => 'L',
            self::Late => 'Lt',
        };
    }

    /** The keyboard key that marks a focused row on the attendance screen (§8.15). */
    public function shortcut(): string
    {
        return match ($this) {
            self::Present => 'p',
            self::Absent => 'a',
            self::Leave => 'l',
            self::Late => 't',
        };
    }

    /** The numerator of the attendance percentage: they were in the room. */
    public function countsAsPresent(): bool
    {
        return $this === self::Present || $this === self::Late;
    }

    /** A real absence — `leave` is excused, and the two must never be summed together. */
    public function isAbsence(): bool
    {
        return $this === self::Absent;
    }

    /**
     * The denominator. All four count; whether `leave` does is the one policy choice §6.9 exposes,
     * and it is read from the setting by the service rather than decided here.
     */
    public function countsInDenominator(): bool
    {
        return true;
    }
}
