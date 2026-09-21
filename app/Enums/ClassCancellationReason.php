<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a class did not happen (`class_sessions.cancellation_reason`, §99).
 *
 * **A select, not free text, because the answer is a number somebody acts on.** "Nine classes lost to
 * teacher unavailability this term" is a staffing decision; the same nine written as nine sentences
 * is an anecdote. The free-text `cancellation_detail` sits beside it for the part only a person can
 * say — which teacher, which week, what was arranged instead.
 */
enum ClassCancellationReason: string
{
    use HasOptions;

    case Holiday = 'holiday';
    case TeacherUnavailable = 'teacher_unavailable';
    case ClassroomUnavailable = 'classroom_unavailable';
    case LowAttendance = 'low_attendance';
    case Technical = 'technical';
    case BatchOnHold = 'batch_on_hold';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Holiday => 'Holiday',
            self::TeacherUnavailable => 'Teacher unavailable',
            self::ClassroomUnavailable => 'Room unavailable',
            self::LowAttendance => 'Too few students',
            self::Technical => 'Technical problem',
            self::BatchOnHold => 'Batch on hold',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Holiday => 'sky',
            self::TeacherUnavailable => 'amber',
            self::ClassroomUnavailable => 'orange',
            self::LowAttendance => 'violet',
            self::Technical => 'rose',
            self::BatchOnHold => 'slate',
            self::Other => 'slate',
        };
    }

    /**
     * Was this the institute's own doing?
     *
     * The §99 report separates the two, because a term full of the first is a planning problem and a
     * term full of holidays is a calendar.
     */
    public function isInstituteFault(): bool
    {
        return match ($this) {
            self::TeacherUnavailable, self::ClassroomUnavailable, self::Technical, self::BatchOnHold => true,
            default => false,
        };
    }
}
