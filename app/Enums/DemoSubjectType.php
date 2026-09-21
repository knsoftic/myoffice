<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which record a demo class was booked for (`demo_classes.subject_type`, §87).
 *
 * The column is explicit rather than inferred from whichever of the three foreign keys is filled,
 * because an index and a report both need to ask "how many demos did we give to people who had not
 * applied yet" without three `IS NOT NULL` tests. `chk_dc_one_subject` keeps the column and the keys
 * honest: exactly one subject, always.
 */
enum DemoSubjectType: string
{
    use HasOptions;

    case Inquiry = 'inquiry';
    case Application = 'application';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Inquiry => 'Inquiry',
            self::Application => 'Applicant',
            self::Student => 'Student',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inquiry => 'sky',
            self::Application => 'amber',
            self::Student => 'emerald',
        };
    }

    /** The column on `demo_classes` this subject type fills. */
    public function column(): string
    {
        return match ($this) {
            self::Inquiry => 'course_inquiry_id',
            self::Application => 'student_application_id',
            self::Student => 'student_id',
        };
    }
}
