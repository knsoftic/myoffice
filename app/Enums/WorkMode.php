<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a job opening's work happens (phase-04 §3, `job_openings.work_mode`) — the only way "remote"
 * can be expressed.
 */
enum WorkMode: string
{
    use HasOptions;

    case Onsite = 'onsite';
    case Remote = 'remote';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Onsite => 'On-site',
            self::Remote => 'Remote',
            self::Hybrid => 'Hybrid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Onsite => 'slate',
            self::Remote => 'emerald',
            self::Hybrid => 'indigo',
        };
    }
}
