<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Services\Hr\DepartmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Departments — `admin.departments.*` (phase-07 §7.1, §8.4), `module:departments`.
 *
 * The head of a department is an **employee**, not a user (D32), and naming one from another department
 * needs `departments.assign` plus a reason — the service decides that, this controller only carries it.
 */
final class DepartmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly DepartmentService $departments) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Department::class);

        $term = trim((string) $request->query('q', ''));

        return view('admin.hr.departments.index', [
            'departments' => Department::query()
                ->with(['head:id,name,employee_code', 'branch:id,name'])
                ->withCount(['employees' => fn ($query) => $query->whereNull('deleted_at')])
                ->when($term !== '', fn ($query) => $query->where(fn ($scoped) => $scoped
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%')))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'employees' => Employee::query()
                ->whereIn('status', ['active', 'probation'])
                ->orderBy('name')
                ->get(['id', 'name', 'employee_code', 'department_id']),
            'term' => $term,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Department::class);

        $this->departments->store($this->validated($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Department added.']);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);

        $this->departments->update($department, $this->validated($request, $department));

        return back()->with('toast', ['type' => 'success', 'message' => 'Department saved.']);
    }

    public function head(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('assign', $department);

        $data = $request->validate([
            'head_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $head = ($data['head_employee_id'] ?? null) === null
            ? null
            : Employee::query()->with('department')->findOrFail($data['head_employee_id']);

        $this->departments->setHead($department, $head, (string) ($data['reason'] ?? ''));

        return back()->with('toast', [
            'type' => 'success',
            'message' => $head === null
                ? 'The department has no head.'
                : $head->name.' now heads this department.',
        ]);
    }

    public function toggle(Department $department): RedirectResponse
    {
        $this->authorize('changeStatus', $department);

        $this->departments->toggle($department);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $department->is_active ? 'Department is active.' : 'Department is inactive.',
        ]);
    }

    public function destroy(Department $department): RedirectResponse
    {
        $this->authorize('delete', $department);

        $this->departments->destroy($department);

        return back()->with('toast', ['type' => 'success', 'message' => 'Department removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('departments', 'code')->ignore($department?->getKey())->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
