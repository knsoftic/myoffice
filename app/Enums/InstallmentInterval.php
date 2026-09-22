<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Carbon\CarbonInterface;
use LogicException;

/**
 * How far apart an installment plan's due dates sit (phase-18 §3).
 *
 * **`Monthly` uses `addMonthsNoOverflow()`, and that is the whole reason this enum has a method.**
 * Carbon's plain `addMonth()` on 31 January gives 3 March, because it adds one to the month and then
 * lets the 31st overflow February. A plan built on the 31st would then have its second installment fall
 * in the *third* month and its schedule would drift a day further every time a short month went past.
 * `addMonthsNoOverflow()` clamps to the month end instead: 31 Jan → 28 Feb (29 in a leap year) → 31 Mar
 * → 30 Apr, which is what "monthly on the 31st" means to the person paying it.
 *
 * `Custom` throws rather than returning a date. A custom plan is one where the caller supplies every
 * date — there is no interval to step by, and a silent fallback to monthly would produce a schedule
 * nobody asked for and nobody would notice until the third due date was wrong.
 */
enum InstallmentInterval: string
{
    use HasOptions;

    /** One line per month, clamped to the month end. */
    case Monthly = 'monthly';

    /** One line every fourteen days. */
    case Fortnightly = 'fortnightly';

    /** One line every seven days. */
    case Weekly = 'weekly';

    /** Every due date is supplied by the caller; there is no step. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Fortnightly => 'Every two weeks',
            self::Weekly => 'Weekly',
            self::Custom => 'Custom dates',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Monthly => 'brand',
            self::Fortnightly => 'sky',
            self::Weekly => 'violet',
            self::Custom => 'slate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Monthly => 'One installment a month, on the same day — clamped to the month end, so a plan starting on the 31st falls on the 28th in February rather than slipping into March.',
            self::Fortnightly => 'One installment every 14 days.',
            self::Weekly => 'One installment every 7 days.',
            self::Custom => 'You pick each due date yourself.',
        };
    }

    /**
     * The nth step from a first due date, zero-based: `addTo($first, 0)` is the first line's own date.
     *
     * @throws LogicException for Custom, which has no step to take
     */
    public function addTo(CarbonInterface $date, int $n): CarbonInterface
    {
        if ($n < 0) {
            throw new LogicException('An installment plan steps forward from its first due date, never back.');
        }

        return match ($this) {
            // Clamped, not overflowed — see the class docblock.
            self::Monthly => $date->copy()->addMonthsNoOverflow($n),
            self::Fortnightly => $date->copy()->addDays($n * 14),
            self::Weekly => $date->copy()->addDays($n * 7),
            self::Custom => throw new LogicException(
                'A custom plan has no interval: every due date is supplied by the caller, and guessing '
                .'one here would build a schedule nobody asked for.',
            ),
        };
    }

    /** True when this interval can generate its own dates. */
    public function isRegular(): bool
    {
        return $this !== self::Custom;
    }

    /** The number of days between two lines, for the plan preview's "≈ every N days" caption. */
    public function approximateDays(): ?int
    {
        return match ($this) {
            self::Monthly => 30,
            self::Fortnightly => 14,
            self::Weekly => 7,
            self::Custom => null,
        };
    }
}
