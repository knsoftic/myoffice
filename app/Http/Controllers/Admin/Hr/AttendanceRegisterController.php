<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Hr\StoreAttendanceRegisterRequest;
use App\Models\Hr\Attendance;
use App\Models\Hr\Department;
use App\Services\Hr\AttendanceRegisterService;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * "Mark attendance" — the employee register for one day, every employee marked from one screen, the HR
 * counterpart of the student register. The deciding lives in `AttendanceRegisterService`.
 */
final class AttendanceRegisterController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AttendanceRegisterService $register,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $date = $this->dateFrom((string) $request->input('date', ''));

        if ($date->isFuture()) {
            return redirect()
                ->route('admin.attendance.register', ['date' => Carbon::today()->toDateString()])
                ->with('toast', ['type' => 'info', 'message' => 'Attendance is marked for today or an earlier day — here is today.']);
        }

        $departmentId = $request->filled('department_id') ? $request->integer('department_id') : null;

        return view('admin.hr.attendance.register', [
            'date' => $date,
            'departmentId' => $departmentId,
            'sheet' => $this->register->sheet($request->user(), $date, $departmentId),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'marks' => AttendanceRegisterService::MARKS,
        ]);
    }

    public function store(StoreAttendanceRegisterRequest $request): RedirectResponse
    {
        $this->authorize('create', Attendance::class);

        $data = $request->validated();
        $date = Carbon::parse($data['date'])->startOfDay();

        try {
            $report = $this->register->save($request->user(), $date, $data['marks'], $data['reason']);
        } catch (LockedAttendanceException $exception) {
            // A payroll locked the period between opening the screen and saving it; nothing was written.
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        $message = sprintf('%d marked, %d unchanged.', $report['marked'], $report['unchanged']);

        if ($report['skipped'] !== []) {
            $message .= ' Not changed: '.implode('; ', $report['skipped']).'.';
        }

        return redirect()
            ->route('admin.attendance.register', array_filter([
                'date' => $date->toDateString(),
                'department_id' => $data['department_id'] ?? null,
            ]))
            ->with('toast', ['type' => $report['skipped'] === [] ? 'success' : 'warning', 'message' => $message]);
    }

    private function dateFrom(string $value): Carbon
    {
        try {
            return $value === '' ? Carbon::today() : Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (Throwable) {
            return Carbon::today();
        }
    }
}
