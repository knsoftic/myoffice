<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which half of the business a money row belongs to (phase-13 §2.6).
 *
 * Requirement §30 says "project/institute"; this makes it a column reports can group by, with `general`
 * for the overheads that belong to neither — rent, bank charges, the internet bill.
 *
 * **The labels read from settings**, so the two businesses are called whatever the company calls them.
 * Hardcoding "Software House" here would be a brand name compiled into an enum, which is exactly what
 * `CLAUDE.md` §1.8 forbids.
 */
enum FinanceContext: string
{
    use HasOptions;

    case SoftwareHouse = 'software_house';
    case Institute = 'institute';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::SoftwareHouse => (string) setting('company.short_name', 'Software house'),
            self::Institute => (string) setting('institute.name', 'Institute'),
            self::General => 'Shared / overheads',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SoftwareHouse => 'sky',
            self::Institute => 'violet',
            self::General => 'slate',
        };
    }
}
