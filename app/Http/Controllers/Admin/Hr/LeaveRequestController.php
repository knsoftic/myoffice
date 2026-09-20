<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\LeaveDayPortion;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Leave requests — `admin.leaves.*` (phase-07 §7.4, §8.15–8.17), `module:leaves`.
 *
 * The detail screen shows the **day expansion**, including the dates that were skipped, so "five calendar
 * days cost three quota days" is visible rather than something an employee has to reconstruct from a
 * calendar.
 *
 * The reason and the attachment are shown only to the employee, the approval chain and holders of
 * `leaves.view_any` (§9) — a leave reason is often medical.
 */
final class LeaveRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LeaveRequestService $leaves,
        private readonly LeaveBalanceService $balances,
        private readonly EmployeeScopeResolver $scopes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $query = LeaveRequest::query()
            ->with(['employee:id,name,employee_code', 'leaveType:id,name,color'])
            ->when($request->filled('status'), fn ($scoped) => $scoped
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('leave_type_id'), fn ($scoped) => $scoped
                ->where('leave_type_id', $request->integer('leave_type_id')))
            ->orderByDesc('from_date');

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.leaves.index', [
            'requests' => $query->paginate(25)->withQueryString(),
            'statuses' => LeaveRequestStatus::options(),
            'types' => LeaveType::query()->orderBy('name')->get(['id', 'name']),
            'pendingCount' => LeaveRequest::query()->where('status', LeaveRequestStatus::Pending)->count(),
        ]);
    }

    /**
     * The calendar of who is away (§8.15).
     */
    public function calendar(Request $request): View
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $month = ($request->query('month') === null ? now() : Carbon::parse((string) $request->query('month')))->startOfMonth();

        $query = LeaveRequest::query()
            ->with(['employee:id,name,employee_code', 'leaveType:id,name,color', 'days'])
            ->whereIn('status', [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved])
            ->whereDate('to_date', '>=', $month->toDateString())
            ->whereDate('from_date', '<=', $month->copy()->endOfMonth()->toDateString());

        $this->scopes->apply($query, $request->user());

        return view('admin.hr.leaves.calendar', [
            'month' => $month,
            'requests' => $query->orderBy('from_date')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', LeaveRequest::class);

        return view('admin.hr.leaves.create', $this->formData($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', LeaveRequest::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'day_portion' => ['required', Rule::enum(LeaveDayPortion::class)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'contact_during_leave' => ['nullable', 'string', 'max:64'],
        ]);

        $leave = $this->leaves->apply(
            employee: Employee::query()->with(['manager', 'department'])->findOrFail($data['employee_id']),
            type: LeaveType::query()->findOrFail($data['leave_type_id']),
            from: Carbon::parse($data['from_date']),
            to: Carbon::parse($data['to_date']),
            portion: LeaveDayPortion::from($data['day_portion']),
            reason: $data['reason'],
            contact: $data['contact_during_leave'] ?? null,
            actor: $request->user(),
        );

        return redirect()
            ->route('admin.leaves.show', $leave)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s filed — %s day(s) counted.', $leave->request_number, rtrim(rtrim((string) $leave->total_days, '0'), '.')),
            ]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): View
    {
        $this->authorize('view', $leaveRequest);

        $leaveRequest->load([
            'employee:id,name,employee_code,department_id,user_id',
            'leaveType', 'days', 'approvals.expectedApprover:id,name', 'approvals.actor:id,name',
        ]);

        return view('admin.hr.leaves.show', [
            'request' => $leaveRequest,
            'showReason' => $request->user()?->can('viewReason', $leaveRequest) === true,
            'available' => $this->balances->available(
                $leaveRequest->employee,
                $leaveRequest->leaveType,
                $leaveRequest->from_date->copy()
            ),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('approve', $leaveRequest);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:255']]);

        $this->leaves->approveLevel($leaveRequest, $request->user(), $data['comment'] ?? null);

        $fresh = $leaveRequest->fresh();

        return back()->with('toast', [
            'type' => 'success',
            'message' => $fresh->status === LeaveRequestStatus::Approved
                ? 'Approved. The days are consumed and written onto the calendar.'
                : sprintf('Approved at this level. Now waiting on level %d.', (int) $fresh->current_approval_level),
        ]);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('reject', $leaveRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->leaves->reject($leaveRequest, $request->user(), $data['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Refused. The days went back to the balance.']);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('changeStatus', $leaveRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->leaves->cancel($leaveRequest, $request->user(), $data['reason']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Cancelled. Every day it touched was re-resolved from its punches.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        $employees = Employee::query()->whereIn('status', ['active', 'probation']);
        $this->scopes->apply($employees, $request->user(), 'id');

        return [
            'employees' => $employees->orderBy('name')->get(['id', 'name', 'employee_code']),
            'types' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(),
            'portions' => LeaveDayPortion::options(),
        ];
    }
}
