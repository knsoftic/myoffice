<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How an attendance row came to exist (`student_attendances.marked_via`, phase-14-17 §2.24).
 *
 * **`system` is the one that matters.** When `institute.attendance_auto_absent_on_close` is on,
 * closing a class fills the unmarked students as absent — and a student challenging an absence
 * deserves to know whether a person decided it or a setting did. Without this column that question
 * has no answer, and the register looks equally authoritative either way.
 *
 * `bulk` is a person pressing "all present" and then overriding; it is still their decision, but
 * distinguishing it from a row-by-row mark tells you how carefully a register was taken.
 */
enum AttendanceMarkSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case Bulk = 'bulk';
    case Import = 'import';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Marked individually',
            self::Bulk => 'Marked in bulk',
            self::Import => 'Imported',
            self::System => 'Filled by the system',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'emerald',
            self::Bulk => 'sky',
            self::Import => 'violet',
            self::System => 'slate',
        };
    }

    /** Did a person decide this row? The auto-absent fill did not. */
    public function isHumanJudgement(): bool
    {
        return $this !== self::System;
    }
}
