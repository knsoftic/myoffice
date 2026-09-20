<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Department;
use App\Services\Hr\AttendanceSummaryService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly attendance summaries — `admin.attendance-summaries.*` (phase-07 §7.3, §8.10).
 *
 * This is the row payroll reads and **nothing else**. The screen states the two rules that decide
 * somebody's pay (§6.4), because "why is my salary short?" is answered here or nowhere: payable days sum
 * every row in the month, and lost-pay days sum working days only — a weekend is never a deduction.
 */
final class AttendanceSummaryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AttendanceSummaryService $summaries,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewReports', Attendance::class);

        $month = $this->month($request);

        $query = AttendanceMonthlySummary::query()
            ->with(['employee:id,name,employee_code,department_id', 'employee.department:id,name', 'lockedByRun:id,run_number'])
            ->where('period_year', $month->year)
            ->where('period_month', $month->month)
            ->when($request->filled('department_id'), fn ($scoped) => $scoped
                ->whereHas('employee', fn ($inner) => $inner->where('department_id', $request->integer('department_id'))));

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.attendance.summaries', [
            'month' => $month,
            'summaries' => $query->orderBy('employee_id')->paginate(30)->withQueryString(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'canRebuild' => (bool) $request->user()?->can('attendance.change_status'),
        ]);
    }

    public function rebuild(Request $request): RedirectResponse
    {
        $this->authorize('viewReports', Attendance::class);

        abort_unless($request->user()?->can('attendance.change_status'), 403);

        $data = $request->validate([
            'month' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $month = Carbon::parse($data['month']);
        $built = $this->summaries->buildPeriod((int) $month->year, (int) $month->month, $data['branch_id'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%d summary(ies) rebuilt for %s. Locked periods were left alone.', $built, $month->format('F Y')),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        $this->authorize('export', Attendance::class);

        abort_unless($format === 'csv', 404);

        $month = $this->month($request);

        $query = AttendanceMonthlySummary::query()
            ->with('employee:id,name,employee_code')
            ->where('period_year', $month->year)
            ->where('period_month', $month->month);

        $this->scopes->apply($query, $request->user());

        $rows = $query->orderBy('employee_id')->get()->map(fn (AttendanceMonthlySummary $summary): array => [
            $summary->employee?->employee_code,
            $summary->employee?->name,
            $summary->calendar_days,
            (string) $summary->working_days,
            (string) $summary->present_days,
            (string) $summary->absent_days,
            (string) $summary->paid_leave_days,
            (string) $summary->unpaid_leave_days,
            (string) $summary->payable_days,
            (string) $summary->lop_days,
            $summary->late_count,
            (string) $summary->attendance_percentage,
        ])->all();

        return (new CsvWriter)->download(
            sprintf('attendance-summary-%s.csv', $month->format('Y-m')),
            ['Code', 'Employee', 'Calendar days', 'Working days', 'Present', 'Absent', 'Paid leave',
                'Unpaid leave', 'Payable days', 'Lost-pay days', 'Late count', 'Attendance %'],
            $rows,
        );
    }

    private function month(Request $request): Carbon
    {
        $value = $request->query('month');

        return ($value === null ? now() : Carbon::parse((string) $value))->startOfMonth();
    }
}
