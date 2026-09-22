<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where an assignment is in its life (phase-19-23 §3.1, §2.28.2).
 *
 * **`closed` still shows the assignment to students, and that is the point of it existing.** A closed
 * assignment stops accepting work but keeps the brief, the deadline and — once marks are released —
 * the student's own mark and feedback visible. Collapsing `closed` into `archived` would mean the only
 * way to stop submissions was to hide the thing everybody was marked on.
 *
 * The deadline and `closed` are different mechanisms for the same reason `draft` and `available_from`
 * are on a material: a deadline is "you are late now", a close is "we have stopped collecting".
 */
enum AssignmentStatus: string
{
    use HasOptions;

    /** Being written. Invisible to students whatever its deadline says. */
    case Draft = 'draft';

    /** Live: visible, and the only status that accepts a submission. */
    case Published = 'published';

    /** Collected. Still visible, still markable, no longer submittable. */
    case Closed = 'closed';

    /** Off the students' list entirely. Marks and submissions are kept. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Closed => 'Closed',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Published => 'emerald',
            self::Closed => 'amber',
            self::Archived => 'slate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Only you and staff can see it.',
            self::Published => 'Students can see it and submit to it.',
            self::Closed => 'Students can still see it and their own marks; nobody can submit.',
            self::Archived => 'Off the list. Everything submitted and marked is kept.',
        };
    }

    /** The one status a submission can be created against. A late submission is still a published one. */
    public function acceptsSubmissions(): bool
    {
        return $this === self::Published;
    }

    /**
     * `closed` is here deliberately: a student must be able to see what they were marked on after
     * collection stops.
     */
    public function isVisibleToStudents(): bool
    {
        return $this === self::Published || $this === self::Closed;
    }

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }
}
