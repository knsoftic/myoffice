<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\SalaryStructure;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\SalaryStructureService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Salary structures — `admin.salary-structures.*` (phase-07 §7.5, §8.18), `module:salary_structures`.
 *
 * The screen is a **timeline**, not a form with a salary in it: every version, when it started, when it
 * was superseded and why. There is no edit and no delete anywhere here, because a raise is the next
 * version and that is the only way a salary ever changes (HR-10).
 *
 * The overview at `/admin/salary-structures` is this phase's own addition to §7.5, which only describes
 * employee-scoped routes: a sidebar entry needs a parameterless URL, and "who is on what" is a question
 * HR asks before it asks about one person.
 */
final class SalaryStructureController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SalaryStructureService $structures,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    /**
     * Everybody's current version, at a glance.
     */
    public function overview(Request $request): View
    {
        $this->authorize('viewAny', SalaryStructure::class);

        $query = Employee::query()
            ->with(['department:id,name', 'activeSalaryStructure'])
            ->whereIn('status', ['active', 'probation']);

        $this->scopes->apply($query, $request->user(), 'id');

        return view('admin.hr.salary-structures.overview', [
            'employees' => $query->orderBy('name')->paginate(30)->withQueryString(),
        ]);
    }

    /**
     * One employee's timeline.
     */
    public function index(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);
        $this->authorize('viewAny', SalaryStructure::class);

        return view('admin.hr.salary-structures.index', [
            'employee' => $employee,
            'versions' => $employee->salaryStructures()->with('components')->get(),
        ]);
    }

    public function create(Request $request, Employee $employee): View
    {
        $this->authorize('create', SalaryStructure::class);

        $current = $employee->activeSalaryStructure()->with('components')->first();

        return view('admin.hr.salary-structures.create', [
            'employee' => $employee,
            'current' => $current,
            'components' => $this->structures->selectableComponents(),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('create', SalaryStructure::class);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'basic_salary' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'change_reason' => ['required', 'string', 'min:3', 'max:255'],
            'components' => ['nullable', 'array'],
            'components.*.salary_component_id' => ['required', 'integer', Rule::exists('salary_components', 'id')],
            'components.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'components.*.rate' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ]);

        $lines = collect($data['components'] ?? [])
            ->filter(fn (array $line): bool => ! empty($line['salary_component_id']))
            ->values()
            ->all();

        $version = $this->structures->createVersion(
            employee: $employee,
            effectiveFrom: Carbon::parse($data['effective_from']),
            basicSalary: (string) $data['basic_salary'],
            components: $lines,
            reason: $data['change_reason'],
            actorId: $request->user()?->getKey(),
        );

        return redirect()
            ->route('admin.employees.salary-structures.index', $employee)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Version %d saved. The previous one was closed the day before it starts.', (int) $version->version),
            ]);
    }

    public function cancel(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $this->authorize('changeStatus', $structure);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->structures->cancel($structure, $data['reason']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Scheduled version withdrawn; the version it would have replaced is open again.',
        ]);
    }
}
