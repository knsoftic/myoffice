<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a student's topic row got the value it has (`student_topic_progress.source`, §2.28).
 *
 * **`manual` is a shield, not a label.** Marking a topic covered for a batch fans out to every active
 * enrolment — except the rows somebody set by hand. A teacher who recorded that one student has not
 * grasped a topic the rest of the class finished must not have that judgement quietly overwritten the
 * next time the class-level mark runs, and this column is what stops it.
 *
 * `assessment` is reserved for Phase 20: a result that moves a topic's progress should say so, rather
 * than looking like somebody typed it.
 */
enum ProgressSource: string
{
    use HasOptions;

    case BatchCoverage = 'batch_coverage';
    case Manual = 'manual';
    case Assessment = 'assessment';

    public function label(): string
    {
        return match ($this) {
            self::BatchCoverage => 'Covered with the class',
            self::Manual => 'Set for this student',
            self::Assessment => 'From an assessment',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BatchCoverage => 'sky',
            self::Manual => 'amber',
            self::Assessment => 'violet',
        };
    }

    /**
     * May a class-level mark overwrite a row with this source? Only one the class itself set.
     */
    public function isOverwritableByBatch(): bool
    {
        return $this === self::BatchCoverage;
    }
}
