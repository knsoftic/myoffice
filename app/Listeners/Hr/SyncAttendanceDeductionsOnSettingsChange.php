<?php

declare(strict_types=1);

namespace App\Listeners\Hr;

use App\Events\SettingsChanged;
use App\Services\Hr\AttendanceDeductionService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A deduction rule switched on or off, or its percentage or allowance changed: re-sync the months that are
 * still open, so pending deductions appear, move or are reversed straight away rather than at payroll.
 *
 * The current and the previous month are re-read for every employee; any other month is re-read only where a
 * pending or automatically reversed row sits in it, so a rule switched off leaves no pending row behind and
 * switched back on restores it. A period payroll locked is refused by `syncMonth()`, and applied deductions
 * are never changed by this.
 */
final class SyncAttendanceDeductionsOnSettingsChange
{
    /** The settings that change what the rules decide. */
    public const KEYS = [
        'hr.late_arrival_deduction_enabled',
        'hr.late_arrival_deduction_percent',
        'hr.excess_absence_deduction_enabled',
        'hr.monthly_absence_allowance_days',
        'hr.payroll_day_basis',
        'hr.lop_basis',
    ];

    public function __construct(
        private readonly AttendanceDeductionService $deductions,
    ) {}

    public function handle(SettingsChanged $event): void
    {
        if (array_intersect($event->keys, self::KEYS) === []) {
            return;
        }

        $now = Carbon::now();
        $synced = [];

        foreach ([$now->copy()->subMonthNoOverflow(), $now] as $month) {
            $synced[] = [(int) $month->year, (int) $month->month];

            try {
                $this->deductions->syncPeriod((int) $month->year, (int) $month->month);
            } catch (Throwable $exception) {
                // A settings save never fails because of this; payroll generation re-syncs regardless.
                report($exception);
            }
        }

        try {
            // A recorded row in any other open month (a late mark entered ahead of the month) follows too.
            $this->deductions->syncRecordedOutside($synced);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
