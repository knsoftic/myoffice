<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

/**
 * A register refused what it was asked to record (phase-14-17 §6.9, INV-I9, INV-I10).
 *
 * Separate from `CourseRuleException` only so a caller can tell the two apart; it is the same 422
 * with the message on the named field. The messages name the student and the date, because somebody
 * is standing at a classroom door with a phone when they read one.
 */
final class AttendanceRuleException extends CourseRuleException
{
    public static function notOnTheRoster(string $student, string $date): self
    {
        return self::refuse('marks', sprintf(
            '%s was not enrolled in this batch on %s, so there is nothing to mark. A student who joined '
            .'later was never absent for a class held before they started.',
            $student,
            $date,
        ));
    }

    public static function sessionIsNotTeachable(string $status): self
    {
        return self::refuse('class_session_id', sprintf(
            'This class is %s, so attendance cannot be recorded against it. A cancelled class is not an '
            .'absence for anybody.',
            mb_strtolower($status),
        ));
    }

    public static function amendmentNeedsAReason(): self
    {
        return self::reasonRequired('amendment_reason',
            'This register was taken more than the lock window ago, so changing it takes a reason. The '
            .'old value is kept either way — that is what makes it a correction rather than a rewrite.');
    }

    public static function amendmentNeedsThePermission(): self
    {
        return self::refuse('status',
            'Changing a register after the lock window needs the attendance edit permission. Marking one '
            .'at the door and revising one somebody has already reported on are different acts.');
    }
}
