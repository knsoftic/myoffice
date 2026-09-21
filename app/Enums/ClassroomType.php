<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What kind of room this is (`classrooms.type`, §70, §71).
 *
 * **`virtual` is the one that changes a rule.** A virtual room holds an unlimited number of
 * simultaneous classes, so it never takes part in classroom clash detection and never acts as the
 * second capacity ceiling. Treating it like a physical room would refuse two online classes that
 * cannot possibly collide.
 */
enum ClassroomType: string
{
    use HasOptions;

    case Classroom = 'classroom';
    case Lab = 'lab';
    case Hall = 'hall';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Classroom => 'Classroom',
            self::Lab => 'Lab',
            self::Hall => 'Hall',
            self::Virtual => 'Virtual room',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Classroom => 'sky',
            self::Lab => 'violet',
            self::Hall => 'amber',
            self::Virtual => 'slate',
        };
    }

    /**
     * Does booking this room stop somebody else booking it?
     *
     * The clash detector asks this before it searches the classroom dimension at all.
     */
    public function isBookable(): bool
    {
        return $this !== self::Virtual;
    }

    /** Does its capacity limit a batch? Only a room somebody physically sits in. */
    public function limitsCapacity(): bool
    {
        return $this !== self::Virtual;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Classroom => 'building-office-2',
            self::Lab => 'beaker',
            self::Hall => 'building-library',
            self::Virtual => 'video-camera',
        };
    }
}
