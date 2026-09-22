<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a piece of course material is in its life (phase-19-23 §3.1, §2.28.1).
 *
 * **`draft` is not "hidden from students" — it is invisible to everyone but its author and staff.**
 * A teacher uploads ahead of the class, targets it, checks it, and publishes when the class happens;
 * `available_from` then handles the timing within `published`. Two mechanisms, deliberately: a draft is
 * "not ready", a window is "ready, but not yet". Collapsing them would mean the only way to hold
 * something back was to leave it unfinished.
 *
 * `archived` is terminal and keeps the bytes. Removing material a course has already been taught from
 * would rewrite what students were given.
 */
enum MaterialStatus: string
{
    use HasOptions;

    /** Visible to its author and to staff holding the module's view ability. Never to a student. */
    case Draft = 'draft';

    /** Distributable — subject to the window, the targets and the enrolment check (INV-19-3). */
    case Published = 'published';

    /** Withdrawn from circulation, bytes kept. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Published => 'emerald',
            self::Archived => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Only you and staff can see it. Students cannot, whatever it is targeted at.',
            self::Published => 'Shared with whoever it is targeted at, inside its availability window.',
            self::Archived => 'Withdrawn. The file is kept — material a class was taught from does not disappear.',
        };
    }

    /**
     * The only status a student can ever reach — and only then if the window, the targets and their
     * enrolment all agree. This answers one of INV-19-3's four conditions, not all four.
     */
    public function isVisibleToStudents(): bool
    {
        return $this === self::Published;
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }
}
