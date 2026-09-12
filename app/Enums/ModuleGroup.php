<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Functional grouping of a module (modules.group) — drives sidebar sections
 * and the module management screen.
 */
enum ModuleGroup: string
{
    use HasOptions;

    case System = 'system';
    case SoftwareHouse = 'software_house';
    case Hr = 'hr';
    case Finance = 'finance';
    case Collaborator = 'collaborator';
    case Institute = 'institute';
    case Website = 'website';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::SoftwareHouse => 'Software House',
            self::Hr => 'HR',
            self::Finance => 'Finance',
            self::Collaborator => 'Collaborator',
            self::Institute => 'Institute',
            self::Website => 'Website',
            self::Shared => 'Shared',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::System => 'slate',
            self::SoftwareHouse => 'indigo',
            self::Hr => 'amber',
            self::Finance => 'emerald',
            self::Collaborator => 'violet',
            self::Institute => 'sky',
            self::Website => 'cyan',
            self::Shared => 'rose',
        };
    }
}
