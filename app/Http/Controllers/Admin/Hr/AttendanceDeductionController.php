<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\AttendanceDeductionStatus;
use App\Enums\AttendanceDeductionType;
use App\Http\Controllers\Controller;
use App\Models\Hr\AttendanceDeduction;
use App\Models\Hr\Employee;
use App\Services\Hr\AttendanceDeductionService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * HR's view of the attendance-based salary deductions, and the one manual action on them: reversal.
 *
 * Routes carry `can:payroll.view_financial` (amounts) and `can:payroll.change_status` (reverse); the
 * rows themselves are scoped to the employees this user may see, so a branch-scoped HR officer never
 * sees — or reverses — another branch's deduction by guessing an id.
 */
final class AttendanceDeductionController extends Controller
{
    public function __construct(
        private readonly AttendanceDeductionService $deductions,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
            'type' => ['nullable', Rule::enum(AttendanceDeductionType::class)],
            'status' => ['nullable', Rule::enum(AttendanceDeductionStatus::class)],
        ]);

        $query = AttendanceDeduction::query()->with(['employee:id,name,employee_code', 'payrollRunItem:id,slip_number', 'correctionItem:id,slip_number', 'reverser:id,name']);
        $this->scopes->apply($query, $request->user());

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['month'])) {
            $month = Carbon::createFromFormat('Y-m', $filters['month'])->startOfMonth();
            $query->forPeriod((int) $month->year, (int) $month->month);
        }

        if (! empty($filters['type'])) {
            $query->where('deduction_type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $totals = (clone $query)
            ->toBase()
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $employees = Employee::query()->select(['id', 'name', 'employee_code'])->orderBy('name');
        $this->scopes->apply($employees, $request->user(), 'id');

        return view('admin.hr.attendance-deductions.index', [
            'deductions' => $query->orderByDesc('deduction_date')->orderByDesc('id')->paginate(per_page())->withQueryString(),
            'totals' => [
                'pending' => Money::round((string) ($totals['pending']->total ?? '0')),
                'pending_count' => (int) ($totals['pending']->n ?? 0),
                'applied' => Money::round((string) ($totals['applied']->total ?? '0')),
                'applied_count' => (int) ($totals['applied']->n ?? 0),
                'reversed_count' => (int) ($totals['reversed']->n ?? 0),
            ],
            'employees' => $employees->get()->mapWithKeys(fn (Employee $e): array => [$e->id => $e->name.' ('.$e->employee_code.')'])->all(),
            'types' => AttendanceDeductionType::options(),
            'statuses' => AttendanceDeductionStatus::options(),
            'filters' => $filters,
            'lateEnabled' => (bool) setting('hr.late_arrival_deduction_enabled', false),
            'absenceEnabled' => (bool) setting('hr.excess_absence_deduction_enabled', false),
        ]);
    }

    public function reverse(Request $request, AttendanceDeduction $deduction): RedirectResponse
    {
        $employee = Employee::query()->find($deduction->employee_id);

        abort_if($employee === null || ! $this->scopes->canSee($request->user(), $employee), 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $wasApplied = $deduction->isApplied();
        $this->deductions->reverse($deduction, $data['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $wasApplied
                ? 'Deduction reversed. Its amount is refunded through a draft correction slip — lock and pay that run as usual.'
                : 'Deduction reversed. It will not be taken from the salary, and the employee has been told.',
        ]);
    }
}
