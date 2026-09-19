<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a project's `progress_percent` is decided (phase-06 §3, `projects.progress_mode`).
 *
 * `auto` is the default and INV-P8's normal path: `ProjectProgressService` derives the number by §6.3 and
 * nothing else may write it. `manual` is the reasoned, attributed, revocable override of [D-P6-3] /
 * **D34** — the project row additionally records `progress_set_by`.
 */
enum ProgressMode: string
{
    use HasOptions;

    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Manual => 'Manual override',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Auto => 'emerald',
            self::Manual => 'amber',
        };
    }

    /**
     * Is the percentage computed from the work below it, rather than typed by a human?
     */
    public function isDerived(): bool
    {
        return $this === self::Auto;
    }
}
