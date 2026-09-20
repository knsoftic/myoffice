<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\DayType;
use App\Enums\HolidayType;
use App\Models\Branch;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\WorkShift;
use App\Support\Hr\ShiftWindow;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What kind of day a date is, for one employee (phase-07 §6.1, §6.3, requirement §26).
 *
 * **One answer, asked in one place.** Attendance resolution, the leave day expansion, the payroll divisor
 * and the calendar screen all call this service rather than each deciding for itself what a weekend is.
 * Otherwise a business that moves its weekend to Friday has three definitions to update, finds the third
 * one six months later, and finds it inside a salary figure.
 *
 * The precedence is deliberate: a **public holiday beats a weekly off**. "The holiday fell on the weekend"
 * is a report somebody asks for, so the attendance row keeps `holiday_id` either way and only the
 * `day_type` collapses.
 *
 * An `optional` holiday never changes the day type ({@see HolidayType::changesDayType()}): it
 * belongs on the calendar as information, not as a paid day off nobody approved.
 *
 * Holidays for a window are loaded **once** by {@see preload()}: resolving a month for fifty people asks
 * this question fifteen hundred times, and fifteen hundred queries is how a nightly job becomes a
 * complaint.
 */
class WorkCalendarService
{
    /** Holidays already looked up, keyed `branch:date`; the value is null when the lookup found nothing. */
    private array $holidays = [];

    /** Dates whose holidays are known in full, so a miss is an answer rather than a reason to query. */
    private array $loadedDates = [];

    /**
     * Load every active holiday in a window up front.
     *
     * Call it before looping a period. Skipping it is not wrong — the per-date lookup still answers — it
     * is only slower.
     */
    public function preload(Carbon $from, Carbon $to): void
    {
        $rows = Holiday::query()
            ->where('is_active', true)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($this->datesIn($from, $to) as $date) {
            $this->loadedDates[$date->toDateString()] = true;
        }

        foreach ($rows as $holiday) {
            $this->holidays[$this->key($holiday->branch_id, $holiday->holiday_date)] = $holiday;
        }
    }

    /**
     * Drop everything cached — after a holiday was added or removed inside the same request.
     */
    public function forget(): void
    {
        $this->holidays = [];
        $this->loadedDates = [];
    }

    /*
    |--------------------------------------------------------------------------
    | The questions the rest of the phase asks
    |--------------------------------------------------------------------------
    */

    /**
     * The §6.3 question: is this a working day, a weekly off, or a public holiday?
     */
    public function dayTypeFor(Employee $employee, Carbon $date): DayType
    {
        $holiday = $this->holidayFor($employee, $date);

        if ($holiday !== null && $holiday->closesTheOffice()) {
            return DayType::PublicHoliday;
        }

        return $this->isWeeklyOff($employee, $date) ? DayType::WeeklyOff : DayType::Working;
    }

    /**
     * The holiday row that applies to this employee on this date, or null.
     *
     * A holiday with no branch applies everywhere; one naming a branch applies only there and **beats**
     * the global row for that branch, so a branch can keep its own regional day without the head office
     * losing its national one.
     *
     * The row is returned even when it is `optional` — the day type is unchanged, but the calendar screen
     * still has something to draw.
     */
    public function holidayFor(Employee $employee, Carbon $date): ?Holiday
    {
        foreach ([$employee->branch_id, null] as $branchId) {
            $holiday = $this->lookupHoliday($branchId, $date);

            if ($holiday !== null) {
                return $holiday;
            }
        }

        return null;
    }

    /**
     * Is this date one of the employee's weekly off days (their own override, their shift's, the
     * business-wide setting — {@see Employee::offDays()})?
     */
    public function isWeeklyOff(Employee $employee, Carbon $date): bool
    {
        $offDays = array_map(
            static fn ($day): string => strtolower(trim((string) $day)),
            $employee->offDays()
        );

        return in_array(strtolower($date->englishDayOfWeek), $offDays, true);
    }

