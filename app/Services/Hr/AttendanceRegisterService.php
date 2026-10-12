<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Enums\DayType;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Models\User;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The employee register: one screen for a day, every employee on it, marked in one save — the HR
 * counterpart of the student register.
 *
 * **Every mark is an HR-direct correction** (`AttendanceCorrectionService::applyDirect()`, HR-6), the same
 * act as `AttendanceController::mark()`, so each changed day carries the reason on its trail and the
 * attendance-deduction observer sees the write like any other. A row the save would leave as it is is not
 * written at all, so saving a register twice adds nothing.
 *
 * **A paid month does not change (HR-18).** A row payroll locked, or any day of a month whose summary
 * payroll locked — a day first entered after the lock included — is shown on the register as locked and
 * is refused by the save, whatever the form posted.
 */
final class AttendanceRegisterService
{
    /** The marks the register offers, each with the payable share it is written with. */
    public const MARKS = [
        'present' => '1.0000',
        'late' => '1.0000',
        'half_day' => '0.5000',
        'absent' => '0.0000',
        'on_leave' => '1.0000',
    ];

    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly EmployeeScopeResolver $scopes,
        private readonly WorkCalendarService $calendar,
    ) {}

    /**
     * Everybody on the register for one day: the employees this user may see who were employed that day,
     * each with the row they already have, the day's type for them, and why they cannot be changed (null
     * when they can).
     *
     * @return Collection<int, array{employee: Employee, row: Attendance|null, day_type: DayType, locked: string|null}>
     */
    public function sheet(User $user, Carbon $date, ?int $departmentId = null): Collection
    {
        $day = $date->copy()->startOfDay();

        $query = Employee::query()
            ->with(['department:id,name', 'workShift:id,name,weekly_off_days'])
            ->whereDate('joining_date', '<=', $day->toDateString())
            ->where(fn ($inner) => $inner
                ->whereNull('exit_date')
                ->orWhereDate('exit_date', '>=', $day->toDateString()))
            ->when($departmentId !== null, fn ($inner) => $inner->where('department_id', $departmentId))
            ->orderBy('name');

        $this->scopes->apply($query, $user, 'id');

        $employees = $query->get();

        // One row per employee per date (`AttendanceService::rowFor()`), so the employee count bounds it.
        $rows = Attendance::query()
            ->with('lockedByRun:id,run_number')
            ->whereDate('attendance_date', $day->toDateString())
            ->whereIn('employee_id', $employees->modelKeys())
            ->limit(max(1, $employees->count()))
            ->get()
            ->keyBy('employee_id');

        $monthLocks = $this->monthLocks($employees->modelKeys(), $day);

        $this->calendar->preload($day, $day);

        return $employees->map(function (Employee $employee) use ($rows, $monthLocks, $day): array {
            /** @var Attendance|null $row */
            $row = $rows->get($employee->getKey());

            return [
                'employee' => $employee,
                'row' => $row,
                'day_type' => $row?->day_type ?? $this->calendar->dayTypeFor($employee, $day),
                'locked' => $this->lockReason($row, $monthLocks[$employee->getKey()] ?? null),
            ];
        })->values();
    }

    /**
     * Which of these employees have the month of `$date` locked by payroll, and by which run.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, string> employee id => run number
     */
    public function monthLocks(array $employeeIds, Carbon $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return AttendanceMonthlySummary::query()
            ->with('lockedByRun:id,run_number')
            ->whereIn('employee_id', $employeeIds)
            ->where('period_year', (int) $date->year)
            ->where('period_month', (int) $date->month)
            ->whereNotNull('locked_at')
            ->get()
            ->mapWithKeys(fn (AttendanceMonthlySummary $summary): array => [
                (int) $summary->employee_id => (string) ($summary->lockedByRun?->run_number ?? 'payroll'),
            ])
            ->all();
    }

    /**
     * Save a register. `$marks` is `[employee_id => ['status' => ..., 'check_in' => 'H:i'?, 'check_out' =>
     * 'H:i'?, 'remarks' => ...?]]`; a blank status means "leave this employee alone".
     *
     * @param  array<int|string, array<string, mixed>>  $marks
     * @return array{marked: int, unchanged: int, skipped: list<string>}
     */
    public function save(User $actor, Carbon $date, array $marks, string $reason): array
    {
        $day = $date->copy()->startOfDay();
        $sheet = $this->sheet($actor, $day)->keyBy(fn (array $entry): int => (int) $entry['employee']->getKey());
        $report = ['marked' => 0, 'unchanged' => 0, 'skipped' => []];

        DB::transaction(function () use ($actor, $day, $marks, $reason, $sheet, &$report): void {
            foreach ($marks as $employeeId => $mark) {
                $status = (string) ($mark['status'] ?? '');

                if ($status === '' || ! array_key_exists($status, self::MARKS)) {
                    continue;
                }

                $entry = $sheet->get((int) $employeeId);

                if ($entry === null) {
                    // Not one this user may see, or not employed on the day: never written, whatever was posted.
                    $report['skipped'][] = sprintf('employee #%d is not on this register', (int) $employeeId);

                    continue;
                }

                if ($entry['locked'] !== null) {
                    $report['skipped'][] = sprintf('%s (%s)', $entry['employee']->name, $entry['locked']);

                    continue;
                }

                $values = $this->valuesFor($day, $status, $mark, $entry['row']);

                if ($values === null) {
                    $report['unchanged']++;

                    continue;
                }

                // The sheet loads a slim shift for display; a write snapshots the whole shift onto the row.
                $employee = Employee::query()->with('workShift')->findOrFail($entry['employee']->getKey());

                $this->corrections->applyDirect(
                    employee: $employee,
                    date: $day,
                    type: isset($values['check_in_at']) || isset($values['check_out_at'])
                        ? AttendanceCorrectionType::WrongTime
                        : AttendanceCorrectionType::StatusChange,
                    newValues: $values,
                    reason: $reason,
                    actor: $actor,
                );

                $report['marked']++;
            }
        });

        return $report;
    }

    /**
     * What to write for one employee, or null when the register would leave their row exactly as it is.
     *
     * @param  array<string, mixed>  $mark
     * @return array<string, mixed>|null
     */
    private function valuesFor(Carbon $day, string $status, array $mark, ?Attendance $row): ?array
    {
        $payable = self::MARKS[$status];
        $values = [];

        if ($row === null || $row->status->value !== $status || Money::compare((string) $row->payable_factor, $payable) !== 0) {
            $values['status'] = $status;
            $values['payable_factor'] = $payable;
        }

        // Times are typed in the business's clock and stored UTC (D61), so both sides compare in the former.
        $zone = Format::timezone();

        foreach (['check_in' => 'check_in_at', 'check_out' => 'check_out_at'] as $field => $column) {
            $time = trim((string) ($mark[$field] ?? ''));

            if ($time === '' || $row?->{$column}?->copy()->setTimezone($zone)->format('H:i') === $time) {
                continue;
            }

            $values[$column] = Carbon::parse($day->toDateString().' '.$time, $zone)->utc()->toDateTimeString();
        }

        $remarks = trim((string) ($mark['remarks'] ?? ''));

        if ($remarks !== '' && $remarks !== (string) $row?->remarks) {
            $values['remarks'] = $remarks;
        }

        if ($values === []) {
            return null;
        }

        // A change of time or note alone still writes the mark it was made under.
        return $values + ['status' => $status, 'payable_factor' => $payable];
    }

    private function lockReason(?Attendance $row, ?string $monthRun): ?string
    {
        if ($row?->isLocked()) {
            return sprintf('locked by payroll run %s', $row->lockedByRun?->run_number ?? '');
        }

        if ($monthRun !== null) {
            return sprintf('month locked by payroll run %s', $monthRun);
        }

        return null;
    }
}
