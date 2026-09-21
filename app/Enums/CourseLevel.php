<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How much a student needs to know before they start (`courses.level`, requirement §62).
 *
 * Three rungs, because the visitor filtering the catalogue is asking "can I do this yet", and a
 * five-point scale would make them guess at the difference between two adjacent answers.
 */
enum CourseLevel: string
{
    use HasOptions;

    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Beginner => 'Beginner',
            self::Intermediate => 'Intermediate',
            self::Advanced => 'Advanced',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Beginner => 'emerald',
            self::Intermediate => 'sky',
            self::Advanced => 'violet',
        };
    }

    /**
     * The line under the name on a public card — what the level actually means to somebody choosing.
     */
    public function description(): string
    {
        return match ($this) {
            self::Beginner => 'No prior experience needed.',
            self::Intermediate => 'Assumes the basics of the subject.',
            self::Advanced => 'For people already working in the field.',
        };
    }
}
