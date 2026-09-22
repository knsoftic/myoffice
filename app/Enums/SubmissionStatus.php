<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where one student's work on one assignment stands (phase-19-23 §3.1, §2.28.3).
 *
 * **`superseded` is why `isLive()` exists, and it is what INV-19-5 is built on.** A resubmission does
 * not overwrite the previous attempt — it supersedes it, and the old row keeps its files, its marks and
 * its timestamps. `uq_as_live` is a unique index over a generated column that is 1 for everything
 * except `superseded` and NULL for that, so MariaDB lets the history stack while permitting exactly one
 * live row per student per assignment.
 *
 * **`missed` is written by the sweeper, never by a student.** It means the deadline passed with nothing
 * submitted, which is a statement about the roster rather than about a row somebody created.
 *
 * `visibleMarks()` is deliberately narrower than `isGraded()`: a teacher may mark privately and release
 * later, so the assignment's `marks_visible_to_students` and the submission's `marks_released_at` both
 * sit on top of this.
 */
enum SubmissionStatus: string
{
    use HasOptions;

    /** Started, not handed in. The only status a student may still edit. */
    case Draft = 'draft';

    /** Handed in, waiting to be looked at. */
    case Submitted = 'submitted';

    /** A teacher has it open. */
    case UnderReview = 'under_review';

    /** Sent back for rework — the student may submit again if the assignment allows it. */
    case Returned = 'returned';

    /** Marked. */
    case Graded = 'graded';

    /** The deadline passed with nothing handed in. Written by the sweeper, never by a student. */
    case Missed = 'missed';

    /** Replaced by a later attempt. Kept whole; never edited, never deleted. */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Returned => 'Returned',
            self::Graded => 'Graded',
            self::Missed => 'Missed',
            self::Superseded => 'Superseded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Submitted => 'sky',
            self::UnderReview => 'violet',
            self::Returned => 'amber',
            self::Graded => 'emerald',
            self::Missed => 'rose',
            self::Superseded => 'slate',
        };
    }

    /**
     * Counts towards the assignment's `submitted_count`. A draft was never handed in, a miss never
     * existed as work, and a superseded row has already been counted as its successor.
     */
    public function countsAsSubmitted(): bool
    {
        return ! in_array($this, [self::Draft, self::Missed, self::Superseded], true);
    }

    /** INV-19-5: exactly one of these per (assignment, student), enforced by `uq_as_live`. */
    public function isLive(): bool
    {
        return $this !== self::Superseded;
    }

    public function isGraded(): bool
    {
        return $this === self::Graded;
    }

    /** A student may change their own work only before they hand it in. */
    public function isEditableByStudent(): bool
    {
        return $this === self::Draft;
    }

    /**
     * The marks exist on the row. Whether the *student* sees them is a further question —
     * `marks_visible_to_students` on the assignment and `marks_released_at` on the submission — so a
     * teacher can mark a whole batch privately and release it in one go.
     */
    public function visibleMarks(): bool
    {
        return $this === self::Graded;
    }
}
