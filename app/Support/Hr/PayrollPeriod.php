<?php

declare(strict_types=1);

namespace App\Support\Hr;

use Illuminate\Support\Carbon;

/**
 * The month a payroll run pays for (phase-07 §6.6).
 *
 * A tiny object, but it removes the same three lines from six call sites and removes the chance that one
 * of them computes `endOfMonth()` on a date that had already been mutated.
 */
final readonly class PayrollPeriod
{
    public function __construct(
        public int $year,
        public int $month,
        public Carbon $start,
        public Carbon $end,
    ) {}

    public static function of(int $year, int $month): self
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return new self($year, $month, $start, $start->copy()->endOfMonth()->startOfDay());
    }

    public function calendarDays(): int
    {
        return $this->start->daysInMonth;
    }

    public function label(): string
    {
        return $this->start->format('F Y');
    }
}
