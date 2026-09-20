<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Services\Hr\DepartmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Designations — `admin.designations.*` (phase-07 §7.1, §8.5), `module:designations`.
 *
 * A title belongs to a department or to nobody; `level` orders a career ladder and is what a report groups
 * by when it asks "how many seniors do we have?".
 */
final class DesignationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly DepartmentService $departments) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Designation::class);

        $term = trim((string) $request->query('q', ''));

        return view('admin.hr.designations.index', [
            'designations' => Designation::query()
                ->with('department:id,name')
                ->withCount(['employees' => fn ($query) => $query->whereNull('deleted_at')])
                ->when($term !== '', fn ($query) => $query->where('title', 'like', '%'.$term.'%'))
                ->orderBy('sort_order')
                ->orderBy('title')
                ->paginate(20)
                ->withQueryString(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'term' => $term,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Designation::class);

        $this->departments->storeDesignation($this->validated($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Designation added.']);
    }

    public function update(Request $request, Designation $designation): RedirectResponse
    {
        $this->authorize('update', $designation);

        $this->departments->updateDesignation($designation, $this->validated($request, $designation));

        return back()->with('toast', ['type' => 'success', 'message' => 'Designation saved.']);
    }

    public function toggle(Designation $designation): RedirectResponse
    {
        $this->authorize('changeStatus', $designation);

        $designation->forceFill(['is_active' => ! $designation->is_active])->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Designation updated.']);
    }

    public function destroy(Designation $designation): RedirectResponse
    {
        $this->authorize('delete', $designation);

        $this->departments->destroyDesignation($designation);

        return back()->with('toast', ['type' => 'success', 'message' => 'Designation removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Designation $designation = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'code' => [
                'nullable', 'string', 'max:32',
                Rule::unique('designations', 'code')->ignore($designation?->getKey())->whereNull('deleted_at'),
            ],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
