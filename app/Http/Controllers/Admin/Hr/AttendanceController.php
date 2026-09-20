<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\WorkCalendarService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance — `admin.attendance.*` (phase-07 §7.3, §8.9–8.11), `module:attendance`.
 *
 * The daily register is the screen HR lives on. It shows **every** payroll-eligible employee for a date,
 * including the ones with no row yet, because a missing row is exactly what somebody needs to notice.
 *
 * Nothing here writes a status directly: a manual change goes through `AttendanceCorrectionService`, so
 * every figure that moved has a row saying who moved it and why (HR-6).
 */
final class AttendanceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrectionService $corrections,
        private readonly WorkCalendarService $calendar,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    /**
     * The daily register (§8.9).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Attendance::class);

        $date = $this->dateFrom($request, 'date');

        $employees = $this->visibleEmployees($request)
            ->whereDate('joining_date', '<=', $date->toDateString())
            ->when($request->filled('department_id'), fn ($query) => $query
                ->where('department_id', $request->integer('department_id')))
            ->orderBy('name')
            ->get();

        $rows = Attendance::query()
            ->whereDate('attendance_date', $date->toDateString())
            ->whereIn('employee_id', $employees->modelKeys())
            ->get()
            ->keyBy('employee_id');

        $this->calendar->preload($date, $date);

        return view('admin.hr.attendance.index', [
            'date' => $date,
            'employees' => $employees,
            'rows' => $rows,
            'dayTypes' => $employees->mapWithKeys(fn (Employee $employee): array => [
                $employee->getKey() => $this->calendar->dayTypeFor($employee, $date),
            ]),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => AttendanceStatus::options(),
            'sources' => AttendanceSource::options(),
            'canMark' => (bool) $request->user()?->can('attendance.create'),
            'canCorrect' => (bool) $request->user()?->can('attendance.edit'),
        ]);
    }

    /**
     * The monthly grid (§8.10) — one row per employee, one cell per day.
     */
    public function monthly(Request $request): View
    {
        $this->authorize('viewAny', Attendance::class);

        $month = $this->dateFrom($request, 'month')->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $employees = $this->visibleEmployees($request)
            ->whereDate('joining_date', '<=', $end->toDateString())
            ->when($request->filled('department_id'), fn ($query) => $query
                ->where('department_id', $request->integer('department_id')))
            ->orderBy('name')
            ->get();

        $rows = Attendance::query()
            ->whereBetween('attendance_date', [$month->toDateString(), $end->toDateString()])
            ->whereIn('employee_id', $employees->modelKeys())
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => $group->keyBy(fn (Attendance $row): string => $row->attendance_date->toDateString()));

        return view('admin.hr.attendance.monthly', [
            'month' => $month,
            'dates' => $this->calendar->datesIn($month, $end),
            'employees' => $employees,
            'rows' => $rows,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * One day's detail — the punches, the snapshot it was judged against, and its corrections (§8.11).
     */
    public function show(Attendance $attendance): View
    {
        $this->authorize('view', $attendance);

        $attendance->load(['employee:id,name,employee_code,department_id', 'workShift:id,name', 'holiday', 'leaveType', 'lockedByRun', 'corrections.requester']);

        return view('admin.hr.attendance.show', [
            'attendance' => $attendance,
            'statuses' => AttendanceStatus::options(),
            'correctionTypes' => AttendanceCorrectionType::options(),
        ]);
    }

    /**
     * A kiosk or admin punch on somebody's behalf (§7.3).
     */
    public function punch(Request $request): RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'direction' => ['required', 'string', Rule::in(['in', 'out'])],
            'at' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
        ]);

        $employee = Employee::query()->with('workShift')->findOrFail($data['employee_id']);
        $at = isset($data['at']) ? Carbon::parse($data['at']) : now();
        $date = isset($data['date']) ? Carbon::parse($data['date']) : null;

        $row = $data['direction'] === 'in'
            ? $this->attendance->checkIn($employee, $at, AttendanceSource::Admin, $request->ip(), $date)
            : $this->attendance->checkOut($employee, $at, AttendanceSource::Admin, $request->ip(), $date);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s %s at %s.',
                $employee->name,
                $data['direction'] === 'in' ? 'checked in' : 'checked out',
                $at->format('H:i')
            ),
        ]);
    }

    /**
     * Mark a day by hand — always through a correction, so the trail exists either way (HR-6).
     */
    public function mark(Request $request): RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'payable_factor' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = Employee::query()->with('workShift')->findOrFail($data['employee_id']);

        $this->corrections->applyDirect(
            employee: $employee,
            date: Carbon::parse($data['attendance_date']),
            type: AttendanceCorrectionType::StatusChange,
            newValues: [
                'status' => $data['status'],
                'payable_factor' => $data['payable_factor'],
                'remarks' => $data['remarks'] ?? null,
            ],
            reason: $data['reason'],
            actor: $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s marked, with a correction row saying why.', $employee->name),
        ]);
    }

    /**
     * Re-run §6.3 for a row whose inputs changed.
     */
    public function recompute(Attendance $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        if ($attendance->is_manual) {
            return back()->with('toast', [
                'type' => 'info',
                'message' => 'This day was set by a correction, so recomputing leaves it alone. File a new correction to change it.',
            ]);
        }

        $this->attendance->resolve($attendance);

        return back()->with('toast', ['type' => 'success', 'message' => 'Day recomputed from its punches.']);
    }

    /**
     * Close a date: create the missing rows for everybody and resolve them. Idempotent.
     */
    public function close(Request $request): RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validate(['date' => ['required', 'date']]);
        $report = $this->attendance->closeDay(Carbon::parse($data['date']));

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%d row(s) created, %d resolved, %d flagged for correction.',
                $report['created'],
                $report['resolved'],
                $report['flagged'],
            ),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        $this->authorize('export', Attendance::class);

        abort_unless($format === 'csv', 404);

        $month = $this->dateFrom($request, 'month')->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $employees = $this->visibleEmployees($request)->pluck('id');

        $rows = Attendance::query()
            ->with('employee:id,name,employee_code')
            ->whereBetween('attendance_date', [$month->toDateString(), $end->toDateString()])
            ->whereIn('employee_id', $employees)
            ->orderBy('attendance_date')
            ->get()
            ->map(fn (Attendance $row): array => [
                $row->attendance_date->toDateString(),
                $row->employee?->employee_code,
                $row->employee?->name,
                $row->day_type->label(),
                $row->status->label(),
                $row->check_in_at?->format('H:i'),
                $row->check_out_at?->format('H:i'),
                $row->worked_minutes,
                $row->late_minutes,
                $row->early_leave_minutes,
                $row->overtime_minutes,
                (string) $row->payable_factor,
            ])
            ->all();

        return (new CsvWriter)->download(
            sprintf('attendance-%s.csv', $month->format('Y-m')),
            ['Date', 'Code', 'Employee', 'Day type', 'Status', 'In', 'Out', 'Worked', 'Late', 'Early leave', 'Overtime', 'Payable'],
            $rows,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function dateFrom(Request $request, string $key): Carbon
    {
        $value = $request->query($key);

        return $value === null ? now()->startOfDay() : Carbon::parse((string) $value)->startOfDay();
    }

    /**
     * @return Builder<Employee>
     */
    private function visibleEmployees(Request $request)
    {
        $query = Employee::query()->with(['department:id,name', 'workShift:id,name']);

        $this->scopes->apply($query, $request->user(), 'id');

        return $query;
    }
}
