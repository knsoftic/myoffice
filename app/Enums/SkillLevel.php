<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How good somebody is at something (phase-07 §3, `employee_skills.level`, requirement §24).
 */
enum SkillLevel: string
{
    use HasOptions;

    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case Expert = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::Beginner => 'Beginner',
            self::Intermediate => 'Intermediate',
            self::Advanced => 'Advanced',
            self::Expert => 'Expert',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Beginner => 'slate',
            self::Intermediate => 'sky',
            self::Advanced => 'violet',
            self::Expert => 'emerald',
        };
    }


    /**
     * Sort key, so a skills list reads strongest first.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Beginner => 1,
            self::Intermediate => 2,
            self::Advanced => 3,
            self::Expert => 4,
        };
    }
}
