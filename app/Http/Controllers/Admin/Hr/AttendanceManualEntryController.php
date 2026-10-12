<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Hr\StoreAttendanceManualEntryRequest;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Employee;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use App\Support\Format;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * "Record attendance" on the attendance register — HR entering an employee's day by hand.
 *
 * The same act as `AttendanceController::mark()` (an HR-direct correction, applied at once, with the reason
 * on the trail — HR-6), offered as a form on the register and made safe for one: the employee has to be
 * one this user may see, the day cannot be ahead of today or outside the employment, check-in and check-out
 * times may be given, and a day that payroll has locked is said on the form rather than failing.
 *
 * Its fields carry an `entry_` prefix so their old input and errors never collide with the register's own
 * filter and punch forms, which use `date` and `employee_id`.
 */
final class AttendanceManualEntryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function store(StoreAttendanceManualEntryRequest $request): RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validated();

        $employee = Employee::query()->find((int) $data['entry_employee_id']);

        if ($employee === null || ! $this->scopes->canSee($request->user(), $employee)) {
            throw ValidationException::withMessages(['entry_employee_id' => 'Pick an employee from the list.']);
        }

        $date = Carbon::parse($data['entry_date'])->startOfDay();

        if ($employee->joining_date !== null && $date->lessThan($employee->joining_date)) {
            throw ValidationException::withMessages([
                'entry_date' => sprintf('%s joined on %s.', $employee->name, app_date($employee->joining_date)),
            ]);
        }

        if ($employee->exit_date !== null && $date->greaterThan($employee->exit_date)) {
            throw ValidationException::withMessages([
                'entry_date' => sprintf('%s left on %s.', $employee->name, app_date($employee->exit_date)),
            ]);
        }

        // A row is locked only if it existed when payroll locked the month; a day first entered afterwards is
        // not. The month's summary carries the lock either way, so a paid month is refused as a whole (HR-18).
        $monthLocked = AttendanceMonthlySummary::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_year', (int) $date->year)
            ->where('period_month', (int) $date->month)
            ->whereNotNull('locked_at')
            ->exists();

        if ($monthLocked) {
            throw ValidationException::withMessages([
                'entry_date' => sprintf(
                    'Payroll has already locked %s for %s, so its attendance does not change. Correct the payroll with a correction run instead.',
                    app_date($date, 'F Y'),
                    $employee->name,
                ),
            ]);
        }

        $values = [
            'status' => $data['entry_status'],
            'payable_factor' => $data['entry_payable_factor'],
            'remarks' => $data['entry_remarks'] ?? null,
        ];

        $withTimes = ! empty($data['entry_check_in']);

        if ($withTimes) {
            // HR types the clock time of the business; stamps are stored UTC (D61).
            $values['check_in_at'] = Carbon::parse($date->toDateString().' '.$data['entry_check_in'], Format::timezone())->utc()->toDateTimeString();
            $values['check_out_at'] = Carbon::parse($date->toDateString().' '.$data['entry_check_out'], Format::timezone())->utc()->toDateTimeString();
        }

        try {
            $this->corrections->applyDirect(
                employee: $employee,
                date: $date,
                type: $withTimes ? AttendanceCorrectionType::WrongTime : AttendanceCorrectionType::StatusChange,
                newValues: $values,
                reason: $data['entry_reason'],
                actor: $request->user(),
            );
        } catch (LockedAttendanceException $exception) {
            throw ValidationException::withMessages(['entry_date' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            // The service's own refusals are keyed to its field names; this form only has its own.
            throw ValidationException::withMessages(['entry_reason' => collect($exception->errors())->flatten()->all()]);
        }

        return redirect()
            ->route('admin.attendance.index', ['date' => $date->toDateString()])
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s recorded for %s, with a correction row saying why.', $employee->name, app_date($date)),
            ]);
    }
}
