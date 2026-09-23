<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

use App\Models\Institute\Exam;

/**
 * Nothing says how to grade this exam (phase-19-23 §6.9).
 *
 * **Thrown rather than falling back to an invented ladder.** An exam that names no scale, in an
 * institute with no default, is a misconfiguration — and a grade produced from an assumption nobody
 * made is worse than a refusal somebody has to fix. A silent A/B/C fallback here would be the exact
 * hardcoded ladder [D-20-1] exists to prevent.
 */
final class NoGradeScale extends CourseRuleException
{
    public static function forExam(Exam $exam): self
    {
        return self::refuse('grade_scale_id', sprintf(
            'There is no grade scale for “%s”, and the institute has no default one either. Choose a '
            .'scale for this exam, or set a default in the institute settings.',
            (string) $exam->getAttribute('name'),
        ));
    }
}
