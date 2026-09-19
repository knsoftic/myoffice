<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a lead conversion produced (phase-05 §3, `lead_conversions.conversion_type`).
 *
 * `client` creates or links a client; `project` hands the lead to an existing client's project; `client_and_project`
 * does both. The project half is only reachable while `ProjectCreator::isAvailable()` (Phase 6, [D-P5-1]).
 */
enum LeadConversionType: string
{
    use HasOptions;

    case Client = 'client';
    case Project = 'project';
    case ClientAndProject = 'client_and_project';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Project => 'Project',
            self::ClientAndProject => 'Client and project',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Client => 'emerald',
            self::Project => 'indigo',
            self::ClientAndProject => 'violet',
        };
    }

    /**
     * Does this conversion write the client side (a new client, or a link to an explicitly chosen one)?
     */
    public function createsClient(): bool
    {
        return $this === self::Client || $this === self::ClientAndProject;
    }

    /**
     * Does this conversion hand the lead to a project through `ProjectCreator`?
     */
    public function createsProject(): bool
    {
        return $this === self::Project || $this === self::ClientAndProject;
    }
}
