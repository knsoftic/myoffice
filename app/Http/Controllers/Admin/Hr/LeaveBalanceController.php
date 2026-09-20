<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveBalance;
use App\Models\Hr\LeaveBalanceTransaction;
use App\Models\Hr\LeaveType;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\LeaveBalanceService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leave balances — `admin.leave-balances.*` (phase-07 §7.4, §8.14), `module:leave_balances`.
 *
 * The per-employee screen is a **statement**, not a number: every credit and debit with its reason, its
 * value date and the balance after it. That is the whole point of keeping days as a ledger — an employee
 * asking "where did my leave go?" gets a list of rows instead of an assurance.
 */
final class LeaveBalanceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LeaveBalance::class);

        $year = (int) $request->query('year', (string) $this->balances->leaveYearFor(now())['year']);

        $query = LeaveBalance::query()
            ->with(['employee:id,name,employee_code,department_id', 'leaveType:id,name,color'])
            ->where('leave_year', $year);

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.leave-balances.index', [
            'balances' => $query->orderBy('employee_id')->paginate(30)->withQueryString(),
            'year' => $year,
            'years' => range(now()->year - 2, now()->year + 1),
            'types' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canAdjust' => (bool) $request->user()?->can('leave_balances.create'),
        ]);
    }

    /**
     * One employee's statement (§8.14).
     */
    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $year = (int) $request->query('year', (string) $this->balances->leaveYearFor(now())['year']);

        return view('admin.hr.leave-balances.show', [
            'employee' => $employee,
            'year' => $year,
            'years' => range(now()->year - 2, now()->year + 1),
            'balances' => LeaveBalance::query()
                ->with('leaveType:id,name,color')
                ->where('employee_id', $employee->getKey())
                ->where('leave_year', $year)
                ->get(),
            'ledger' => LeaveBalanceTransaction::query()
                ->with(['leaveType:id,name', 'performer:id,name', 'leaveRequest:id,request_number'])
                ->where('employee_id', $employee->getKey())
                ->where('leave_year', $year)
                ->orderByDesc('occurred_on')
                ->orderByDesc('id')
                ->get(),
            'types' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canAdjust' => (bool) $request->user()?->can('leave_balances.create'),
        ]);
    }

    /**
     * Post an adjustment — an append-only ledger row, never an edit of a balance column.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $this->authorize('create', LeaveBalance::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')],
            'signed_days' => ['required', 'numeric', 'decimal:0,4', 'not_in:0'],
            'notes' => ['required', 'string', 'min:3', 'max:255'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        $this->balances->adjust(
            employee: Employee::query()->findOrFail($data['employee_id']),
            type: LeaveType::query()->findOrFail($data['leave_type_id']),
            signedDays: (string) $data['signed_days'],
            note: $data['notes'],
            actor: $request->user(),
            on: isset($data['occurred_on']) ? Carbon::parse($data['occurred_on']) : null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Adjustment posted to the ledger.']);
    }

    /**
     * Credit the year's quota, once, for everybody (§6.2). Running it twice writes nothing.
     */
    public function grantYear(Request $request): RedirectResponse
    {
        $this->authorize('create', LeaveBalance::class);

        $data = $request->validate([
            'leave_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
        ]);

        $employee = ($data['employee_id'] ?? null) === null
            ? null
            : Employee::query()->findOrFail($data['employee_id']);

        $granted = $this->balances->grantYear((int) $data['leave_year'], $employee);

        return back()->with('toast', [
            'type' => $granted > 0 ? 'success' : 'info',
            'message' => $granted > 0
                ? sprintf('%d quota grant(s) credited for %d.', $granted, (int) $data['leave_year'])
                : 'Nothing to grant — every quota for that year already exists.',
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        $this->authorize('export', LeaveBalance::class);

        abort_unless($format === 'csv', 404);

        $year = (int) $request->query('year', (string) $this->balances->leaveYearFor(now())['year']);

        $query = LeaveBalance::query()
            ->with(['employee:id,name,employee_code', 'leaveType:id,name'])
            ->where('leave_year', $year);

        $this->scopes->apply($query, $request->user());

        $rows = $query->orderBy('employee_id')->get()->map(fn (LeaveBalance $balance): array => [
            $balance->employee?->employee_code,
            $balance->employee?->name,
            $balance->leaveType?->name,
            (string) $balance->entitled_days,
            (string) $balance->carried_forward_days,
            (string) $balance->accrued_days,
            (string) $balance->adjusted_days,
            (string) $balance->consumed_days,
            (string) $balance->pending_days,
            (string) $balance->expired_days,
            (string) $balance->available_days,
        ])->all();

        return (new CsvWriter)->download(
            sprintf('leave-balances-%d.csv', $year),
            ['Code', 'Employee', 'Leave type', 'Entitled', 'Carried', 'Accrued', 'Adjusted',
                'Consumed', 'Reserved', 'Expired', 'Available'],
            $rows,
        );
    }
}
