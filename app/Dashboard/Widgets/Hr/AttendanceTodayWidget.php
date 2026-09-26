<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Hr;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Models\Hr\Employee;
use App\Support\DateRange;
use App\Support\Format;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Who is in today, and who nobody has accounted for yet (requirement §26, HR-1, T57).
 *
 * **Today, whatever the range selector says.** Attendance is a state of the morning: switching the
 * dashboard to "this quarter" must not turn the register into a statistic, because the only useful
 * answer to "who is in?" is about the day that is happening.
 *
 * **It counts from `employees`, not from `attendances`.** A row is only born when somebody punches
 * or when the daily close runs, so counting attendance rows answers "who has been marked", which is
 * a completely different question and is silently reassuring on the exact morning the register was
 * never opened. Starting from the people expected in and joining the day's row lets the card report
 * the number HR actually needs: **how many have no row at all**.
 *
 * **One query.** A LEFT JOIN from the roll to today's rows, aggregated with conditional sums in a
 * single pass — `GET /admin` is measured against a query ceiling, and "one query per figure" is how
 * it gets broken one reasonable card at a time. `attendance_date` is a `date` column, so it is
 * compared against a plain date string inside the join: `whereDate()` on a DATE column throws the
 * `(employee_id, attendance_date)` index away.
 *
 * The join carries its own `deleted_at IS NULL`, because a base-builder join does not inherit the
 * soft-delete scope the way the model's own query does.
 *
 * Every state comes from {@see AttendanceStatus} — `countsAsWorkedDay()` decides what "in" means,
 * so the register screen and this card cannot drift apart (golden rule 8). "Today" is
 * `now()` in the application timezone, exactly as `AttendanceController` resolves its default date,
 * so the card can never disagree with the screen it links to.
 */
final class AttendanceTodayWidget extends Widget
{
    public function key(): string
    {
        return 'hr_attendance_today';
    }

    public function title(): string
    {
        return 'Attendance today';
    }

    public function icon(): string
    {
        return 'calendar-days';
    }

    public function permission(): ?string
    {
        return 'attendance.view_any';
    }

    public function module(): ?string
    {
        return 'attendance';
    }

    public function group(): string
    {
        return WidgetGroup::PEOPLE;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.attendance.index', [
            'date' => Carbon::now(Format::timezone())->toDateString(),
        ]);
    }

    public function emptyMessage(): ?string
    {
        return 'Nobody is expected in today.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $today = Carbon::now(Format::timezone())->toDateString();

        $onRoll = self::employeeStatusValues();
        $worked = self::attendanceStatusValues(
            static fn (AttendanceStatus $status): bool => $status->countsAsWorkedDay(),
        );

        try {
            $row = Employee::query()
                ->toBase()
                ->leftJoin('attendances', static function (JoinClause $join) use ($today): void {
                    $join->on('attendances.employee_id', '=', 'employees.id')
                        ->where('attendances.attendance_date', '=', $today)
                        ->whereNull('attendances.deleted_at');
                })
                ->whereIn('employees.status', $onRoll)
                // Somebody who starts next Monday is not absent today.
                ->where('employees.joining_date', '<=', $today)
                ->selectRaw(
                    'COUNT(*) as expected,'
                    .' SUM(CASE WHEN '.self::inList('attendances.status', $worked).' THEN 1 ELSE 0 END) as in_today,'
                    .' SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as present,'
                    .' SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as late,'
                    .' SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as absent,'
                    .' SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as on_leave,'
                    .' SUM(CASE WHEN attendances.status = ? THEN 1 ELSE 0 END) as on_holiday,'
                    .' SUM(CASE WHEN attendances.id IS NULL THEN 1 ELSE 0 END) as unmarked',
                    [
                        ...$worked,
                        AttendanceStatus::Present->value,
                        AttendanceStatus::Late->value,
                        AttendanceStatus::Absent->value,
                        AttendanceStatus::OnLeave->value,
                        AttendanceStatus::Holiday->value,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            // Everybody on the roll who had already joined by today — the denominator, and the
            // figure the empty state is decided on.
            'expected' => (int) ($row->expected ?? 0),
            // Present, late, early-leave and half day: somebody who came in late is still in.
            'in_today' => (int) ($row->in_today ?? 0),
            'present' => (int) ($row->present ?? 0),
            'late' => (int) ($row->late ?? 0),
            'absent' => (int) ($row->absent ?? 0),
            'on_leave' => (int) ($row->on_leave ?? 0),
            'on_holiday' => (int) ($row->on_holiday ?? 0),
            // No row at all for today — not "absent", *unaccounted for*, which is the one number
            // on this card that is somebody's job this morning.
            'unmarked' => (int) ($row->unmarked ?? 0),
            'date' => Carbon::parse($today),
        ];
    }

    /**
     * The statuses of the people a register is expected to account for — `active` and `probation`,
     * as {@see EmployeeStatus::isPayrollEligible()} defines them.
     *
     * @return list<string>
     */
    private static function employeeStatusValues(): array
    {
        return array_values(array_map(
            static fn (EmployeeStatus $status): string => $status->value,
            array_filter(
                EmployeeStatus::cases(),
                static fn (EmployeeStatus $status): bool => $status->isPayrollEligible(),
            ),
        ));
    }

    /**
     * @param  callable(AttendanceStatus): bool  $predicate
     * @return list<string>
     */
    private static function attendanceStatusValues(callable $predicate): array
    {
        return array_values(array_map(
            static fn (AttendanceStatus $status): string => $status->value,
            array_filter(AttendanceStatus::cases(), $predicate),
        ));
    }

    /**
     * `col IN (?, ?)` with one placeholder per value, and a false condition for an empty list —
     * `IN ()` is a syntax error, not an empty result.
     *
     * @param  list<string>  $values
     */
    private static function inList(string $column, array $values): string
    {
        if ($values === []) {
            return '1 = 0';
        }

        return $column.' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
    }
}
