<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\WorkShift;
use App\Services\Hr\WorkShiftService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Work shifts — `admin.work-shifts.*` (phase-07 §7.2, §8.7), `module:work_shifts`.
 *
 * The screen says in one sentence that editing a shift changes nothing about past attendance (HR-2),
 * because the opposite assumption is the natural one and it is the question HR would otherwise ask.
 *
 * `crosses_midnight` and `expected_minutes` are never posted: the service derives them.
 */
final class WorkShiftController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly WorkShiftService $shifts) {}

    public function index(): View
    {
        $this->authorize('viewAny', WorkShift::class);

        return view('admin.hr.work-shifts.index', [
            'shifts' => WorkShift::query()
                ->with('branch:id,name')
                ->withCount(['employees' => fn ($query) => $query->whereNull('deleted_at')])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'weekdays' => self::WEEKDAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', WorkShift::class);

        $this->shifts->store($this->validated($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Shift added.']);
    }

    public function update(Request $request, WorkShift $shift): RedirectResponse
    {
        $this->authorize('update', $shift);

        $this->shifts->update($shift, $this->validated($request, $shift));

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Shift saved. Attendance already recorded keeps the window it was measured against.',
        ]);
    }

    public function toggle(WorkShift $shift): RedirectResponse
    {
        $this->authorize('changeStatus', $shift);

        $this->shifts->toggle($shift);

        return back()->with('toast', ['type' => 'success', 'message' => 'Shift updated.']);
    }

    /**
     * Put a set of employees on this shift (§7.2) — a roster decision, separate from defining the shift.
     */
    public function assign(Request $request, WorkShift $shift): RedirectResponse
    {
        $this->authorize('assign', $shift);

        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ]);

        $moved = Employee::query()
            ->whereIn('id', $data['employee_ids'])
            ->update(['work_shift_id' => $shift->getKey(), 'updated_at' => now()]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%d employee(s) moved to %s from today. Past days keep their own window.', $moved, $shift->name),
        ]);
    }

    public function destroy(WorkShift $shift): RedirectResponse
    {
        $this->authorize('delete', $shift);

        $this->shifts->destroy($shift);

        return back()->with('toast', ['type' => 'success', 'message' => 'Shift removed.']);
    }

    /** The days a weekly-off picker offers, in the order a week is read. */
    public const WEEKDAYS = [
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
    ];

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WorkShift $shift = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('work_shifts', 'code')->ignore($shift?->getKey())->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'grace_in_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'grace_out_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'min_full_day_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'min_half_day_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'weekly_off_days' => ['nullable', 'array'],
            'weekly_off_days.*' => ['string', Rule::in(array_keys(self::WEEKDAYS))],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        // A form that posts no checkboxes means "no days chosen", which falls back to the business-wide
        // setting — not "keep whatever was there", which would make unticking impossible.
        $data['weekly_off_days'] = $data['weekly_off_days'] ?? [];

        return $data;
    }
}
