<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\AdvanceRecoveryType;
use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeAdvance;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\EmployeeScopeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Salary advances — `admin.advances.*` (phase-07 §7.5, §8.18), `module:employee_advances`.
 *
 * The detail screen shows the repayment ledger, because "how much is left?" has to be a list of rows
 * rather than a number somebody believes. There is no edit and no delete: a mistake is a waiver or a
 * correction entry, each with a reason and an actor.
 */
final class EmployeeAdvanceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AdvanceService $advances,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', EmployeeAdvance::class);

        $query = EmployeeAdvance::query()
            ->with(['employee:id,name,employee_code'])
            ->when($request->filled('status'), fn ($scoped) => $scoped
                ->where('status', $request->string('status')->toString()))
            ->orderByDesc('requested_on');

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.advances.index', [
            'advances' => $query->paginate(25)->withQueryString(),
            'statuses' => AdvanceStatus::options(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', EmployeeAdvance::class);

        $employees = Employee::query()->whereIn('status', ['active', 'probation']);
        $this->scopes->apply($employees, $request->user(), 'id');

        return view('admin.hr.advances.create', [
            'employees' => $employees->orderBy('name')->get(['id', 'name', 'employee_code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EmployeeAdvance::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:36'],
            'first_recovery_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'first_recovery_month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $advance = $this->advances->request(
            employee: Employee::query()->with('activeSalaryStructure')->findOrFail($data['employee_id']),
            amount: (string) $data['amount'],
            reason: $data['reason'],
            installments: (int) $data['installment_count'],
            firstRecoveryYear: $data['first_recovery_year'] ?? null,
            firstRecoveryMonth: $data['first_recovery_month'] ?? null,
            actor: $request->user(),
        );

        return redirect()
            ->route('admin.advances.show', $advance)
            ->with('toast', ['type' => 'success', 'message' => $advance->advance_number.' filed.']);
    }

    public function show(EmployeeAdvance $advance): View
    {
        $this->authorize('view', $advance);

        $advance->load([
            'employee:id,name,employee_code',
            'repayments.performer:id,name',
            'repayments.payrollItem:id,slip_number',
            'approver:id,name',
        ]);

        return view('admin.hr.advances.show', [
            'advance' => $advance,
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function approve(Request $request, EmployeeAdvance $advance): RedirectResponse
    {
        $this->authorize('approve', $advance);

        $this->advances->approve($advance, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Advance approved.']);
    }

    public function reject(Request $request, EmployeeAdvance $advance): RedirectResponse
    {
        $this->authorize('reject', $advance);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->advances->reject($advance, $data['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Advance refused, with the reason recorded.']);
    }

    public function disburse(Request $request, EmployeeAdvance $advance): RedirectResponse
    {
        $this->authorize('changeStatus', $advance);

        $data = $request->validate([
            'disbursement_method' => ['required', Rule::enum(PaymentMethod::class)],
            'disbursement_reference' => ['nullable', 'string', 'max:64'],
            'disbursed_on' => ['nullable', 'date'],
        ]);

        $this->advances->disburse(
            $advance,
            PaymentMethod::from($data['disbursement_method']),
            $data['disbursement_reference'] ?? null,
            isset($data['disbursed_on']) ? Carbon::parse($data['disbursed_on']) : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Disbursed. From here the amount is what was actually handed over.',
        ]);
    }

    /**
     * Record money paid back outside payroll.
     */
    public function recovery(Request $request, EmployeeAdvance $advance): RedirectResponse
    {
        $this->authorize('changeStatus', $advance);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:255'],
            'recovered_on' => ['nullable', 'date'],
        ]);

        $this->advances->recordRecovery(
            advance: $advance,
            amount: (string) $data['amount'],
            item: null,
            type: AdvanceRecoveryType::Manual,
            notes: $data['notes'] ?? null,
            actor: $request->user(),
            on: isset($data['recovered_on']) ? Carbon::parse($data['recovered_on']) : null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Repayment recorded.']);
    }

    public function waive(Request $request, EmployeeAdvance $advance): RedirectResponse
    {
        $this->authorize('waive', $advance);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->advances->waive($advance, (string) $data['amount'], $data['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Waiver posted. The advance still says what was lent.',
        ]);
    }

    public function cancel(Request $request, EmployeeAdvance $advance): RedirectResponse
    {
        $this->authorize('changeStatus', $advance);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->advances->cancel($advance, $data['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Advance withdrawn.']);
    }
}
