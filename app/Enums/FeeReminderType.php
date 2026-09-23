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
     * The `NotificationRegistry` event this type sends under (§13.3, §10.3).
     *
     * **A key, not a class.** Phase 22 was contracted to ship `FeeDueReminder` and `FeeOverdue`, and
     * what it shipped instead was a registry: an event key carries the level, the default channels,
     * the preference row and the module gate, and two bespoke classes would have carried none of
     * them. A student who muted fee reminders would still have received them, because
     * `$user->notify()` asks nobody's permission (INV-22-7).
     *
     * The keys are §10.3's own, so the preference screen and the bell agree with the table.
     */
    public function notificationEventKey(): string
    {
        return match ($this) {
            self::UpcomingDue, self::DueToday => 'fee.due',
            self::Overdue => 'fee.overdue',
        };
    }

    /** True when the amount is already late, which decides the wording and the badge. */
    public function isLate(): bool
    {
        return $this === self::Overdue;
    }
}
