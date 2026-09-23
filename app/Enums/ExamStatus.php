<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where an exam is in its life (phase-19-23 §3.2, §2.28.4, requirement §81).
 *
 * **`conducted` and `marking` are both result-entry states, and keeping them separate is the point.**
 * "The exam has happened" and "somebody is entering the sheet" are different facts a coordinator needs
 * to tell apart when chasing a marker, and collapsing them would make "conducted but nobody has
 * started" invisible.
 *
 * **`results_published` is the only status a student sees a mark in.** Marks may exist on rows from the
 * moment entry begins; `resultsVisible()` is what stands between them and the student panel, and it is
 * deliberately not a range check — adding a status must mean deciding this question for it, on purpose.
 *
 * **`cancelled` is not `draft`.** A cancelled exam keeps its reason, its date and any results already
 * entered against it, because the question a student asks is "what happened to the exam I sat", and a
 * row that reverted to draft answers it with silence. `active_guard` is NULL for it so
 * `uq_ex_batch_slot` frees the slot for a replacement.
 */
enum ExamStatus: string
{
    use HasOptions;

    /** Being set up. Nobody outside the staff panel knows it exists. */
    case Draft = 'draft';

    /** On the calendar. The batch has been told. */
    case Scheduled = 'scheduled';

    /** Happening now. */
    case Ongoing = 'ongoing';

    /** It happened. Marks may now be entered. */
    case Conducted = 'conducted';

    /** Somebody is entering the sheet. */
    case Marking = 'marking';

    /** Verified where the setting demands it, and visible to students. */
    case ResultsPublished = 'results_published';

    /** Called off, with a reason. Whatever was entered is kept. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Ongoing => 'Ongoing',
            self::Conducted => 'Conducted',
            self::Marking => 'Marking',
            self::ResultsPublished => 'Results published',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Scheduled => 'sky',
            self::Ongoing => 'violet',
            self::Conducted => 'amber',
            self::Marking => 'amber',
            self::ResultsPublished => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Only staff can see it.',
            self::Scheduled => 'On the calendar, and the batch has been told.',
            self::Ongoing => 'Being sat right now.',
            self::Conducted => 'It happened. Marks can be entered.',
            self::Marking => 'Marks are being entered.',
            self::ResultsPublished => 'Students can see their own result.',
            self::Cancelled => 'Called off. Anything already entered is kept.',
        };
    }

    /** Marks may be written. Entry starts once the exam has actually happened. */
    public function acceptsResultEntry(): bool
    {
        return $this === self::Conducted || $this === self::Marking;
    }

    /**
     * A student may see their mark. **Only one status, and not a range** — adding a case must mean
     * answering this question for it deliberately rather than inheriting an answer from its position.
     */
    public function resultsVisible(): bool
    {
        return $this === self::ResultsPublished;
    }

    public function isTerminal(): bool
    {
        return $this === self::ResultsPublished || $this === self::Cancelled;
    }

    /** A draft was never real and a cancellation did not happen, so neither belongs in a report. */
    public function countsInReports(): bool
    {
        return $this !== self::Draft && $this !== self::Cancelled;
    }

    /** `active_guard` is NULL here, which frees the batch's slot for a replacement exam. */
    public function holdsTheSlot(): bool
    {
        return $this !== self::Cancelled;
    }
}
