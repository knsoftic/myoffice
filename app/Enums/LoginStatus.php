<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Outcome recorded on a login_histories row (login_histories.status).
 */
enum LoginStatus: string
{
    use HasOptions;

    case Success = 'success';
    case Failed = 'failed';
    case Logout = 'logout';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Logout => 'Logout',
            self::Blocked => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Success => 'emerald',
            self::Failed => 'rose',
            self::Logout => 'slate',
            self::Blocked => 'amber',
        };
    }
}
