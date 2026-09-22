<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which of the three fee reminders was sent (phase-18 §3, requirement §97).
 *
 * The type is part of `uq_sfr_dedupe`, so it is not decoration: it is what lets one installment line
 * legitimately produce a "due in three days" reminder, a "due today" reminder and an overdue reminder
 * without any of the three counting as a duplicate of the others.
 *
 * `offsetSign()` is the sign convention `student_fee_reminders.offset_days` stores — positive is before
 * the due date, zero is on it, negative is after. It is written down here because "offset 5" is
 * meaningless on its own, and a reminder log whose sign nobody can read cannot answer the only question
 * it exists to answer: was this student actually told, and when.
 */
enum FeeReminderType: string
{
    use HasOptions;

    /** Sent ahead of the due date, one per configured offset in `institute.installment_reminder_days`. */
    case UpcomingDue = 'upcoming_due';

    /** Sent on the due date itself. */
    case DueToday = 'due_today';

    /** Sent after the due date has passed and the line is still unsettled. */
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::UpcomingDue => 'Due soon',
            self::DueToday => 'Due today',
            self::Overdue => 'Overdue',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::UpcomingDue => 'sky',
            self::DueToday => 'amber',
            self::Overdue => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UpcomingDue => 'A heads-up before the due date.',
            self::DueToday => 'The payment is due today.',
            self::Overdue => 'The due date has passed and the amount is still outstanding.',
        };
    }

    /**
     * The sign `offset_days` carries for this type: `+1` before the due date, `0` on it, `-1` after.
     *
     * So `upcoming_due` with three days to go stores `+3`, `due_today` stores `0`, and a reminder five
     * days late stores `-5`. One column, one convention, and the dedupe key reads the same in both
     * directions.
     */
    public function offsetSign(): int
    {
        return match ($this) {
            self::UpcomingDue => 1,
            self::DueToday => 0,
            self::Overdue => -1,
        };
    }

    /**
     * The notification Phase 22 sends for this type (§13.3 — Phase 22 ships the classes).
     *
     * Returned as a string rather than a `::class` reference so this enum stays loadable before those
     * classes exist; `FeeReminderService` checks the class before dispatching and records the row either
     * way, because "we decided to tell them" is worth logging even on a night the mailer was missing.
     */
    public function notificationClass(): string
    {
        return match ($this) {
            self::UpcomingDue, self::DueToday => 'App\\Notifications\\Institute\\FeeDueReminder',
            self::Overdue => 'App\\Notifications\\Institute\\FeeOverdue',
        };
    }

    /** True when the amount is already late, which decides the wording and the badge. */
    public function isLate(): bool
    {
        return $this === self::Overdue;
    }
}
