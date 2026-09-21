<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The five columns of the receivables aging report (phase-13 §6.7).
 *
 * Defined once so the SQL, the CSV and the screen cannot disagree about where a 60-day-old invoice
 * belongs. `matches()` is the only place a boundary is written down, and `range()` is what a CASE
 * expression is built from — so moving a boundary is one edit rather than three that have to agree.
 *
 * `current` covers everything not yet overdue, including an invoice due tomorrow: a claim that is not
 * late is not aged.
 */
enum AgingBucket: string
{
    use HasOptions;

    case Current = 'current';
    case D1To30 = 'd1_30';
    case D31To60 = 'd31_60';
    case D61To90 = 'd61_90';
    case D90Plus = 'd90_plus';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Not yet due',
            self::D1To30 => '1–30 days',
            self::D31To60 => '31–60 days',
            self::D61To90 => '61–90 days',
            self::D90Plus => 'Over 90 days',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Current => 'slate',
            self::D1To30 => 'sky',
            self::D31To60 => 'amber',
            self::D61To90 => 'orange',
            self::D90Plus => 'rose',
        };
    }

    /**
     * `[from, to]` in days overdue; `to` of null means open-ended.
     *
     * @return array{0: int, 1: int|null}
     */
    public function range(): array
    {
        return match ($this) {
            self::Current => [PHP_INT_MIN, 0],
            self::D1To30 => [1, 30],
            self::D31To60 => [31, 60],
            self::D61To90 => [61, 90],
            self::D90Plus => [91, null],
        };
    }

    public function matches(int $daysOverdue): bool
    {
        [$from, $to] = $this->range();

        return $daysOverdue >= $from && ($to === null || $daysOverdue <= $to);
    }

    /**
     * Which bucket a given age falls in. One function, so nothing has to reimplement the ladder.
     */
    public static function forDays(int $daysOverdue): self
    {
        foreach (self::cases() as $bucket) {
            if ($bucket->matches($daysOverdue)) {
                return $bucket;
            }
        }

        return self::D90Plus;
    }
}
