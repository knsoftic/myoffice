<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

use App\DataObjects\Institute\ClashReport;

/**
 * The slot is already taken (phase-14-17 §6.7, [D-IN-14]).
 *
 * It carries the whole report, not a sentence, because the form that catches this has to show every
 * conflict at once — a coordinator who fixes the teacher only to be told about the room has been made
 * to do the work twice — and because the "accept with a reason" path needs to know which of them are
 * overridable at all.
 */
final class ScheduleClashException extends CourseRuleException
{
    public ClashReport $report;

    public static function from(ClashReport $report, string $field = 'start_time'): self
    {
        $exception = self::refuse($field, $report->summary());
        $exception->report = $report;

        return $exception;
    }

    /**
     * A clash somebody tried to override without the permission or without a reason.
     */
    public static function needsOverride(ClashReport $report, string $field = 'start_time'): self
    {
        $exception = self::refuse($field, $report->summary()
            .' Booking it anyway needs the timetable status permission and a reason on the record.');
        $exception->report = $report;

        return $exception;
    }
}
