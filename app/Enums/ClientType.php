<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Whether a client is a person or an organisation (phase-05 §3, `clients.client_type`).
 */
enum ClientType: string
{
    use HasOptions;

    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company => 'Company',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Individual => 'sky',
            self::Company => 'indigo',
        };
    }

    /**
     * An individual client is identified for tax by CNIC rather than by NTN / STRN (§19 tax details).
     */
    public function requiresCnic(): bool
    {
        return $this === self::Individual;
    }
}
