<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether a student sat the exam, and what it means that they did not (phase-19-23 §3.2, §2.12).
 *
 * **This enum exists because "absent" and "scored zero" are different facts.** A zero is a mark; an
 * absence is the lack of one. Storing an absence as `obtained_marks = 0` would drag the class average
 * down with a number nobody earned, and `chk_er_appeared` / `chk_er_absent` make the distinction a
 * database fact: `appeared` must have marks, and nothing else may.
 *
 * **`exempt` is the one that is excused, and it is the reason `countsInDenominator()` exists.** A
 * student excused on medical grounds did not fail — they were not assessed. Counting them would make a
 * class of twenty with one exemption look like nineteen passed out of twenty. `absent` and `debarred`
 * *are* counted, because both are outcomes the student is answerable for.
 */
enum ExamAttendanceStatus: string
{
    use HasOptions;

    /** They sat it. The only status that carries marks. */
    case Appeared = 'appeared';

    /** They did not turn up. Counted, and counted as a fail. */
    case Absent = 'absent';

    /** Excused — medical, compassionate, an approved clash. Not counted at all. */
    case Exempt = 'exempt';

    /** Barred from sitting it: attendance, discipline, unpaid fees. Counted, and a fail. */
    case Debarred = 'debarred';

    public function label(): string
    {
        return match ($this) {
            self::Appeared => 'Appeared',
            self::Absent => 'Absent',
            self::Exempt => 'Exempt',
            self::Debarred => 'Debarred',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Appeared => 'emerald',
            self::Absent => 'rose',
            self::Exempt => 'slate',
            self::Debarred => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Appeared => 'Sat the exam and has a mark.',
            self::Absent => 'Did not attend. Counts as a fail.',
            self::Exempt => 'Excused, and left out of the class figures entirely.',
            self::Debarred => 'Not permitted to sit it. Counts as a fail.',
        };
    }

    /**
     * `chk_er_appeared` and `chk_er_absent` between them say the same thing at the database: marks
     * exist for exactly this status and for no other.
     */
    public function requiresMarks(): bool
    {
        return $this === self::Appeared;
    }

    /**
     * Counted in the class figures. **`exempt` is not**: they were not assessed, so including them
     * would turn an excused absence into a statistic against the batch.
     */
    public function countsInDenominator(): bool
    {
        return $this !== self::Exempt;
    }

    /** Not sitting it is not passing it — for the two statuses the student is answerable for. */
    public function countsAsFail(): bool
    {
        return $this === self::Absent || $this === self::Debarred;
    }
}
