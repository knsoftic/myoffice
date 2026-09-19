<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The engagement model a project is sold under (phase-06 §3, `projects.type`).
 *
 * §20's "project type" is read here as **how the work is charged**, not what kind of work it is: the kind
 * of work is `projects.service_id` pointing at the Phase 4 service catalogue, so the same fact is never
 * stored in two columns (§12.2 Q1).
 */
enum ProjectType: string
{
    use HasOptions;

    case FixedPrice = 'fixed_price';
    case Hourly = 'hourly';
    case Retainer = 'retainer';
    case Maintenance = 'maintenance';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::FixedPrice => 'Fixed price',
            self::Hourly => 'Hourly',
            self::Retainer => 'Retainer',
            self::Maintenance => 'Maintenance',
            self::Internal => 'Internal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FixedPrice => 'indigo',
            self::Hourly => 'sky',
            self::Retainer => 'violet',
            self::Maintenance => 'teal',
            self::Internal => 'slate',
        };
    }

    /**
     * Is the contract value the thing that is billed, rather than logged hours?
     *
     * Used by the §8.8 value tab to decide whether to show the hourly rate beside the project value.
     */
    public function isFixedValue(): bool
    {
        return $this === self::FixedPrice || $this === self::Retainer || $this === self::Maintenance;
    }

    /**
     * An internal project is the company's own work: it carries no client and earns no commission.
     */
    public function isInternal(): bool
    {
        return $this === self::Internal;
    }
}
