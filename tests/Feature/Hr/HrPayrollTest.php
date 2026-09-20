<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\AdvanceRecoveryType;
use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LeaveDayPortion;
use App\Enums\LeaveRequestStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveBalanceTransaction;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\WorkShift;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\AttendanceSummaryService;
use App\Services\Hr\EmployeeScopeResolver;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\Exceptions\AppendOnlyRowException;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Hr\Exceptions\ImmutablePayrollAttributeException;
use App\Services\Hr\Exceptions\LockedAttendanceException;
use App\Services\Hr\HolidayService;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveRequestService;
use App\Services\Hr\PayrollCalculator;
use App\Services\Hr\PayrollRunService;
use App\Services\Hr\SalaryStructureService;
use App\Services\Hr\WorkCalendarService;
use App\Support\Format;
use App\Support\Hr\PayrollInputs;
use App\Support\Hr\PayrollPeriod;
use App\Support\Hr\PayslipLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 7 HR: the screens answer, the permission behind each one bites, money is withheld rather than
 * blanked, and the invariants that decide somebody's salary hold through the services.
 *
 * This is the integration gate, not the phase's full acceptance suite (phase-07 §11, FT-HR-01 … FT-HR-62),
 * which is still owed.
 */
final class HrPayrollTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** HR screens, each with the permission its route really checks. */
    private const ADMIN_SCREENS = [
        ['admin.employees.index', 'employees.view_any'],
        ['admin.employees.create', 'employees.create'],
        ['admin.departments.index', 'departments.view_any'],
        ['admin.designations.index', 'designations.view_any'],
        ['admin.work-shifts.index', 'work_shifts.view_any'],
        ['admin.holidays.index', 'holidays.view_any'],
        ['admin.holidays.calendar', 'holidays.view_any'],
        ['admin.leave-types.index', 'leave_types.view_any'],
        ['admin.salary-components.index', 'salary_components.view_any'],
        ['admin.attendance.index', 'attendance.view_any'],
        ['admin.attendance.monthly', 'attendance.view_any'],
        ['admin.attendance-corrections.index', 'attendance.view_any'],
        ['admin.attendance-summaries.index', 'attendance.view_reports'],
        ['admin.leaves.index', 'leaves.view_any'],
        ['admin.leaves.calendar', 'leaves.view_any'],
        ['admin.leaves.create', 'leaves.create'],
        ['admin.leave-balances.index', 'leave_balances.view_any'],
        ['admin.salary-structures.index', 'salary_structures.view_any'],
        ['admin.advances.index', 'employee_advances.view_any'],
        ['admin.advances.create', 'employee_advances.create'],
        ['admin.payroll-runs.index', 'payroll.view_any'],
        ['admin.payroll-runs.create', 'payroll.create'],
        ['admin.payslips.index', 'salary_slips.view_any'],
    ];

    #[Test]
    public function every_hr_screen_answers_for_a_super_admin(): void
    {
        $super = $this->createSuperAdmin();

        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($super)
                ->get(route($route))
                ->assertOk(sprintf('%s did not answer for a Super Admin.', $route));
        }
    }

    #[Test]
    public function an_hr_screen_is_refused_without_its_permission_and_opens_with_it(): void
    {
        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($this->createUserWithPermissions([]))
                ->get(route($route))
                ->assertForbidden(sprintf('%s opened without %s.', $route, $permission));

            $this->actingAs($this->createUserWithPermissions([$permission]))
                ->get(route($route))
                ->assertOk(sprintf('%s stayed shut for a holder of %s.', $route, $permission));
        }
    }

    #[Test]
    public function the_salary_column_is_absent_without_view_financial(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Paid Person');
        $this->structure($employee, '77000');

        $this->actingAs($this->createUserWithPermissions(['employees.view_any', 'employees.view', 'employees.view_financial']))
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertSee('89,700', false);

        // Not merely hidden: the column never reaches the response.
        $this->actingAs($this->createUserWithPermissions(['employees.view_any', 'employees.view']))
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertDontSee('89,700', false);
    }

    #[Test]
    public function an_employee_outside_the_viewers_window_answers_404_not_403(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $stranger = $this->employee('Somebody Else');

        // Holds `employees.view` but has no employee record, so the row is out of reach (§9).
        $this->actingAs($this->createUserWithPermissions(['employees.view']))
            ->get(route('admin.employees.show', $stranger))
            ->assertNotFound();
    }

    #[Test]
    public function a_line_manager_sees_their_tree_and_nobody_else(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $leadUser = User::factory()->create();
        $lead = $this->employee('Team Lead', ['user_id' => $leadUser->getKey()]);
        $report = $this->employee('Direct Report', ['reports_to_id' => $lead->getKey()]);
        $outsider = $this->employee('Another Team');

        $this->grantPermissions($leadUser, 'leaves.approve', 'employees.view');
        $this->forgetPermissionCache();

        $scopes = app(EmployeeScopeResolver::class);
        $scopes->forget();

        $visibility = $scopes->visibility($leadUser->fresh());

        $this->assertSame('team', $visibility['mode']);
        $this->assertEqualsCanonicalizing(
            [$lead->getKey(), $report->getKey()],
            $visibility['ids'],
            'A manager sees themselves and their reports.'
        );
        $this->assertFalse($scopes->canSee($leadUser->fresh(), $outsider), 'And nobody else.');
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance — §6.3
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_first_punch_of_the_day_wins_and_is_never_overwritten(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Punch Twice');
        $service = app(AttendanceService::class);

        $first = $service->checkIn($employee, Carbon::parse('2026-03-02 09:05:00'), AttendanceSource::Kiosk);
        $second = $service->checkIn($employee, Carbon::parse('2026-03-02 09:40:00'), AttendanceSource::Kiosk);

        $this->assertSame($first->getKey(), $second->getKey(), 'A second punch is the same row (HR-1).');
        $this->assertSame('2026-03-02 09:05:00', $second->check_in_at->toDateTimeString());
        $this->assertSame(1, Attendance::query()->where('employee_id', $employee->getKey())->count());
    }

    #[Test]
    public function a_shift_window_is_business_time_and_a_punch_is_measured_against_it(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Nine To Five');
        $service = app(AttendanceService::class);

        // Nine in the morning where the business is — stamps are stored UTC (D61), so the punch is
        // built in the business timezone and converted, exactly as a real request would.
        $businessNine = Carbon::parse('2026-03-02 09:00:00', Format::timezone())->utc();

        $row = $service->checkIn($employee, $businessNine, AttendanceSource::Kiosk);
        $row = $service->checkOut($employee, $businessNine->copy()->addHours(8), AttendanceSource::Kiosk, null, Carbon::parse('2026-03-02'));

        $this->assertSame(0, $row->late_minutes, 'A punch at the shift start is not late.');
        $this->assertSame(0, $row->early_leave_minutes);
        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(
            '09:00',
            app_time($row->expected_in_at, 'H:i'),
            'The expected window reads as the business wall clock it was configured with.'
        );
        $this->assertSame('2026-03-02', $row->attendance_date->toDateString());

        // Half an hour late is half an hour late, grace and all.
        $late = $this->employee('Late Riser');
        $service->checkIn($late, $businessNine->copy()->addMinutes(45), AttendanceSource::Kiosk);
        $lateRow = $service->checkOut($late, $businessNine->copy()->addHours(8), AttendanceSource::Kiosk, null, Carbon::parse('2026-03-02'));

        $this->assertSame(30, $lateRow->late_minutes, '45 minutes, less the 15-minute grace.');
        $this->assertSame(AttendanceStatus::Late, $lateRow->status, 'Late is the status; the minutes are stored either way.');
    }

    #[Test]
    public function a_weekend_is_never_a_loss_of_pay(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Weekend Worker');
        $service = app(AttendanceService::class);

        $sunday = $service->resolve($service->rowFor($employee, Carbon::parse('2026-03-08')));

        $this->assertSame(AttendanceStatus::Holiday, $sunday->status);
        $this->assertSame('1.0000', (string) $sunday->payable_factor);
        $this->assertSame('0.0000', $sunday->lossOfPayDays(), 'R2: only a working day can lose pay.');
    }

    #[Test]
    public function an_unpaid_holiday_pays_nothing_and_a_paid_one_pays_the_day(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Holiday Person');
        $service = app(AttendanceService::class);

        $holiday = new Holiday;
        $holiday->forceFill([
            'holiday_date' => '2026-03-23', 'title' => 'Shutdown', 'holiday_type' => 'company',
            'is_paid' => false, 'is_active' => true,
        ])->save();

        app(WorkCalendarService::class)->forget();

        $row = $service->resolve($service->rowFor($employee, Carbon::parse('2026-03-23')));

        $this->assertSame(AttendanceStatus::Holiday, $row->status);
        $this->assertSame('0.0000', (string) $row->payable_factor, 'An unpaid shutdown must not silently pay people.');

        $holiday->forceFill(['is_paid' => true])->save();
        app(WorkCalendarService::class)->forget();

        $this->assertSame('1.0000', (string) $service->resolve($row->fresh())->payable_factor);
    }

    #[Test]
    public function a_manual_row_survives_the_nightly_recomputation(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Corrected Person');
        $service = app(AttendanceService::class);

        $row = $service->rowFor($employee, Carbon::parse('2026-03-04'));
        $row->forceFill([
            'is_manual' => true,
            'status' => AttendanceStatus::Present,
            'payable_factor' => '1.0000',
        ])->save();

        $service->resolve($row);

        $this->assertSame(AttendanceStatus::Present, $row->fresh()->status, 'A correction is not undone at 23:50.');
    }

    #[Test]
    public function attendance_behind_a_locked_payroll_cannot_be_recomputed(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Paid Month');
        $service = app(AttendanceService::class);

        $row = $service->rowFor($employee, Carbon::parse('2026-03-04'));
        $row->forceFill(['locked_at' => now()])->save();

        $this->expectException(LockedAttendanceException::class);

        $service->resolve($row->fresh());
    }

    #[Test]
    public function closing_a_day_twice_changes_nothing(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $this->employee('Day Close A');
        $this->employee('Day Close B');

        $service = app(AttendanceService::class);

        $first = $service->closeDay(Carbon::parse('2026-03-16'));
        $second = $service->closeDay(Carbon::parse('2026-03-16'));

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created'], 'closeDay is idempotent.');
    }

    #[Test]
    public function a_correction_leaves_the_same_evidence_whichever_door_it_came_through(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Evidence');
        $corrections = app(AttendanceCorrectionService::class);

        $correction = $corrections->applyDirect(
            employee: $employee,
            date: Carbon::parse('2026-03-05'),
            type: AttendanceCorrectionType::StatusChange,
            newValues: ['status' => 'present', 'payable_factor' => '1', 'locked_at' => now()->toDateTimeString()],
            reason: 'Worked from the client office all day.',
            actor: $actor,
        );

        $this->assertSame('hr_direct', $correction->source->value);
        $this->assertNotNull($correction->applied_at, 'An HR-direct correction is applied in the same act.');
        $this->assertSame(
            ['status', 'payable_factor'],
            array_keys($correction->new_values),
            'The whitelist keeps a correction away from the lock.'
        );

        $row = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', '2026-03-05')
            ->first();

        $this->assertTrue($row->is_manual);
        $this->assertNull($row->locked_at, 'A correction can never write the lock.');
    }

    /*
    |--------------------------------------------------------------------------
    | The monthly bridge — §6.4
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_full_month_of_attendance_is_payable_for_every_calendar_day(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Full Month');

        $this->workMonth($employee, 2026, 3);

        $summary = app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $this->assertSame('31.0000', (string) $summary->payable_days, 'R1: every row contributes.');
        $this->assertSame('0.0000', (string) $summary->lop_days, 'R2: nothing lost on a full month.');
        $this->assertSame(31, (int) $summary->calendar_days);
    }

    #[Test]
    public function two_absences_and_a_half_day_are_two_and_a_half_lost_days(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Partial Month');

        $this->workMonth($employee, 2026, 3, skip: ['2026-03-10', '2026-03-11'], half: ['2026-03-12']);

        $summary = app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $this->assertSame('2.5000', (string) $summary->lop_days);
        $this->assertSame('28.5000', (string) $summary->payable_days);
    }

    /*
    |--------------------------------------------------------------------------
    | Leave — §6.5
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function five_calendar_days_can_cost_three_quota_days_and_the_skipped_ones_are_kept(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Leave Taker');
        $type = $this->leaveType();

        app(HolidayService::class)->createRange(
            ['title' => 'Mid-week holiday', 'holiday_type' => 'public', 'is_paid' => true, 'is_active' => true],
            Carbon::parse('2026-03-19'),
        );

        app(LeaveBalanceService::class)->grantYear(2026, $employee);

        $request = app(LeaveRequestService::class)->apply(
            employee: $employee,
            type: $type,
            from: Carbon::parse('2026-03-17'),
            to: Carbon::parse('2026-03-20'),
            portion: LeaveDayPortion::FullDay,
            reason: 'Family wedding.',
            actor: $actor,
        );

        $this->assertSame('3.0000', (string) $request->total_days);
        $this->assertSame(4, $request->days()->count(), 'Every date is written, including the skipped one.');
        $this->assertFalse(
            (bool) $request->days()->whereDate('leave_date', '2026-03-19')->value('is_counted'),
            'The holiday inside the range costs no quota.'
        );
    }

    #[Test]
    public function nobody_approves_their_own_leave(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $selfUser = User::factory()->create();
        $employee = $this->employee('Self Approver', ['user_id' => $selfUser->getKey()]);
        $type = $this->leaveType();

        app(LeaveBalanceService::class)->grantYear(2026, $employee);

        $request = app(LeaveRequestService::class)->apply(
            employee: $employee,
            type: $type,
            from: Carbon::parse('2026-03-17'),
            to: Carbon::parse('2026-03-17'),
            portion: LeaveDayPortion::FullDay,
            reason: 'A day off.',
            actor: $actor,
        );

        $this->grantPermissions($selfUser, 'leaves.approve');
        $this->forgetPermissionCache();

        $this->expectException(HrRuleException::class);

        app(LeaveRequestService::class)->approveLevel($request, $selfUser->fresh());
    }

    #[Test]
    public function the_balance_is_always_exactly_the_sum_of_its_ledger(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Ledger Person');
        $type = $this->leaveType();
        $balances = app(LeaveBalanceService::class);

        $balances->grantYear(2026, $employee);

        $request = app(LeaveRequestService::class)->apply(
            employee: $employee,
            type: $type,
            from: Carbon::parse('2026-03-17'),
            to: Carbon::parse('2026-03-18'),
            portion: LeaveDayPortion::FullDay,
            reason: 'Two days.',
            actor: $actor,
        );

        $balances->assertConsistent($employee);

        app(LeaveRequestService::class)->approveLevel($request, $actor);
        $balances->assertConsistent($employee);

        $balances->adjust($employee, $type, '-1.5', 'Taken in the old system.', $actor);
        $balances->assertConsistent($employee);

        $this->assertSame(
            $balances->available($employee, $type, Carbon::parse('2026-03-01'), fresh: true),
            $balances->available($employee, $type, Carbon::parse('2026-03-01')),
            'HR-7: the cache and the ledger agree.'
        );
    }

    #[Test]
    public function a_leave_ledger_row_cannot_be_deleted(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $employee = $this->employee('Append Only');
        $type = $this->leaveType();

        app(LeaveBalanceService::class)->grantYear(2026, $employee);

        $entry = LeaveBalanceTransaction::query()->where('employee_id', $employee->getKey())->firstOrFail();

        $this->expectException(AppendOnlyRowException::class);

        $entry->delete();
    }

    #[Test]
    public function cancelling_an_approved_leave_puts_the_days_back_and_re_resolves_the_calendar(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Cancelled Leave');
        $type = $this->leaveType();
        $balances = app(LeaveBalanceService::class);
        $leaves = app(LeaveRequestService::class);

        $balances->grantYear(2026, $employee);

        $request = $leaves->apply(
            employee: $employee,
            type: $type,
            from: Carbon::parse('2026-03-17'),
            to: Carbon::parse('2026-03-17'),
            portion: LeaveDayPortion::FullDay,
            reason: 'One day.',
            actor: $actor,
        );

        $leaves->approveLevel($request, $actor);

        $this->assertSame(
            AttendanceStatus::OnLeave,
            Attendance::query()->where('employee_id', $employee->getKey())
                ->whereDate('attendance_date', '2026-03-17')->value('status')
        );

        $leaves->cancel($request->fresh(), $actor, 'Plans changed.');

        $this->assertSame(LeaveRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('14.0000', $balances->available($employee, $type, Carbon::parse('2026-03-01')));
        $this->assertSame(
            AttendanceStatus::Absent,
            Attendance::query()->where('employee_id', $employee->getKey())
                ->whereDate('attendance_date', '2026-03-17')->value('status'),
            'The day goes back to being judged from its punches.'
        );
        $balances->assertConsistent($employee);
    }

    /*
    |--------------------------------------------------------------------------
    | Payroll — §6.6, §6.7, §6.8
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_worked_example_of_the_contract_comes_out_to_the_paisa(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Worked Example');
        $this->structure($employee, '40000');

        $summary = $this->summaryRow($employee, 2026, 3, payable: '28.5000', lop: '2.5000');

        $advance = app(AdvanceService::class)->request($employee, '20000', 'House repairs.', 4, 2026, 3, $actor);
        app(AdvanceService::class)->approve($advance, $actor);
        app(AdvanceService::class)->disburse($advance, PaymentMethod::Cash, on: Carbon::parse('2026-02-20'));

        $draft = app(PayrollCalculator::class)->build(
            $employee,
            PayrollPeriod::of(2026, 3),
            $summary,
            app(SalaryStructureService::class)->effectiveOn($employee, Carbon::parse('2026-03-31')),
            new PayrollInputs(
                advancesDue: app(AdvanceService::class)->dueFor($employee, 2026, 3)->all(),
                manualTaxAmount: '1200',
            ),
        );

        $this->assertSame('1580.65', $draft->perDayAmount, '49,000 / 31, quantised when it is stored.');
        $this->assertSame('49000.00', $draft->grossEarnings);
        $this->assertSame('3951.63', $draft->groupTotal(SalaryComponentGroup::UnpaidLeave));
        $this->assertSame('5000.00', $draft->groupTotal(SalaryComponentGroup::AdvanceRecovery));
        $this->assertSame('10151.63', $draft->totalDeductions);
        $this->assertSame('38848.37', $draft->netSalary);
        $this->assertTrue($draft->totalsAgree(), 'HR-13: the lines add up to the totals.');
    }

    #[Test]
    public function generating_the_same_run_twice_produces_the_same_figures(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Deterministic');
        $this->structure($employee, '40000');
        $this->summaryRow($employee, 2026, 3, payable: '28.5000', lop: '2.5000');

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $actor);

        $runs->generate($run, null, $actor);
        $first = (string) PayrollRunItem::query()->where('payroll_run_id', $run->getKey())->value('net_salary');

        $runs->generate($run->fresh(), null, $actor);
        $second = (string) PayrollRunItem::query()->where('payroll_run_id', $run->getKey())->value('net_salary');

        $this->assertSame($first, $second, 'The calculator is a pure function (FT-HR-44).');
    }

    #[Test]
    public function an_employee_with_no_summary_is_skipped_by_name_not_paid_zero(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('No Summary');
        $this->structure($employee, '30000');

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $actor);
        $report = $runs->generate($run, null, $actor);

        $this->assertSame(0, $report['generated']);
        $this->assertSame('no_attendance_summary', $report['skipped'][$employee->getKey()] ?? null);
        $this->assertSame(0, PayrollRunItem::query()->where('payroll_run_id', $run->getKey())->count());
    }

    #[Test]
    public function a_second_regular_run_for_one_month_is_refused_naming_the_first(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $runs = app(PayrollRunService::class);
        $first = $runs->create(2026, 3, actor: $actor);

        try {
            $runs->create(2026, 3, actor: $actor);
            $this->fail('A second regular run for the same month was allowed.');
        } catch (HrRuleException $exception) {
            $this->assertStringContainsString($first->run_number, $exception->getMessage());
        }
    }

    #[Test]
    public function locking_a_run_freezes_its_slips_and_the_attendance_behind_them(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Locked Month');
        $this->structure($employee, '40000');
        $this->workMonth($employee, 2026, 3);
        app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $actor);
        $runs->generate($run, null, $actor);
        $runs->lock($run->fresh(), $actor);

        $item = PayrollRunItem::query()->where('payroll_run_id', $run->getKey())->firstOrFail();

        $this->assertGreaterThan(
            0,
            Attendance::query()->where('employee_id', $employee->getKey())->whereNotNull('locked_at')->count(),
            'HR-18: the period is frozen with the run.'
        );

        $this->assertNotNull(
            AttendanceMonthlySummary::query()
                ->where('employee_id', $employee->getKey())
                ->where('period_month', 3)
                ->value('locked_at')
        );

        $this->expectException(ImmutablePayrollAttributeException::class);

        $item->forceFill(['net_salary' => '1.00'])->save();
    }

    #[Test]
    public function a_correction_never_touches_the_original_slip(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Corrected Slip');
        $this->structure($employee, '40000');
        $this->summaryRow($employee, 2026, 3, payable: '31.0000', lop: '0.0000');

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $actor);
        $runs->generate($run, null, $actor);
        $runs->lock($run->fresh(), $actor);

        $original = PayrollRunItem::query()->where('payroll_run_id', $run->getKey())->firstOrFail();
        $before = (string) $original->net_salary;

        $correction = $runs->issueCorrection(
            $original,
            [new PayslipLine(
                componentCode: 'FUEL',
                componentName: 'Fuel allowance (missed)',
                group: SalaryComponentGroup::Allowance,
                side: SalaryComponentType::Earning,
                calculation: SalaryComponentCalculation::Fixed,
                amount: '2000.00',
            )],
            'The fuel allowance was missed.',
            $actor,
        );

        $this->assertSame($before, (string) $original->fresh()->net_salary, 'The original is untouched, for ever.');
        $this->assertSame($original->getKey(), $correction->corrects_item_id);
        $this->assertSame('2000.00', (string) $correction->net_salary);
        $this->assertSame(
            1,
            PayrollRun::query()->where('period_year', 2026)->where('period_month', 3)
                ->where('run_type', 'regular')->count(),
            'A correction run does not occupy the regular slot.'
        );
    }

    #[Test]
    public function an_advance_can_never_be_recovered_for_more_than_it_was(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Over Recovered');
        $advances = app(AdvanceService::class);

        $advance = $advances->request($employee, '10000', 'Emergency.', 2, 2026, 3, $actor);
        $advances->approve($advance, $actor);
        $advances->disburse($advance, PaymentMethod::Cash);

        $advances->recordRecovery($advance->fresh(), '4000', null, AdvanceRecoveryType::Manual, 'Part paid.', $actor);

        try {
            $advances->recordRecovery($advance->fresh(), '7000', null, AdvanceRecoveryType::Manual, 'Too much.', $actor);
            $this->fail('Over-recovery was allowed (HR-19).');
        } catch (HrRuleException $exception) {
            $this->assertStringContainsString('6000.00', $exception->getMessage(), 'The refusal names what is left.');
        }

        $this->assertSame('6000.00', (string) $advance->fresh()->outstanding_amount);
    }

    #[Test]
    public function a_salary_rate_is_never_updated_only_superseded(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Raised');
        $first = $this->structure($employee, '40000');

        $second = app(SalaryStructureService::class)->createVersion(
            employee: $employee,
            effectiveFrom: Carbon::parse('2026-07-01'),
            basicSalary: '44000',
            components: [],
            reason: 'Annual increment 2026.',
            actorId: $actor->getKey(),
        );

        $this->assertSame(2, (int) $second->version);
        $this->assertSame('2026-06-30', $first->fresh()->effective_to->toDateString());
        $this->assertSame('40000.00', (string) $first->fresh()->basic_salary, 'The old version still says what it said.');
        $this->assertSame(
            1,
            (int) app(SalaryStructureService::class)->effectiveOn($employee, Carbon::parse('2026-03-31'))->version,
            'March still reads the version that was in force in March.'
        );
    }

    #[Test]
    public function a_leaver_is_still_paid_for_the_month_they_left(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $employee = $this->employee('Leaver');

        app(EmployeeService::class)->exit(
            $employee,
            Carbon::parse('2026-03-31'),
            EmployeeStatus::Resigned,
            'Moving abroad.',
        );

        $employee = $employee->fresh();

        $this->assertTrue(
            $employee->isPayrollEligibleOn(Carbon::parse('2026-03-01')),
            'Somebody who left in March is paid for March (§6.10 #2).'
        );
        $this->assertFalse(
            $employee->isPayrollEligibleOn(Carbon::parse('2026-04-01')),
            'And not for April.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Self-service — §7.7, §9
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function self_service_is_404_for_somebody_who_is_not_an_employee(): void
    {
        $user = $this->createUserWithPermissions(['employee_self_service.view']);

        $this->actingAs($user)
            ->get(route('admin.my.profile'))
            ->assertNotFound();
    }

    #[Test]
    public function an_employee_reaches_their_own_slip_and_404s_on_somebody_elses(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $mineUser = $this->createUserWithPermissions([
            'employee_self_service.view',
            'employee_self_service.view_financial',
        ]);
        $mine = $this->employee('Mine', ['user_id' => $mineUser->getKey()]);
        $theirs = $this->employee('Theirs');

        $this->structure($mine, '30000');
        $this->structure($theirs, '30000');
        $this->summaryRow($mine, 2026, 3, payable: '31.0000', lop: '0.0000');
        $this->summaryRow($theirs, 2026, 3, payable: '31.0000', lop: '0.0000');

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $actor);
        $runs->generate($run, null, $actor);

        $mySlip = PayrollRunItem::query()->where('employee_id', $mine->getKey())->firstOrFail();
        $theirSlip = PayrollRunItem::query()->where('employee_id', $theirs->getKey())->firstOrFail();

        $this->actingAs($mineUser->fresh())
            ->get(route('admin.my.payslips.show', $mySlip))
            ->assertOk();

        $this->actingAs($mineUser->fresh())
            ->get(route('admin.my.payslips.show', $theirSlip))
            ->assertNotFound('Somebody else\'s slip is a 404, not a 403.');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function department(): Department
    {
        return Department::query()->firstOrCreate(['code' => 'HRT'], ['name' => 'HR Test']);
    }

    private function shift(): WorkShift
    {
        $shift = WorkShift::query()->where('code', 'HRT-D')->first();

        if ($shift !== null) {
            return $shift;
        }

        $shift = new WorkShift;
        $shift->forceFill([
            'code' => 'HRT-D', 'name' => 'HR Test Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 0, 'grace_in_minutes' => 15, 'grace_out_minutes' => 10,
            'min_full_day_minutes' => 400, 'min_half_day_minutes' => 200, 'expected_minutes' => 480,
            'crosses_midnight' => false, 'weekly_off_days' => ['sunday'], 'is_default' => false, 'is_active' => true,
        ])->save();

        return $shift->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, array $attributes = []): Employee
    {
        $employee = new Employee;
        $employee->forceFill(array_merge([
            'employee_code' => 'EMP-'.str_pad((string) (Employee::query()->withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'department_id' => $this->department()->getKey(),
            'work_shift_id' => $this->shift()->getKey(),
            'name' => $name,
            'joining_date' => '2025-01-01',
            'employment_type' => 'full_time',
            'status' => 'active',
        ], $attributes))->save();

        return $employee->fresh();
    }

    private function leaveType(): LeaveType
    {
        $type = LeaveType::query()->where('code', 'HRT-AL')->first();

        if ($type !== null) {
            return $type;
        }

        $type = new LeaveType;
        $type->forceFill([
            'code' => 'HRT-AL', 'name' => 'Annual', 'annual_quota_days' => '14.00', 'is_paid' => true,
            'accrual_method' => 'annual_grant', 'excludes_weekends' => true, 'excludes_holidays' => true,
            'allow_half_day' => true, 'approval_levels' => 1, 'color' => 'sky', 'is_active' => true,
        ])->save();

        return $type->fresh();
    }

    /**
     * Basic plus a 10% house allowance and a fixed 5,000 fuel allowance — the contract's worked example.
     */
    private function structure(Employee $employee, string $basic): SalaryStructure
    {
        $house = SalaryComponent::query()->where('code', 'HRT-HOUSE')->first();

        if ($house === null) {
            $house = new SalaryComponent;
            $house->forceFill([
                'code' => 'HRT-HOUSE', 'name' => 'House allowance',
                'component_group' => SalaryComponentGroup::Allowance->value, 'side' => 'earning',
                'calculation_type' => SalaryComponentCalculation::PercentageOfBasic->value,
                'default_rate' => '10.0000', 'is_taxable' => true, 'affects_gross' => true, 'is_active' => true,
            ])->save();
            $house = $house->fresh();
        }

        $fuel = SalaryComponent::query()->where('code', 'HRT-FUEL')->first();

        if ($fuel === null) {
            $fuel = new SalaryComponent;
            $fuel->forceFill([
                'code' => 'HRT-FUEL', 'name' => 'Fuel allowance',
                'component_group' => SalaryComponentGroup::Allowance->value, 'side' => 'earning',
                'calculation_type' => SalaryComponentCalculation::Fixed->value,
                'default_amount' => '5000.00', 'is_taxable' => true, 'affects_gross' => true, 'is_active' => true,
            ])->save();
            $fuel = $fuel->fresh();
        }

        return app(SalaryStructureService::class)->createVersion(
            employee: $employee,
            effectiveFrom: Carbon::parse('2025-01-01'),
            basicSalary: $basic,
            components: [
                ['salary_component_id' => $house->getKey(), 'rate' => '10'],
                ['salary_component_id' => $fuel->getKey(), 'amount' => '5000'],
            ],
            reason: 'Joining package.',
        );
    }

    /**
     * A month of attendance: every date closed, and every working day punched.
     *
     * @param  list<string>  $skip
     * @param  list<string>  $half
     */
    private function workMonth(Employee $employee, int $year, int $month, array $skip = [], array $half = []): void
    {
        $calendar = app(WorkCalendarService::class);
        $attendance = app(AttendanceService::class);

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $calendar->forget();

        foreach ($calendar->datesIn($start, $end) as $date) {
            $attendance->closeDay($date);
        }

        foreach ($calendar->datesIn($start, $end) as $date) {
            if (! $calendar->dayTypeFor($employee, $date)->countsTowardWorkingDays()) {
                continue;
            }

            if (in_array($date->toDateString(), $skip, true)) {
                continue;
            }

            $out = in_array($date->toDateString(), $half, true) ? 11 : 17;

            $attendance->checkIn($employee, $date->copy()->setTime(9, 0), AttendanceSource::Kiosk, null, $date);
            $attendance->checkOut($employee, $date->copy()->setTime($out, 0), AttendanceSource::Kiosk, null, $date);
        }
    }

    /**
     * A summary written directly, for the payroll tests that are not about attendance.
     */
    private function summaryRow(Employee $employee, int $year, int $month, string $payable, string $lop): AttendanceMonthlySummary
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        $summary = new AttendanceMonthlySummary;
        $summary->forceFill([
            'employee_id' => $employee->getKey(),
            'period_year' => $year,
            'period_month' => $month,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'calendar_days' => $start->daysInMonth,
            'working_days' => '26.0000',
            'present_days' => '23.5000',
            'payable_days' => $payable,
            'lop_days' => $lop,
            'generated_at' => now(),
        ])->save();

        return $summary->fresh();
    }
}
