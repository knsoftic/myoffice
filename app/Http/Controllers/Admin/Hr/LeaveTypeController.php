<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\EmploymentType;
use App\Enums\LeaveAccrualMethod;
use App\Http\Controllers\Controller;
use App\Models\Hr\LeaveType;
use App\Services\Hr\Exceptions\HrRuleException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave types — `admin.leave-types.*` (phase-07 §7.4, §8.13), `module:leave_types`.
 *
 * Every rule a business might have about leave lives here as **data**: the quota, how it accrues, whether
 * it carries forward, how much notice it needs, whether weekends inside a range count against it, how
 * many approval levels it goes through. Nothing about leave is hardcoded anywhere else.
 */
final class LeaveTypeController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', LeaveType::class);

        return view('admin.hr.leave-types.index', [
            'types' => LeaveType::query()
                ->withCount(['requests', 'balances'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'accrualMethods' => LeaveAccrualMethod::options(),
            'employmentTypes' => EmploymentType::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', LeaveType::class);

        $type = new LeaveType;
        $type->fill($this->validated($request))->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Leave type added.']);
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('update', $leaveType);

        $leaveType->fill($this->validated($request, $leaveType))->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Leave type saved.']);
    }

    public function toggle(LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('changeStatus', $leaveType);

        $leaveType->forceFill(['is_active' => ! $leaveType->is_active])->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => $leaveType->is_active
                ? 'Leave type is available again.'
                : 'Leave type retired. Existing requests and balances keep it.',
        ]);
    }

    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('delete', $leaveType);

        $used = $leaveType->requests()->count();

        if ($used > 0) {
            throw HrRuleException::refuse('id', sprintf(
                '%d leave request(s) use this type. Retire it instead — the history has to keep naming '
                .'what was actually taken.',
                $used
            ));
        }

        $leaveType->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Leave type removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LeaveType $type = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('leave_types', 'code')->ignore($type?->getKey())->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'annual_quota_days' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:365'],
            'is_paid' => ['nullable', 'boolean'],
            'accrual_method' => ['required', Rule::enum(LeaveAccrualMethod::class)],
            'accrual_days_per_month' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:31'],
            'accrue_from_joining' => ['nullable', 'boolean'],
            'carry_forward_enabled' => ['nullable', 'boolean'],
            'max_carry_forward_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:365'],
            'carry_forward_expiry_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'min_notice_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'allow_half_day' => ['nullable', 'boolean'],
            'allow_negative_balance' => ['nullable', 'boolean'],
            'requires_attachment' => ['nullable', 'boolean'],
            'attachment_required_after_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'excludes_weekends' => ['nullable', 'boolean'],
            'excludes_holidays' => ['nullable', 'boolean'],
            'applies_to_employment_types' => ['nullable', 'array'],
            'applies_to_employment_types.*' => [Rule::enum(EmploymentType::class)],
            'allowed_on_probation' => ['nullable', 'boolean'],
            'approval_levels' => ['required', 'integer', 'between:1,2'],
            'is_encashable' => ['nullable', 'boolean'],
            'color' => ['required', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        // An empty list means "everybody" (§2.12), which is what unticking every box has to produce.
        $data['applies_to_employment_types'] = $data['applies_to_employment_types'] ?? [];

        foreach (['is_paid', 'accrue_from_joining', 'carry_forward_enabled', 'allow_half_day',
            'allow_negative_balance', 'requires_attachment', 'excludes_weekends', 'excludes_holidays',
            'allowed_on_probation', 'is_encashable', 'is_active'] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }

        return $data;
    }
}
