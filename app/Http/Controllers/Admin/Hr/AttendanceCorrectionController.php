<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceCorrectionType;
use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceCorrection;
use App\Models\Hr\Employee;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceSummaryService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The correction queue — `admin.attendance-corrections.*` (phase-07 §7.3, §8.12), `module:attendance`.
 *
 * Approving one writes the values onto the day, marks the row manual so the nightly pass leaves it alone,
 * and rebuilds that month's summary — because a corrected day that never reached the summary would be
 * corrected on screen and wrong in the payroll.
 */
final class AttendanceCorrectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly AttendanceSummaryService $summaries,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AttendanceCorrection::class);

        $query = AttendanceCorrection::query()
            ->with(['employee:id,name,employee_code', 'requester:id,name', 'reviewer:id,name'])
            ->when($request->filled('status'), fn ($scoped) => $scoped
                ->where('status', $request->string('status')->toString()))
            ->orderByDesc('requested_at');

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.attendance.corrections', [
            'corrections' => $query->paginate(25)->withQueryString(),
            'statuses' => AttendanceCorrectionStatus::options(),
            'pendingCount' => AttendanceCorrection::query()->where('status', AttendanceCorrectionStatus::Pending)->count(),
        ]);
    }

    /**
     * File a correction against one day (§7.3).
     */
    public function store(Request $request, Attendance $attendance): RedirectResponse
    {
        $this->authorize('update', $attendance);

        $data = $request->validate([
            'correction_type' => ['required', Rule::enum(AttendanceCorrectionType::class)],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:32'],
            'payable_factor' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:1'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $values = array_filter(
            [
                'check_in_at' => $data['check_in_at'] ?? null,
                'check_out_at' => $data['check_out_at'] ?? null,
                'status' => $data['status'] ?? null,
                'payable_factor' => $data['payable_factor'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ],
            static fn ($value): bool => $value !== null && $value !== '',
        );

        $this->corrections->applyDirect(
            employee: $attendance->employee,
            date: $attendance->attendance_date->copy(),
            type: AttendanceCorrectionType::from($data['correction_type']),
            newValues: $values,
            reason: $data['reason'],
            actor: $request->user(),
        );

        $this->rebuildSummary($attendance->employee, $attendance->attendance_date->copy());

        return back()->with('toast', ['type' => 'success', 'message' => 'Day corrected, and the month rebuilt.']);
    }

    public function approve(Request $request, AttendanceCorrection $correction): RedirectResponse
    {
        $this->authorize('approve', $correction);

        $this->corrections->approve($correction, $request->user());

        $this->rebuildSummary($correction->employee, $correction->attendance_date->copy());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Correction approved, written onto the day and the month rebuilt.',
        ]);
    }

    public function reject(Request $request, AttendanceCorrection $correction): RedirectResponse
    {
        $this->authorize('reject', $correction);

        $data = $request->validate([
            'review_comment' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->corrections->reject($correction, $data['review_comment'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Correction refused, with the reason recorded.']);
    }

    /**
     * A corrected day that never reached the summary is corrected on screen and wrong in the payroll.
     * A locked period simply refuses, which is what HR-18 is for.
     */
    private function rebuildSummary(?Employee $employee, Carbon $date): void
    {
        if ($employee === null) {
            return;
        }

        try {
            $this->summaries->build($employee, (int) $date->year, (int) $date->month);
        } catch (LockedAttendanceException) {
            // The period is paid; the correction stands on the row and the remedy is a correction run.
        }
    }
}
