<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Per-user interface theme (users.theme). System follows the OS preference.
 */
enum ThemePreference: string
{
    use HasOptions;

    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
            self::System => 'System',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Light => 'amber',
            self::Dark => 'indigo',
            self::System => 'slate',
        };
    }
}
