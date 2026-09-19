<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What automatic progress is averaged over (phase-06 §3, `projects.progress_basis`).
 *
 * Seeded from `projects.default_progress_basis` when a project is created (§6.1). §6.3 falls back to the
 * other basis, and then to `ProjectStatus::progressWeight()`, when the chosen one has nothing to average.
 */
enum ProgressBasis: string
{
    use HasOptions;

    case Milestones = 'milestones';
    case Tasks = 'tasks';

    public function label(): string
    {
        return match ($this) {
            self::Milestones => 'Milestones',
            self::Tasks => 'Tasks',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Milestones => 'violet',
            self::Tasks => 'sky',
        };
    }

    /**
     * The basis §6.3 tries when this one has nothing to average.
     */
    public function fallback(): self
    {
        return $this === self::Milestones ? self::Tasks : self::Milestones;
    }
}
