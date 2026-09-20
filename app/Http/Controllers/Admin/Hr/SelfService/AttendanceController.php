<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr\SelfService;

use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceSource;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceCorrection;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\Exceptions\HrRuleException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `/admin/my/attendance` — my own days, and my own punches (phase-07 §7.7, §8.21).
 *
 * **Self check-in is a setting and an IP list.** When `hr.self_check_in_enabled` is off, or the request
 * comes from outside `hr.self_check_in_ip_whitelist`, the punch is refused and the refusal is logged with
 * the IP — somebody clocking in from home is a conversation, not a silent success.
 */
final class AttendanceController extends SelfServiceController
{
    public function __construct(
        EmployeeScopeResolver $scopes,
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrectionService $corrections,
    ) {
        parent::__construct($scopes);
    }

    public function index(Request $request): View
    {
        $employee = $this->employee($request);

        $month = ($request->query('month') === null ? now() : Carbon::parse((string) $request->query('month')))->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return view('admin.hr.my.attendance', [
            'employee' => $employee,
            'month' => $month,
            'rows' => $employee->attendances()
                ->whereBetween('attendance_date', [$month->toDateString(), $end->toDateString()])
                ->orderByDesc('attendance_date')
                ->get(),
            'today' => $employee->attendances()
                ->whereDate('attendance_date', now()->toDateString())
                ->first(),
            'summary' => AttendanceMonthlySummary::query()
                ->where('employee_id', $employee->getKey())
                ->where('period_year', $month->year)
                ->where('period_month', $month->month)
                ->first(),
            'corrections' => AttendanceCorrection::query()
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('requested_at')
                ->limit(10)
                ->get(),
            'correctionTypes' => AttendanceCorrectionType::options(),
            'selfPunchEnabled' => (bool) setting('hr.self_check_in_enabled', true),
        ]);
    }

    public function checkIn(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $this->assertSelfPunchAllowed($request);

        $this->attendance->checkIn($employee, now(), AttendanceSource::SelfWeb, $request->ip());

        return back()->with('toast', ['type' => 'success', 'message' => 'Checked in at '.now()->format('H:i').'.']);
    }

    public function checkOut(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $this->assertSelfPunchAllowed($request);

        $this->attendance->checkOut($employee, now(), AttendanceSource::SelfWeb, $request->ip());

        return back()->with('toast', ['type' => 'success', 'message' => 'Checked out at '.now()->format('H:i').'.']);
    }

    /**
     * Ask for a day to be fixed. Nothing is written onto the day until somebody approves it.
     */
    public function requestCorrection(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'attendance_date' => ['required', 'date'],
            'correction_type' => ['required', Rule::enum(AttendanceCorrectionType::class)],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $values = array_filter([
            'check_in_at' => $data['check_in_at'] ?? null,
            'check_out_at' => $data['check_out_at'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $this->corrections->request(
            employee: $employee,
            date: Carbon::parse($data['attendance_date']),
            type: AttendanceCorrectionType::from($data['correction_type']),
            newValues: $values,
            reason: $data['reason'],
            actor: $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Asked for. HR sees it in the correction queue; nothing changes until they decide.',
        ]);
    }

    /**
     * The two gates of §6.2 on a self-service punch, refused with the reason rather than a blank 403.
     */
    private function assertSelfPunchAllowed(Request $request): void
    {
        if (! setting('hr.self_check_in_enabled', true)) {
            throw HrRuleException::refuse('check_in_at',
                'Punching in from this screen is switched off. Your attendance is recorded by HR or by the '
                .'kiosk.');
        }

        $whitelist = trim((string) setting('hr.self_check_in_ip_whitelist', ''));

        if ($whitelist === '') {
            return;
        }

        $allowed = array_filter(array_map('trim', preg_split('/[\s,]+/', $whitelist) ?: []));

        if (! in_array((string) $request->ip(), $allowed, true)) {
            activity('attendance')
                ->withProperties(['ip' => $request->ip()])
                ->log('Self check-in refused: the address is outside the allowed list.');

            throw HrRuleException::refuse('check_in_at', sprintf(
                'Attendance can only be punched from the office network. This request came from %s.',
                (string) $request->ip()
            ));
        }
    }
}
