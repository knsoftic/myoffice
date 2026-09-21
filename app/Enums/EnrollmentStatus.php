<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * One student's place in one batch (`student_batch_enrollments.status`, phase-14-17 §2.30.8).
 *
 * **Only `active` fills `current_guard`**, which is what makes `batches.current_students` a count of
 * exactly the active rows and lets a student be re-enrolled in a batch they once dropped. A status
 * that also counted would make the capacity check refuse a seat that is not taken.
 *
 * **`suspended` keeps the seat and stops the expectation.** A student who is suspended is still on
 * the roster — the seat is not given away — but they are not marked absent for classes nobody
 * expected them at, which is the difference between a suspension and a drop.
 *
 * **`transferred_out` is never the end of a story.** Its successor row carries the same student into
 * another batch, and the two are linked both ways so the history reads in either direction.
 */
enum EnrollmentStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Suspended = 'suspended';
    case TransferredOut = 'transferred_out';
    case Completed = 'completed';
    case Dropped = 'dropped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::TransferredOut => 'Transferred out',
            self::Completed => 'Completed',
            self::Dropped => 'Dropped',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Suspended => 'amber',
            self::TransferredOut => 'sky',
            self::Completed => 'teal',
            self::Dropped => 'rose',
            self::Cancelled => 'slate',
        };
    }

    /**
     * Does this row occupy a seat? `batches.current_students` counts exactly these, and so does the
     * capacity check — one definition, so a batch can never look full while a seat is free.
     */
    public function countsInCapacity(): bool
    {
        return $this === self::Active;
    }

    /**
     * Is this student expected at the next class? A suspended student keeps the seat and drops out of
     * the register, so an absence is never recorded against somebody nobody was waiting for.
     */
    public function countsInAttendance(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::TransferredOut, self::Completed, self::Dropped, self::Cancelled => true,
            default => false,
        };
    }
}
