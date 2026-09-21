<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a teacher stands with the institute (`teachers.status`, requirement §72).
 *
 * **`canTeach()` is the only question the timetable asks.** A teacher who is on leave, suspended or
 * resigned cannot be put on a new slot — and the check lives here rather than in five services,
 * because "is this person available" answered differently in two places is how a resigned trainer
 * ends up on next week's schedule.
 *
 * `on_leave` is separate from `inactive` on purpose: one is temporary and expected back, the other is
 * a trainer the institute has stopped using. A cover arrangement is made for the first and not the
 * second, and a report that could not tell them apart would be useless for planning.
 */
enum TeacherStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case OnLeave = 'on_leave';
    case Resigned = 'resigned';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::OnLeave => 'On leave',
            self::Resigned => 'Resigned',
            self::Suspended => 'Suspended',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Inactive => 'slate',
            self::OnLeave => 'amber',
            self::Resigned => 'slate',
            self::Suspended => 'rose',
        };
    }

    /**
     * May this teacher be put on a timetable, a session or a demo?
     *
     * Only `active`. Everything else is somebody the institute cannot commit to a class next week.
     */
    public function canTeach(): bool
    {
        return $this === self::Active;
    }

    /** Should the login be out of action? A suspension and a resignation both stop it. */
    public function canLogin(): bool
    {
        return $this !== self::Suspended && $this !== self::Resigned;
    }

    /** Are they coming back? A cover arrangement is made for these and not for the others. */
    public function isTemporary(): bool
    {
        return $this === self::OnLeave;
    }
}
