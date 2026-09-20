<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use App\Enums\LeaveApprovalStatus;
use App\Enums\LeaveDayPortion;
use App\Models\Hr\LeaveApproval;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `/admin/my/leave` — my own requests and my own balance (phase-07 §7.7, §8.16).
 *
 * The apply form shows the balance **before** the dates, because "how many days do I have?" is the
 * question somebody actually opens this screen with.
 *
 * `/admin/my/approvals` is the other half: the inbox of requests waiting on **me**, which is a different
 * question from "requests I can see".
 */
final class LeaveController extends SelfServiceController
{
    public function __construct(
        EmployeeScopeResolver $scopes,
        private readonly LeaveRequestService $leaves,
        private readonly LeaveBalanceService $balances,
    ) {
        parent::__construct($scopes);
    }

    public function index(Request $request): View
    {
        $employee = $this->employee($request);
        $year = $this->balances->leaveYearFor(now())['year'];

        return view('admin.hr.my.leave', [
            'employee' => $employee,
            'requests' => $employee->leaveRequests()
                ->with('leaveType:id,name,color')
                ->orderByDesc('from_date')
                ->get(),
            'balances' => $employee->leaveBalances()
                ->with('leaveType:id,name,color')
                ->where('leave_year', $year)
                ->get(),
            'year' => $year,
        ]);
    }

    public function create(Request $request): View
    {
        $employee = $this->employee($request);
        $year = $this->balances->leaveYearFor(now())['year'];

        return view('admin.hr.my.leave-apply', [
            'employee' => $employee,
            'types' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(),
            'balances' => $employee->leaveBalances()->where('leave_year', $year)->get()->keyBy('leave_type_id'),
            'portions' => LeaveDayPortion::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'day_portion' => ['required', Rule::enum(LeaveDayPortion::class)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'contact_during_leave' => ['nullable', 'string', 'max:64'],
        ]);

        $leave = $this->leaves->apply(
            employee: $employee->load(['manager', 'department']),
            type: LeaveType::query()->findOrFail($data['leave_type_id']),
            from: Carbon::parse($data['from_date']),
            to: Carbon::parse($data['to_date']),
            portion: LeaveDayPortion::from($data['day_portion']),
            reason: $data['reason'],
            contact: $data['contact_during_leave'] ?? null,
            actor: $request->user(),
        );

        return redirect()
            ->route('admin.my.leave.show', $leave)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s filed — %s day(s) counted.', $leave->request_number, rtrim(rtrim((string) $leave->total_days, '0'), '.')),
            ]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): View
    {
        $employee = $this->employee($request);
        $this->assertOwn($employee, $leaveRequest->employee_id);

        $leaveRequest->load(['leaveType', 'days', 'approvals.expectedApprover:id,name', 'approvals.actor:id,name']);

        return view('admin.hr.my.leave-show', ['employee' => $employee, 'request' => $leaveRequest]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $employee = $this->employee($request);
        $this->assertOwn($employee, $leaveRequest->employee_id);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->leaves->cancel($leaveRequest, $request->user(), $data['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Withdrawn. The days went back to your balance.']);
    }

    /**
     * The approval inbox (§8.17): requests waiting on **me**, by name or by the fallback permission.
     */
    public function approvals(Request $request): View
    {
        $employee = $this->employee($request);

        $mine = LeaveApproval::query()
            ->with(['leaveRequest.employee:id,name,employee_code', 'leaveRequest.leaveType:id,name'])
            ->where('status', LeaveApprovalStatus::Pending)
            ->where('expected_approver_user_id', $request->user()?->getKey())
            ->get();

        $byPermission = collect();

        if ($request->user()?->can('leaves.approve')) {
            $byPermission = LeaveApproval::query()
                ->with(['leaveRequest.employee:id,name,employee_code', 'leaveRequest.leaveType:id,name'])
                ->where('status', LeaveApprovalStatus::Pending)
                ->whereNull('expected_approver_user_id')
                ->whereHas('leaveRequest', fn ($query) => $query->where('employee_id', '<>', $employee->getKey()))
                ->get();
        }

        return view('admin.hr.my.approvals', [
            'employee' => $employee,
            'named' => $mine->filter(fn (LeaveApproval $row): bool => $row->leaveRequest?->employee_id !== $employee->getKey()),
            'byPermission' => $byPermission,
        ]);
    }
}
