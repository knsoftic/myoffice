<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Http\Controllers\Controller;
use App\Models\Hr\SalaryComponent;
use App\Services\Hr\SalaryComponentService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Salary components — `admin.salary-components.*` (phase-07 §7.5, §8.18), `module:salary_components`.
 *
 * `side` is never posted: it comes from the group, so a component filed under "allowance" can never be
 * saved as a deduction. The form offers only the groups a human may configure — the five system groups
 * (unpaid leave, late, advance recovery, overtime, tax) are produced by payroll itself.
 */
final class SalaryComponentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SalaryComponentService $components) {}

    public function index(): View
    {
        $this->authorize('viewAny', SalaryComponent::class);

        return view('admin.hr.salary-components.index', [
            'components' => SalaryComponent::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->groupBy(fn (SalaryComponent $component): string => $component->side->label()),
            'groups' => collect(SalaryComponentGroup::cases())
                ->reject(fn (SalaryComponentGroup $group): bool => $group->isSystem())
                ->mapWithKeys(fn (SalaryComponentGroup $group): array => [$group->value => $group->label()])
                ->all(),
            'calculations' => SalaryComponentCalculation::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SalaryComponent::class);

        $this->components->store($this->validated($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Component added.']);
    }

    public function update(Request $request, SalaryComponent $component): RedirectResponse
    {
        $this->authorize('update', $component);

        $this->components->update($component, $this->validated($request, $component));

        return back()->with('toast', ['type' => 'success', 'message' => 'Component saved.']);
    }

    public function toggle(SalaryComponent $component): RedirectResponse
    {
        $this->authorize('changeStatus', $component);

        $this->components->toggle($component);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $component->is_active
                ? 'Component is available again.'
                : 'Component retired. Past slips keep exactly what they said.',
        ]);
    }

    public function destroy(SalaryComponent $component): RedirectResponse
    {
        $this->authorize('delete', $component);

        $this->components->destroy($component);

        return back()->with('toast', ['type' => 'success', 'message' => 'Component removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SalaryComponent $component = null): array
    {
        $groups = collect(SalaryComponentGroup::cases())
            ->reject(fn (SalaryComponentGroup $group): bool => $group->isSystem())
            ->map(fn (SalaryComponentGroup $group): string => $group->value)
            ->all();

        return $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('salary_components', 'code')->ignore($component?->getKey())->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'component_group' => ['required', Rule::in($groups)],
            'calculation_type' => ['required', Rule::enum(SalaryComponentCalculation::class)],
            'default_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999999999'],
            'default_rate' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'is_taxable' => ['nullable', 'boolean'],
            'affects_gross' => ['nullable', 'boolean'],
            'is_attendance_dependent' => ['nullable', 'boolean'],
            'print_label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
