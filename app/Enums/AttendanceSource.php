<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How an attendance row came to exist (phase-07 §2.9, §3).
 *
 * `system` is the nightly closer: the job that marks an unclosed day absent or closes a missing check-out
 * rather than leaving the row half-written forever.
 */
enum AttendanceSource: string
{
    use HasOptions;

    case SelfWeb = 'self_web';
    case Kiosk = 'kiosk';
    case Admin = 'admin';
    case Import = 'import';
    case Api = 'api';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::SelfWeb => 'Self service',
            self::Kiosk => 'Kiosk',
            self::Admin => 'Entered by HR',
            self::Import => 'Imported',
            self::Api => 'Device / API',
            self::System => 'System',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SelfWeb => 'sky',
            self::Kiosk => 'cyan',
            self::Admin => 'violet',
            self::Import => 'amber',
            self::Api => 'teal',
            self::System => 'slate',
        };
    }

    /**
     * Did the employee mark this themselves?
     */
    public function isSelfService(): bool
    {
        return $this === self::SelfWeb || $this === self::Kiosk;
    }
}
