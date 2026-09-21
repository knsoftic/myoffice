<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * §66's gender field, declared here and nowhere else (F-5.8).
 *
 * Nullable on every table that uses it: "not stated" is a real answer and is a null, never a fourth
 * case, so a report can tell somebody who declined to say from somebody nobody asked.
 */
enum Gender: string
{
    use HasOptions;

    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Male => 'sky',
            self::Female => 'violet',
            self::Other => 'slate',
        };
    }
}
