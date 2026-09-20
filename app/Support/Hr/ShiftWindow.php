<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Models\Hr\WorkShift;
use Illuminate\Support\Carbon;

/**
 * The window one attendance row is measured against (phase-07 §6.1, HR-2).
 *
 * **This is what gets snapshotted.** `attendances` stores every one of these numbers on the row at
 * creation instead of joining to `work_shifts`, so editing a shift — or deleting it — can never rewrite
 * how late somebody was three months ago. The value object exists so that the snapshot is assembled in
 * exactly one place and cannot drift between the check-in path, the day-close job and the importer.
 *
 * **A null shift is a real case** (§6.10 #8). An employee with no shift and no branch default still gets a
 * window: the expected in/out pair is null — late minutes are meaningless without one, and the row says so
 * rather than inventing a 09:00 — while the thresholds fall back to the `hr.*` settings so the day can
 * still be judged full, half or absent from the minutes actually worked.
 */
final readonly class ShiftWindow
{
    public function __construct(
        public ?int $workShiftId,
        public ?Carbon $expectedIn,
        public ?Carbon $expectedOut,
        public int $expectedMinutes,
        public int $graceInMinutes,
        public int $graceOutMinutes,
        public int $breakMinutes,
        public int $minHalfDayMinutes,
        public int $minFullDayMinutes,
    ) {}

    /**
     * Build the window a shift implies for one date.
     *
     * A shift that ends at or before it starts runs through midnight, so its end belongs to the **next**
     * day — which is why a 02:00 punch-out is this row's check-out and never a row of its own (§6.3).
     */
    public static function fromShift(WorkShift $shift, Carbon $date): self
    {
        $in = $date->copy()->startOfDay()->setTimeFromTimeString((string) $shift->start_time);
        $out = $date->copy()->startOfDay()->setTimeFromTimeString((string) $shift->end_time);

        if ($out->lessThanOrEqualTo($in)) {
            $out->addDay();
        }

        return new self(
            workShiftId: (int) $shift->getKey(),
            expectedIn: $in,
            expectedOut: $out,
            expectedMinutes: (int) $shift->expected_minutes,
            graceInMinutes: (int) $shift->grace_in_minutes,
            graceOutMinutes: (int) $shift->grace_out_minutes,
            breakMinutes: (int) $shift->break_minutes,
            minHalfDayMinutes: (int) $shift->min_half_day_minutes,
            minFullDayMinutes: (int) $shift->min_full_day_minutes,
        );
    }

    /**
     * The window for somebody with no shift at all (§6.10 #8) — thresholds from settings, no clock.
     */
    public static function unscheduled(): self
    {
        return new self(
            workShiftId: null,
            expectedIn: null,
            expectedOut: null,
            expectedMinutes: (int) setting('hr.full_day_min_minutes', 480),
            graceInMinutes: (int) setting('hr.late_grace_minutes', 15),
            graceOutMinutes: (int) setting('hr.early_leave_grace_minutes', 10),
            breakMinutes: 0,
            minHalfDayMinutes: (int) setting('hr.half_day_min_minutes', 240),
            minFullDayMinutes: (int) setting('hr.full_day_min_minutes', 480),
        );
    }

    /**
     * Is there a clock to measure lateness against? When there is not, late and early-leave minutes stay
     * zero rather than being derived from a guess.
     */
    public function hasClock(): bool
    {
        return $this->expectedIn !== null && $this->expectedOut !== null;
    }

    /**
     * The moment after which an arrival is late — the expected start plus its grace.
     */
    public function lateAfter(): ?Carbon
    {
        return $this->expectedIn?->copy()->addMinutes($this->graceInMinutes);
    }

    /**
     * The moment before which a departure is an early leave — the expected end minus its grace.
     */
    public function earlyBefore(): ?Carbon
    {
        return $this->expectedOut?->copy()->subMinutes($this->graceOutMinutes);
    }

    /**
     * The columns an `attendances` row snapshots from this window (HR-2).
     *
     * @return array<string, int|string|null>
     */
    public function toAttendanceColumns(): array
    {
        return [
            'work_shift_id' => $this->workShiftId,
            'expected_in_at' => $this->expectedIn?->toDateTimeString(),
            'expected_out_at' => $this->expectedOut?->toDateTimeString(),
            'expected_minutes' => $this->expectedMinutes,
            'grace_in_minutes' => $this->graceInMinutes,
            'grace_out_minutes' => $this->graceOutMinutes,
            'break_minutes' => $this->breakMinutes,
        ];
    }

    /**
     * Rebuild the window from a row that already carries the snapshot, so `resolve()` measures against
     * what the day was actually promised rather than against today's shift definition.
     *
     * The two **thresholds** are not on the row: §2.9 snapshots the clock, not the full/half-day minimums,
     * because those are a policy the business may restate for an open month. The caller therefore passes
     * them in from the live shift when one still exists, and they fall back to the `hr.*` settings when it
     * does not — a deleted shift must not make every day in its history unjudgeable.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromSnapshot(array $row): self
    {
        $shift = $row['work_shift_id'] ?? null;

        return new self(
            workShiftId: $shift === null ? null : (int) $shift,
            expectedIn: isset($row['expected_in_at']) && $row['expected_in_at'] !== null
                ? Carbon::parse((string) $row['expected_in_at']) : null,
            expectedOut: isset($row['expected_out_at']) && $row['expected_out_at'] !== null
                ? Carbon::parse((string) $row['expected_out_at']) : null,
            expectedMinutes: (int) ($row['expected_minutes'] ?? 0),
            graceInMinutes: (int) ($row['grace_in_minutes'] ?? 0),
            graceOutMinutes: (int) ($row['grace_out_minutes'] ?? 0),
            breakMinutes: (int) ($row['break_minutes'] ?? 0),
            minHalfDayMinutes: (int) ($row['min_half_day_minutes'] ?? setting('hr.half_day_min_minutes', 240)),
            minFullDayMinutes: (int) ($row['min_full_day_minutes'] ?? setting('hr.full_day_min_minutes', 480)),
        );
    }
}
