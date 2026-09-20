<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Models\Hr\Attendance;
use App\Models\Hr\Holiday;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The holiday calendar (phase-07 §2.8, §6.2, [D-HR-6]).
 *
 * **One row per date, never a range.** The attendance engine asks "is this date a holiday?" once per
 * employee per day; a stored range would turn that lookup into a scan and would make the per-date unique
 * guard impossible. A user still enters a range — this service expands it.
 *
 * **Declaring a holiday late is a real case** (§6.10 #3). Every affected attendance row is re-resolved and
 * each change writes a `holiday_recalculation` correction row with the values before and after, so a day
 * that turned from `absent` into `holiday` has an explanation attached to it. A date already **locked** by
 * payroll refuses the whole change: the month is paid.
 */
class HolidayService
{
    public function __construct(
        private readonly WorkCalendarService $calendar,
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrectionService $corrections,
    ) {}

    /**
     * Create one row per date in a range ([D-HR-6]).
     *
     * A date that already has a holiday is **reported, not duplicated** — the message names the existing
     * one, because "it is already there" is a better answer than a constraint violation.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Holiday>
     */
    public function createRange(array $data, Carbon $from, ?Carbon $to = null): Collection
    {
        $to ??= $from->copy();

        if ($to->lessThan($from)) {
            throw HrRuleException::refuse('to_date', 'The last day cannot be before the first.');
        }

        return DB::transaction(function () use ($data, $from, $to): Collection {
            $created = collect();

            foreach ($this->calendar->datesIn($from, $to) as $date) {
                $holiday = new Holiday;
                $holiday->fill($data);
                $holiday->forceFill(['holiday_date' => $date->toDateString()]);

                try {
                    $holiday->save();
                } catch (UniqueConstraintViolationException) {
                    $existing = Holiday::query()
                        ->whereDate('holiday_date', $date->toDateString())
                        ->where('is_active', true)
                        ->when(($data['branch_id'] ?? null) === null,
                            fn ($query) => $query->whereNull('branch_id'),
                            fn ($query) => $query->where('branch_id', $data['branch_id']))
                        ->first();

                    throw HrRuleException::refuse('holiday_date', sprintf(
                        '%s is already a holiday: %s. Edit that one rather than adding a second.',
                        $date->toDateString(),
                        $existing?->title ?? '(an existing holiday)'
                    ));
                }

                $created->push($holiday);
            }

            $this->calendar->forget();
            $this->recomputeRange($from, $to);

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Holiday $holiday, array $data): Holiday
    {
        $this->assertDateUnlocked($holiday->holiday_date->copy());

        return DB::transaction(function () use ($holiday, $data): Holiday {
            $holiday->fill($data)->save();

            $this->calendar->forget();
            $this->recomputeRange($holiday->holiday_date->copy(), $holiday->holiday_date->copy());

            return $holiday;
        });
    }

    /**
     * Copy a year's recurring holidays forward. Idempotent — a date that already exists is skipped,
     * never duplicated, so running it twice in January costs nothing.
     */
    public function copyYear(int $fromYear, int $toYear): int
    {
        $source = Holiday::query()
            ->where('is_recurring_yearly', true)
            ->whereYear('holiday_date', $fromYear)
            ->get();

        $copied = 0;

        foreach ($source as $holiday) {
            $date = $holiday->holiday_date->copy()->setYear($toYear);

            $exists = Holiday::query()
                ->whereDate('holiday_date', $date->toDateString())
                ->when($holiday->branch_id === null,
                    fn ($query) => $query->whereNull('branch_id'),
                    fn ($query) => $query->where('branch_id', $holiday->branch_id))
                ->exists();

            if ($exists) {
                continue;
            }

            $copy = new Holiday;
            $copy->forceFill([
                'branch_id' => $holiday->branch_id,
                'holiday_date' => $date->toDateString(),
                'title' => $holiday->title,
                'holiday_type' => $holiday->holiday_type,
                'is_paid' => $holiday->is_paid,
                'is_recurring_yearly' => true,
                'description' => $holiday->description,
                'is_active' => true,
            ])->save();

            $copied++;
        }

        $this->calendar->forget();

        return $copied;
    }

    /**
     * Remove a holiday and put the affected days back the way they were.
     */
    public function destroy(Holiday $holiday, string $reason): void
    {
        if (trim($reason) === '') {
            throw HrRuleException::reasonRequired('reason',
                'Say why the holiday is being removed — everybody who was marked "holiday" that day is '
                .'about to be re-judged from their punches.');
        }

        $date = $holiday->holiday_date->copy();
        $this->assertDateUnlocked($date);

        DB::transaction(function () use ($holiday, $date, $reason): void {
            $holiday->delete();

            $this->calendar->forget();
            $this->recomputeRange($date, $date, sprintf('Holiday removed: %s', trim($reason)));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Re-resolve every affected row and explain each change (§6.10 #3).
     */
    private function recomputeRange(Carbon $from, Carbon $to, ?string $reason = null): int
    {
        $changed = 0;

        foreach ($this->calendar->datesIn($from, $to) as $date) {
            $rows = Attendance::query()
                ->with('employee')
                ->whereDate('attendance_date', $date->toDateString())
                ->whereNull('locked_at')
                ->where('is_manual', false)
                ->get();

            foreach ($rows as $row) {
                $before = [
                    'status' => $row->status->value,
                    'day_type' => $row->day_type->value,
                    'payable_factor' => (string) $row->payable_factor,
                    'holiday_id' => $row->holiday_id,
                ];

                $this->attendance->resolve($row);

                $after = [
                    'status' => $row->status->value,
                    'day_type' => $row->day_type->value,
                    'payable_factor' => (string) $row->payable_factor,
                    'holiday_id' => $row->holiday_id,
                ];

                if ($before === $after) {
                    continue;
                }

                $this->corrections->recordSystemChange(
                    row: $row,
                    type: AttendanceCorrectionType::HolidayRecalculation,
                    oldValues: $before,
                    newValues: $after,
                    reason: $reason ?? sprintf(
                        'The holiday calendar changed for %s, so this day was re-resolved.',
                        $date->toDateString()
                    ),
                );

                $changed++;
            }
        }

        return $changed;
    }

    private function assertDateUnlocked(Carbon $date): void
    {
        $locked = Attendance::query()
            ->with('lockedByRun')
            ->whereDate('attendance_date', $date->toDateString())
            ->whereNotNull('locked_at')
            ->first();

        if ($locked !== null) {
            throw LockedAttendanceException::forPeriod(
                sprintf('Attendance for %s', $date->toDateString()),
                $locked->lockedByRun?->run_number ?? '(unknown run)'
            );
        }
    }
}
