<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a class is taught (requirement §62 "type", §70, §71, §87).
 *
 * **One enum for four separate questions**, on purpose: a course's mode, a batch's mode, a timetable
 * entry's mode and a demo class's mode are the same fact about the same lesson, and three enums with
 * the same three cases would drift the moment somebody added a fourth.
 *
 * The two predicates are what the schedule validators ask. They are separate methods rather than one
 * `isOnline()` because `hybrid` answers **yes to both** — it needs a room *and* a link — and a single
 * boolean would force it to lie about one of them.
 */
enum DeliveryMode: string
{
    use HasOptions;

    case Physical = 'physical';
    case Online = 'online';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'On campus',
            self::Online => 'Online',
            self::Hybrid => 'Hybrid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Physical => 'sky',
            self::Online => 'violet',
            self::Hybrid => 'amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Physical => 'building-office-2',
            self::Online => 'video-camera',
            self::Hybrid => 'arrows-right-left',
        };
    }

    /**
     * Does it occupy a room? A `virtual` classroom is still not a room, so an online class books none
     * and never takes part in classroom clash detection (INV-I8).
     */
    public function needsClassroom(): bool
    {
        return $this !== self::Online;
    }

    /**
     * Does it need a meeting link? A hybrid class does — half its students are not in the room.
     */
    public function needsMeetingUrl(): bool
    {
        return $this !== self::Physical;
    }
}