    /**
     * The shift that governs this employee: their own, else their branch's default, else the
     * business-wide default. Null is a real answer (§6.10 #8) and the caller must handle it.
     *
     * The date is part of the signature because a future phase may date-stamp shift assignments; today
     * every assignment is current, and past rows are protected by their snapshot rather than by this
     * lookup (HR-2).
     */
    public function shiftFor(Employee $employee, ?Carbon $date = null): ?WorkShift
    {
        if ($employee->work_shift_id !== null) {
            return $employee->relationLoaded('workShift')
                ? $employee->workShift
                : $employee->workShift()->first();
        }

        return WorkShift::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->where(fn ($query) => $query
                ->where('branch_id', $employee->branch_id)
                ->orWhereNull('branch_id'))
            ->orderByRaw('CASE WHEN `branch_id` IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    /**
     * The window a day is measured against — the pair of moments plus the grace, break and expected
     * minutes an attendance row snapshots (HR-2, {@see ShiftWindow}).
     */
    public function expectedWindow(Employee $employee, Carbon $date): ShiftWindow
    {
        $shift = $this->shiftFor($employee, $date);

        return $shift === null
            ? ShiftWindow::unscheduled()
            : ShiftWindow::fromShift($shift, $date);
    }

    /**
     * How many **working** days this employee has between two dates, inclusive, as a decimal string.
     *
     * A decimal string rather than an int because it is a payroll divisor (`hr.payroll_day_basis =
     * working_days`) and every payroll figure goes through `Money` — an int here would be the one place a
     * divisor could arrive as a float.
     */
    public function workingDaysBetween(Employee $employee, Carbon $from, Carbon $to): string
    {
        $this->preloadOnce($from, $to);

        $days = 0;

        foreach ($this->datesIn($from, $to) as $date) {
            if ($this->dayTypeFor($employee, $date)->countsTowardWorkingDays()) {
                $days++;
            }
        }

        return Money::round((string) $days, 4);
    }

    /**
     * Every active holiday in a window, for a branch (its own rows plus the business-wide ones) or for
     * everybody when no branch is given — the calendar screen's query, and `HolidayService::copyYear()`'s.
     *
     * @return Collection<int, Holiday>
     */
    public function holidaysBetween(?Branch $branch, Carbon $from, Carbon $to): Collection
    {
        return Holiday::query()
            ->where('is_active', true)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->when($branch !== null, fn ($query) => $query
                ->where(fn ($scoped) => $scoped
                    ->where('branch_id', $branch->getKey())
                    ->orWhereNull('branch_id')))
            ->orderBy('holiday_date')
            ->get();
    }

    /**
     * Every date in a period, inclusive — the loop four services share rather than each writing their own
     * off-by-one.
     *
     * @return Collection<int, Carbon>
     */
    public function datesIn(Carbon $from, Carbon $to): Collection
    {
        $dates = collect();

        for ($date = $from->copy()->startOfDay(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $dates->push($date->copy());
        }

        return $dates;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function preloadOnce(Carbon $from, Carbon $to): void
    {
        if (! isset($this->loadedDates[$from->toDateString()], $this->loadedDates[$to->toDateString()])) {
            $this->preload($from, $to);
        }
    }

    private function lookupHoliday(?int $branchId, Carbon $date): ?Holiday
    {
        $key = $this->key($branchId, $date);

        if (array_key_exists($key, $this->holidays)) {
            return $this->holidays[$key];
        }

        if (isset($this->loadedDates[$date->toDateString()])) {
            return $this->holidays[$key] = null;
        }

        return $this->holidays[$key] = Holiday::query()
            ->where('is_active', true)
            ->whereDate('holiday_date', $date->toDateString())
            ->when(
                $branchId === null,
                fn ($query) => $query->whereNull('branch_id'),
                fn ($query) => $query->where('branch_id', $branchId)
            )
            ->first();
    }

    private function key(?int $branchId, Carbon|string $date): string
    {
        $date = $date instanceof Carbon ? $date->toDateString() : substr((string) $date, 0, 10);

        return ($branchId ?? 0).':'.$date;
    }
}
