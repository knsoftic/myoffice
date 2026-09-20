<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Models\Hr\WorkShift;
use App\Models\Role;
use App\Models\User;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\EmployeeService;
use App\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employees — `admin.employees.*` (phase-07 §7.1, §8.1–8.3), `module:employees`.
 *
 * Every list starts from `EmployeeScopeResolver` (§9), so what a line manager sees is decided in one
 * place rather than re-derived per screen.
 *
 * **Money is omitted, not hidden.** Without `employees.view_financial` the gross salary is left out of
 * the select and out of the markup entirely — hiding a column with CSS puts it in the page source, which
 * is the same as publishing it.
 */
final class EmployeeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);

        $showMoney = (bool) $request->user()?->can('employees.view_financial');
        $term = trim((string) $request->query('q', ''));

        $query = Employee::query()
            ->with(['department:id,name', 'designation:id,title', 'workShift:id,name'])
            ->select($this->columns($showMoney));

        $this->scopes->apply($query, $request->user(), 'id');

        return view('admin.hr.employees.index', [
            'employees' => $query
                ->when($term !== '', fn ($scoped) => $scoped->where(fn ($inner) => $inner
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('employee_code', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')))
                ->when($request->filled('department_id'), fn ($scoped) => $scoped
                    ->where('department_id', $request->integer('department_id')))
                ->when($request->filled('status'), fn ($scoped) => $scoped
                    ->where('status', $request->string('status')->toString()))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => EmployeeStatus::options(),
            'showMoney' => $showMoney,
            'term' => $term,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Employee::class);

        return view('admin.hr.employees.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $data = $this->validated($request);
        $account = $this->accountData($request);

        $employee = $this->employees->create($data, $account);

        return redirect()
            ->route('admin.employees.show', $employee)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s added as %s.', $employee->name, $employee->employee_code),
            ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $showMoney = $request->user()?->can('viewFinancial', $employee) === true;

        $employee->load([
            'department:id,name', 'designation:id,title', 'workShift:id,name',
            'manager:id,name,employee_code', 'branch:id,name', 'skills', 'user:id,name,email,status',
        ]);

        return view('admin.hr.employees.show', [
            'employee' => $employee,
            'showMoney' => $showMoney,
            'structure' => $showMoney ? $employee->activeSalaryStructure()->with('components')->first() : null,
            'statuses' => EmployeeStatus::options(),
            'directReports' => $employee->directReports()->orderBy('name')->get(['id', 'name', 'employee_code']),
        ]);
    }

    public function edit(Employee $employee): View
    {
        $this->authorize('update', $employee);

        return view('admin.hr.employees.edit', $this->formData() + ['employee' => $employee]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $this->employees->update($employee, $this->validated($request, $employee));

        return redirect()
            ->route('admin.employees.show', $employee)
            ->with('toast', ['type' => 'success', 'message' => 'Employee saved.']);
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return redirect()
            ->route('admin.employees.index')
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s archived. Their payroll history stays exactly as it was.', $employee->name),
            ]);
    }

    public function restore(int $employee): RedirectResponse
    {
        $this->authorize('restore', Employee::class);

        $record = Employee::withTrashed()->findOrFail($employee);
        $record->restore();

        return back()->with('toast', ['type' => 'success', 'message' => $record->name.' restored.']);
    }

    /**
     * Move somebody between the states of §2.26, including the exit sequence.
     */
    public function status(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('changeStatus', $employee);

        $data = $request->validate([
            'status' => ['required', Rule::enum(EmployeeStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'exit_date' => ['nullable', 'date'],
        ]);

        $this->employees->changeStatus(
            $employee,
            EmployeeStatus::from($data['status']),
            (string) ($data['reason'] ?? ''),
            isset($data['exit_date']) ? Carbon::parse($data['exit_date']) : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is now %s.', $employee->name, $employee->fresh()->status->label()),
        ]);
    }

    /**
     * Department, designation, shift and reporting line — the org chart (§7.1).
     */
    public function assignments(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('assign', $employee);

        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')],
            'work_shift_id' => ['nullable', 'integer', Rule::exists('work_shifts', 'id')],
            'reports_to_id' => ['nullable', 'integer', Rule::notIn([$employee->getKey()]), Rule::exists('employees', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ]);

        $employee->forceFill($data)->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Assignments saved. Attendance already recorded keeps its own shift window.',
        ]);
    }

    public function linkUser(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $this->employees->linkUser($employee, User::query()->findOrFail($data['user_id']));

        return back()->with('toast', ['type' => 'success', 'message' => 'Login linked.']);
    }

    public function unlinkUser(Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $this->employees->unlinkUser($employee);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Login unlinked. The account itself is untouched.',
        ]);
    }

    public function print(Employee $employee): View
    {
        $this->authorize('print', $employee);

        $employee->load(['department:id,name', 'designation:id,title', 'skills']);

        return view('admin.hr.employees.print', ['employee' => $employee]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        $this->authorize('export', Employee::class);

        abort_unless($format === 'csv', 404);

        $showMoney = (bool) $request->user()?->can('employees.view_financial');

        $query = Employee::query()->with(['department:id,name', 'designation:id,title']);
        $this->scopes->apply($query, $request->user(), 'id');

        $headers = ['Code', 'Name', 'Department', 'Designation', 'Status', 'Employment', 'Joined', 'Email', 'Phone'];

        if ($showMoney) {
            $headers[] = 'Current gross';
        }

        $rows = $query->orderBy('name')->get()->map(function (Employee $employee) use ($showMoney): array {
            $row = [
                $employee->employee_code,
                $employee->name,
                $employee->department?->name,
                $employee->designation?->title,
                $employee->status->label(),
                $employee->employment_type->label(),
                $employee->joining_date->toDateString(),
                $employee->email,
                $employee->phone,
            ];

            if ($showMoney) {
                $row[] = (string) $employee->current_gross_salary;
            }

            return $row;
        })->all();

        return (new CsvWriter)->download('employees.csv', $headers, $rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The columns a list may select. `current_gross_salary` is **absent** without the money ability —
     * omitted from the query, so it cannot reach the markup by accident (§9).
     *
     * @return list<string>
     */
    private function columns(bool $showMoney): array
    {
        $columns = [
            'id', 'employee_code', 'user_id', 'branch_id', 'department_id', 'designation_id',
            'reports_to_id', 'work_shift_id', 'name', 'photo_path', 'phone', 'email',
            'joining_date', 'employment_type', 'status', 'exit_date', 'is_attendance_exempt',
            'deleted_at',
        ];

        if ($showMoney) {
            $columns[] = 'current_gross_salary';
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::query()->where('is_active', true)->orderBy('title')->get(['id', 'title', 'department_id']),
            'shifts' => WorkShift::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'managers' => Employee::query()->whereIn('status', ['active', 'probation'])->orderBy('name')->get(['id', 'name', 'employee_code']),
            'roles' => Role::query()->orderBy('name')->pluck('name', 'name'),
            'employmentTypes' => EmploymentType::options(),
            'statuses' => EmployeeStatus::options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')],
            'work_shift_id' => ['nullable', 'integer', Rule::exists('work_shifts', 'id')],
            'reports_to_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'joining_date' => ['required', 'date'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'email' => [
                'nullable', 'email', 'max:150',
                Rule::unique('employees', 'email')->ignore($employee?->getKey())->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'is_attendance_exempt' => ['nullable', 'boolean'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:64'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * The optional login, validated only when one was asked for.
     *
     * @return array<string, mixed>|null
     */
    private function accountData(Request $request): ?array
    {
        if (! $request->boolean('create_login')) {
            return null;
        }

        $data = $request->validate([
            'account_email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'account_password' => ['required', 'string', 'min:8'],
            'account_role' => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        return [
            'email' => $data['account_email'],
            'password' => bcrypt($data['account_password']),
            'role' => $data['account_role'],
        ];
    }
}
