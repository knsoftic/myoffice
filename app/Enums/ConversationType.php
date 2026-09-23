<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A thread between two people, or between several (phase-19-23 §3.4, §2.22, requirement §94).
 *
 * **The two are not the same table row with a different count.** A `direct` thread carries
 * `direct_key = sha256(sorted ids)` and `uq_cv_direct`, so messaging the same person twice reopens
 * the thread you already had instead of starting a second one nobody will read — `startDirect()`
 * catches the 1062 and **returns the existing thread** rather than failing (INV-22-5). A `group` has
 * no such key, because two groups with the same members are a legitimate thing to want.
 *
 * **`requiresSubject()` follows from that.** A direct thread is identified by who is in it; a group
 * needs a name, or a list of six group threads is six rows of the same avatars.
 */
enum ConversationType: string
{
    use HasOptions;

    /** Two people. One thread between them, for ever. */
    case Direct = 'direct';

    /** Three or more, with a subject. */
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Group => 'Group',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Direct => 'sky',
            self::Group => 'indigo',
        };
    }

    /**
     * Must this thread be named?
     *
     * A direct thread is identified by the other person. A group without a subject is
     * indistinguishable from every other group with the same people in it.
     */
    public function requiresSubject(): bool
    {
        return $this === self::Group;
    }

    /** Does this kind hold `direct_key` — the guard that makes one thread per pair? */
    public function hasDirectKey(): bool
    {
        return $this === self::Direct;
    }
}
