<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceDeductionStatus;
use App\Enums\AttendanceDeductionType;
use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveDayPortion;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceDeduction;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveType;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\PayrollRunItemComponent;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructure;
use App\Models\Hr\WorkShift;
use App\Models\User;
use App\Notifications\Support\RegistryNotification;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceDeductionService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\AttendanceSummaryService;
use App\Services\Hr\Exceptions\HrRuleException;
use App\Services\Hr\LeaveBalanceService;
use App\Services\Hr\LeaveRequestService;
use App\Services\Hr\PayrollRunService;
use App\Services\Hr\SalaryStructureService;
use App\Services\Hr\WorkCalendarService;
use App\Support\Format;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Attendance-based salary deductions: the late-arrival share and the excess-absence day, from the attendance
 * row to the employee's notice, the payslip line, the lock and the refund.
 *
 * Every figure is the contract's worked structure — basic 40,000 + 10% house + 5,000 fuel = 49,000 gross —
 * in March 2026 (31 days, 1 March a Sunday), so the daily salary is 49,000 / 31 = 1,580.65 and a late
 * arrival at 25% costs 395.16. "Now" is 10 April 2026: March is the previous month, which is what a
 * settings save re-reads.
 */
final class AttendanceDeductionTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | What gets recorded
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function nothing_is_recorded_while_both_rules_are_off(): void
    {
        $this->signIn();
        $employee = $this->employee('Rules Off');
        $this->structure($employee, '40000');
        $this->rules(late: false, absence: false);

        $this->mark($employee, '2026-03-02', 'late');
        $this->mark($employee, '2026-03-03', 'absent', '0');
        $this->mark($employee, '2026-03-04', 'absent', '0');

        $report = app(AttendanceDeductionService::class)->syncMonth($employee, 2026, 3);

        $this->assertSame(0, $report['created']);
        $this->assertCount(0, $this->deductions($employee), 'A business that never switches the rules on is charged nothing.');
    }

    #[Test]
    public function a_late_arrival_costs_the_configured_share_of_payrolls_daily_salary(): void
    {
        $this->signIn();
        $employee = $this->employee('Late Once');
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: false);

        $this->mark($employee, '2026-03-02', 'late');

        $rows = $this->deductions($employee);
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame(AttendanceDeductionType::LateArrival, $row->deduction_type);
        $this->assertSame(AttendanceDeductionStatus::Pending, $row->status);
        $this->assertSame('2026-03-02', $row->deduction_date->toDateString());
        $this->assertSame([2026, 3], [(int) $row->period_year, (int) $row->period_month]);
        $this->assertSame('1580.65', (string) $row->daily_salary, '49,000 / 31 — the per-day amount payroll itself uses.');
        $this->assertSame('25.0000', (string) $row->rate_percentage);
        $this->assertSame('395.16', (string) $row->amount);
        $this->assertNotNull($row->attendance_id);

        $text = $row->explanation();
        $this->assertStringContainsString('25%', $text);
        $this->assertStringContainsString('395.16', $text);
        $this->assertStringContainsString('1,580.65', $text);
    }

    #[Test]
    public function the_shift_grace_minutes_decide_whether_an_arrival_is_late(): void
    {
        $this->signIn();
        $employee = $this->employee('Nearly Late');   // 09:00 shift, 15 grace minutes
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: false);

        $this->punch($employee, '2026-03-03', '09:10');
        $this->punch($employee, '2026-03-04', '09:40');

        $this->assertSame(AttendanceStatus::Present, $this->statusOn($employee, '2026-03-03'), 'Inside the grace window.');
        $this->assertSame(AttendanceStatus::Late, $this->statusOn($employee, '2026-03-04'));
        $this->assertSame(['2026-03-04'], $this->dates($employee));
    }

    #[Test]
    public function the_first_absence_is_free_and_each_one_after_costs_a_full_day(): void
    {
        $this->signIn();
        $employee = $this->employee('Often Away');
        $this->structure($employee, '40000');
        $this->rules(late: false, absence: true, allowance: 1);

        $this->mark($employee, '2026-03-02', 'absent', '0');
        $this->mark($employee, '2026-03-03', 'absent', '0');
        $this->mark($employee, '2026-03-04', 'absent', '0');
        $this->mark($employee, '2026-03-08', 'absent', '0');   // a Sunday: the shift's weekly off, never charged

        $rows = $this->deductions($employee);

        $this->assertSame(['2026-03-03', '2026-03-04'], $this->dates($employee));
        $this->assertSame([2, 3], $rows->map(fn (AttendanceDeduction $row): int => (int) $row->absence_sequence)->all());

        foreach ($rows as $row) {
            $this->assertSame(AttendanceDeductionType::ExcessAbsence, $row->deduction_type);
            $this->assertSame('1580.65', (string) $row->amount, 'A full day.');
            $this->assertSame('100.0000', (string) $row->rate_percentage);
            $this->assertSame(1, (int) $row->allowance_days);
        }
    }

    #[Test]
    public function syncing_again_changes_nothing_and_tells_the_employee_once(): void
    {
        Notification::fake();

        $this->signIn();
        $user = $this->createUserWithPermissions(['employee_self_service.view_financial']);
        $employee = $this->employee('Told Once', ['user_id' => $user->getKey()]);
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: true, allowance: 1);

        $this->mark($employee, '2026-03-02', 'late');
        $this->mark($employee, '2026-03-03', 'absent', '0');
        $this->mark($employee, '2026-03-04', 'absent', '0');

        $snapshot = fn (): array => $this->deductions($employee)
            ->map(fn (AttendanceDeduction $row): array => [$row->getKey(), (string) $row->amount, $row->status->value, $row->notified_state])
            ->all();

        $before = $snapshot();
        $this->assertCount(2, $before, 'One late arrival, and the second absence (the first is free).');

        $service = app(AttendanceDeductionService::class);

        foreach ([1, 2, 3] as $ignored) {
            $this->assertSame(
                ['created' => 0, 'updated' => 0, 'reversed' => 0, 'skipped' => null],
                $service->syncMonth($employee, 2026, 3),
            );
        }

        $this->assertSame($before, $snapshot(), 'The sync is idempotent.');

        Notification::assertSentToTimes($user, RegistryNotification::class, 2);
        Notification::assertSentTo(
            $user,
            RegistryNotification::class,
            fn (RegistryNotification $notice): bool => $notice->event->key === AttendanceDeductionService::EVENT_RECORDED
                && $notice->payload['deduction_type'] === 'late_arrival'
                && $notice->payload['deduction_date'] === '2026-03-02'
                && $notice->payload['daily_salary'] === '1580.65'
                && $notice->payload['amount'] === '395.16'
                && $notice->payload['status'] === 'pending'
                && str_contains((string) $notice->payload['body'], '25%'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance moves, and the deduction follows
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_correction_that_removes_the_late_arrival_reverses_the_pending_deduction(): void
    {
        $this->signIn();
        $employee = $this->employee('Corrected Late');
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: false);

        $this->mark($employee, '2026-03-02', 'late');
        $row = $this->deductions($employee)->firstOrFail();

        $this->mark($employee, '2026-03-02', 'present');
        $row->refresh();

        $this->assertSame(AttendanceDeductionStatus::Reversed, $row->status);
        $this->assertNull($row->reversed_by, 'Reversed by the sync, not by a person.');
        $this->assertStringContainsString('no longer a late arrival', (string) $row->reversal_reason);
        $this->assertStringEndsWith('(now present).', $row->explanation(), 'One full stop, not two.');

        $this->mark($employee, '2026-03-02', 'late');
        $row->refresh();

        $this->assertSame(AttendanceDeductionStatus::Pending, $row->status, 'The same row comes back; no duplicate.');
        $this->assertCount(1, $this->deductions($employee));
    }

    #[Test]
    public function approved_leave_reverses_an_absence_deduction_and_cancelling_it_brings_it_back(): void
    {
        $this->signIn();
        $employee = $this->employee('On Leave Later');
        $this->structure($employee, '40000');
        $this->rules(late: false, absence: true, allowance: 1);

        // Two days with no punches, closed as absences by the day close (not by hand, so leave can re-resolve them).
        app(AttendanceService::class)->closeDay(Carbon::parse('2026-03-02'));
        app(AttendanceService::class)->closeDay(Carbon::parse('2026-03-03'));

        $this->assertSame(['2026-03-03'], $this->dates($employee));
        $row = $this->deductions($employee)->firstOrFail();

        // The type must exist before the year is granted, or there is no balance to take the day from.
        $type = $this->leaveType();
        app(LeaveBalanceService::class)->grantYear(2026, $employee);
        $leaves = app(LeaveRequestService::class);

        $request = $leaves->apply(
            employee: $employee,
            type: $type,
            from: Carbon::parse('2026-03-03'),
            to: Carbon::parse('2026-03-03'),
            portion: LeaveDayPortion::FullDay,
            reason: 'Family wedding.',
            actor: $this->actor,
        );
        $leaves->approveLevel($request, $this->actor);

        $row->refresh();
        $this->assertSame(AttendanceDeductionStatus::Reversed, $row->status);
        $this->assertStringContainsString('approved leave', (string) $row->reversal_reason);

        $leaves->cancel($request->fresh(), $this->actor, 'Wedding postponed.');

        $row->refresh();
        $this->assertSame(AttendanceDeductionStatus::Pending, $row->status, 'Absent again, so charged again.');
    }

    #[Test]
    public function an_hr_reversal_of_a_pending_deduction_survives_every_later_sync(): void
    {
        $this->signIn();
        $employee = $this->employee('Waived Late');
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: false);

        $this->mark($employee, '2026-03-02', 'late');
        $service = app(AttendanceDeductionService::class);

        $service->reverse($this->deductions($employee)->firstOrFail(), 'Motorway closed, confirmed by the manager.', $this->actor);

        $service->syncMonth($employee, 2026, 3);
        $this->mark($employee, '2026-03-02', 'present');
        $this->mark($employee, '2026-03-02', 'late');

        $row = $this->deductions($employee)->firstOrFail();
        $this->assertSame(AttendanceDeductionStatus::Reversed, $row->status, 'HR decided; attendance changes do not undo it.');
        $this->assertSame($this->actor->getKey(), $row->reversed_by);

        $this->expectException(HrRuleException::class);
        $service->reverse($row, 'Reversing it twice.', $this->actor);
    }

    #[Test]
    public function switching_a_rule_off_reverses_pending_rows_in_any_open_month_and_on_again_restores_them(): void
    {
        $this->signIn();
        $employee = $this->employee('Switched');
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: false);

        $this->mark($employee, '2026-03-02', 'late');   // the previous month
        $this->mark($employee, '2026-06-02', 'late');   // a month a settings save does not re-read in full

        $settings = app(SettingsRepository::class);
        $settings->set('hr.late_arrival_deduction_enabled', false);

        foreach ($this->deductions($employee) as $row) {
            $this->assertSame(AttendanceDeductionStatus::Reversed, $row->status, $row->deduction_date->toDateString());
            $this->assertSame('Late arrival deductions were switched off.', $row->reversal_reason);
        }

        $settings->set('hr.late_arrival_deduction_enabled', true);

        foreach ($this->deductions($employee) as $row) {
            $this->assertSame(AttendanceDeductionStatus::Pending, $row->status, $row->deduction_date->toDateString());
        }

        $this->assertCount(2, $this->deductions($employee));
    }

    /*
    |--------------------------------------------------------------------------
    | Payroll
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function payroll_takes_each_rule_as_its_own_line_and_applies_exactly_those_rows_on_lock(): void
    {
        $this->signIn();
        $employee = $this->employee('Payroll Month');
        $this->structure($employee, '40000');
        $this->workMonth($employee, 2026, 3, skip: ['2026-03-03', '2026-03-04', '2026-03-05']);

        // The count-based late rule this one replaces while it is on.
        app(SettingsRepository::class)->set('hr.late_deduction_lates_per_day', 1);
        $this->rules(late: true, absence: true, allowance: 1);
        $this->mark($employee, '2026-03-09', 'late');

        app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $this->actor);
        $runs->generate($run, null, $this->actor);

        $item = $this->item($run->getKey(), $employee);
        $lines = $this->lines($item);

        $this->assertSame('395.16', $lines['ATT_LATE'] ?? null);
        $this->assertSame('3161.30', $lines['ATT_ABSENCE'] ?? null, 'Absences 2 and 3 of the month, a full day each.');
        $this->assertArrayNotHasKey('LATE', $lines, 'Lateness is never charged by both rules.');
        $this->assertArrayNotHasKey('UNPAID_LEAVE', $lines, 'Absences are never charged again as unpaid leave.');
        $this->assertTrue($item->totalsAgree());

        $runs->generate($run->fresh(), null, $this->actor);
        $item = $this->item($run->getKey(), $employee);

        $this->assertSame($lines, $this->lines($item), 'Generating again takes the same rows once.');
        $this->assertSame(3, AttendanceDeduction::query()->where('employee_id', $employee->getKey())->pending()->count());

        $runs->lock($run->fresh(), $this->actor);

        $rows = $this->deductions($employee);
        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertSame(AttendanceDeductionStatus::Applied, $row->status);
            $this->assertSame($item->getKey(), $row->payroll_run_item_id);
            $this->assertSame($run->getKey(), $row->payroll_run_id);
        }

        // March is history now: a resync is refused, and switching a rule off leaves applied rows alone.
        $this->assertSame('period_locked', app(AttendanceDeductionService::class)->syncMonth($employee, 2026, 3)['skipped']);

        app(SettingsRepository::class)->set('hr.late_arrival_deduction_enabled', false);

        $this->assertSame(3, AttendanceDeduction::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', AttendanceDeductionStatus::Applied->value)
            ->count());
    }

    #[Test]
    public function with_both_rules_off_payroll_builds_exactly_the_slip_it_always_built(): void
    {
        $this->signIn();
        $employee = $this->employee('Unchanged Slip');
        $this->structure($employee, '40000');
        $this->workMonth($employee, 2026, 3, skip: ['2026-03-03', '2026-03-04', '2026-03-05']);

        // The existing count-based late rule and the unpaid-leave line stay exactly as they were.
        app(SettingsRepository::class)->set('hr.late_deduction_lates_per_day', 1);
        $this->rules(late: false, absence: false);
        $this->mark($employee, '2026-03-09', 'late');

        app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $this->actor);
        $runs->generate($run, null, $this->actor);

        $item = $this->item($run->getKey(), $employee);
        $lines = $this->lines($item);

        $this->assertArrayNotHasKey('ATT_LATE', $lines);
        $this->assertArrayNotHasKey('ATT_ABSENCE', $lines);
        $this->assertArrayHasKey('LATE', $lines, 'The count-based late rule still applies while the new one is off.');
        $this->assertSame('4741.95', $lines['UNPAID_LEAVE'] ?? null, 'All three absent days, as unpaid leave, exactly as before.');
        $this->assertTrue($item->totalsAgree());
        $this->assertCount(0, $this->deductions($employee), 'No deduction row is ever written while both rules are off.');

        $runs->lock($run->fresh(), $this->actor);
        $this->assertCount(0, $this->deductions($employee));
    }

    #[Test]
    public function only_a_settings_editor_can_switch_the_rules(): void
    {
        $this->signIn();

        // Payroll rights are not settings rights: the write is refused on the server, not just hidden.
        $this->actingAs($this->createUserWithPermissions(['payroll.view_financial', 'payroll.change_status', 'employee_self_service.view_financial']))
            ->put(route('admin.settings.update', 'hr'), [
                'late_arrival_deduction_enabled' => '1',
                'excess_absence_deduction_enabled' => '1',
            ])
            ->assertForbidden();

        $this->assertFalse((bool) setting('hr.late_arrival_deduction_enabled', false));
        $this->assertFalse((bool) setting('hr.excess_absence_deduction_enabled', false));
    }

    #[Test]
    public function a_slip_whose_deductions_were_re_priced_after_generation_cannot_be_locked(): void
    {
        $this->signIn();
        $employee = $this->employee('Re-priced');
        $this->structure($employee, '40000');
        $this->workMonth($employee, 2026, 3);
        $this->rules(late: true, absence: false);
        $this->mark($employee, '2026-03-09', 'late');
        app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $this->actor);
        $runs->generate($run, null, $this->actor);

        app(SettingsRepository::class)->set('hr.late_arrival_deduction_percent', '30.0000');

        $row = $this->deductions($employee)->firstOrFail();
        $this->assertSame('474.20', (string) $row->amount, 'Re-priced at 30% while pending.');

        try {
            $runs->lock($run->fresh(), $this->actor);
            $this->fail('A slip that took 395.16 was locked while the row says 474.20.');
        } catch (HrRuleException $exception) {
            $this->assertStringContainsString('changed after the run was generated', $exception->getMessage());
        }

        $this->assertSame(AttendanceDeductionStatus::Pending, $row->fresh()->status);

        // Regenerating prices the row from the run's own snapshot again, and then the lock goes through.
        $runs->generate($run->fresh(), null, $this->actor);
        $this->assertSame('395.16', (string) $row->fresh()->amount);

        $runs->lock($run->fresh(), $this->actor);
        $this->assertSame(AttendanceDeductionStatus::Applied, $row->fresh()->status);
    }

    #[Test]
    public function reversing_an_applied_deduction_refunds_it_through_a_correction_slip(): void
    {
        $this->signIn();
        [$employee, $item, $row] = $this->lockedMonthWithOneLateArrival('Refunded');
        $netBefore = (string) $item->net_salary;

        $reversed = app(AttendanceDeductionService::class)->reverse($row, 'The office lift failed that morning.', $this->actor);

        $this->assertSame(AttendanceDeductionStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->correction_item_id);

        $correction = PayrollRunItem::query()->findOrFail($reversed->correction_item_id);
        $this->assertSame($item->getKey(), $correction->corrects_item_id);
        $this->assertSame('395.16', (string) $correction->net_salary);

        $refund = PayrollRunItemComponent::query()
            ->where('payroll_run_item_id', $correction->getKey())
            ->where('component_code', 'ATT_REFUND')
            ->firstOrFail();

        $this->assertSame('395.16', (string) $refund->amount);
        $this->assertSame('earning', $refund->side->value);
        $this->assertSame($netBefore, (string) $item->fresh()->net_salary, 'The locked slip itself is never touched.');
    }

    #[Test]
    public function an_applied_deduction_can_be_neither_edited_nor_deleted(): void
    {
        $this->signIn();
        [, , $row] = $this->lockedMonthWithOneLateArrival('Frozen');

        try {
            $row->forceFill(['amount' => '1.00'])->save();
            $this->fail('An applied deduction was edited.');
        } catch (LogicException) {
            $this->assertSame('395.16', (string) $row->fresh()->amount);
        }

        $this->expectException(LogicException::class);
        $row->fresh()->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Who sees what
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_review_screen_needs_payroll_rights_and_reversing_needs_change_status(): void
    {
        $this->signIn();
        $employee = $this->employee('Qasim Latecomer');
        $this->structure($employee, '40000');
        $this->rules(late: true, absence: false);
        $this->mark($employee, '2026-03-02', 'late');
        $row = $this->deductions($employee)->firstOrFail();

        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('admin.attendance-deductions.index'))
            ->assertForbidden();

        $viewer = $this->createUserWithPermissions(['payroll.view_financial', 'employees.view_any']);

        $this->actingAs($viewer)
            ->get(route('admin.attendance-deductions.index'))
            ->assertOk()
            ->assertSee('Qasim Latecomer')
            ->assertSee('395.16');

        $this->actingAs($viewer)
            ->post(route('admin.attendance-deductions.reverse', $row), ['reason' => 'A viewer may not do this.'])
            ->assertForbidden();
        $this->assertSame(AttendanceDeductionStatus::Pending, $row->fresh()->status);

        $approver = $this->createUserWithPermissions(['payroll.view_financial', 'payroll.change_status', 'employees.view_any']);

        $this->actingAs($approver)
            ->post(route('admin.attendance-deductions.reverse', $row), ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($approver)
            ->post(route('admin.attendance-deductions.reverse', $row), ['reason' => 'Doctor appointment, letter on file.'])
            ->assertRedirect();

        $this->assertSame(AttendanceDeductionStatus::Reversed, $row->fresh()->status);
        $this->assertSame($approver->getKey(), $row->fresh()->reversed_by);

        // Payroll rights without HR-wide visibility see nobody else's rows.
        $this->actingAs($this->createUserWithPermissions(['payroll.view_financial']))
            ->get(route('admin.attendance-deductions.index'))
            ->assertOk()
            ->assertDontSee('Qasim Latecomer');
    }

    #[Test]
    public function an_employee_sees_only_their_own_deductions(): void
    {
        $this->signIn();
        $amina = $this->createUserWithPermissions(['employee_self_service.view_financial']);
        $bilal = $this->createUserWithPermissions(['employee_self_service.view_financial']);

        $first = $this->employee('Amina Self', ['user_id' => $amina->getKey()]);
        $second = $this->employee('Bilal Self', ['user_id' => $bilal->getKey()]);
        $this->structure($first, '40000');    // 49,000 gross: late costs 395.16
        $this->structure($second, '60000');   // 71,000 gross: 2,290.32 a day, late costs 572.58
        $this->rules(late: true, absence: false);

        $this->mark($first, '2026-03-02', 'late');
        $this->mark($second, '2026-03-03', 'late');

        $this->actingAs($amina)
            ->get(route('admin.my.deductions.index'))
            ->assertOk()
            ->assertSee('395.16')
            ->assertDontSee('572.58');

        // An id in the request is never how self-service decides whose rows to show.
        $this->actingAs($amina)
            ->get(route('admin.my.deductions.index', ['employee_id' => $second->getKey()]))
            ->assertOk()
            ->assertDontSee('572.58');

        $this->actingAs($bilal)
            ->get(route('admin.my.deductions.index'))
            ->assertOk()
            ->assertSee('572.58')
            ->assertDontSee('395.16');

        $this->actingAs($amina)
            ->get(route('admin.attendance-deductions.index'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function signIn(): User
    {
        $this->actor = $this->createSuperAdmin();
        $this->actingAs($this->actor);

        return $this->actor;
    }

    private function rules(bool $late, bool $absence, int $allowance = 1, string $percent = '25.0000'): void
    {
        $settings = app(SettingsRepository::class);

        $settings->set('hr.late_arrival_deduction_percent', $percent);
        $settings->set('hr.monthly_absence_allowance_days', $allowance);
        $settings->set('hr.late_arrival_deduction_enabled', $late);
        $settings->set('hr.excess_absence_deduction_enabled', $absence);
    }

    /**
     * Mark a day by hand, the way HR's "mark attendance" does — through a correction.
     */
    private function mark(Employee $employee, string $date, string $status, string $payable = '1'): void
    {
        app(AttendanceCorrectionService::class)->applyDirect(
            employee: $employee,
            date: Carbon::parse($date),
            type: AttendanceCorrectionType::StatusChange,
            newValues: ['status' => $status, 'payable_factor' => $payable],
            reason: 'Marked by hand for the deduction tests.',
            actor: $this->actor,
        );
    }

    private function punch(Employee $employee, string $date, string $in, string $out = '17:00'): void
    {
        $day = Carbon::parse($date);
        $attendance = app(AttendanceService::class);

        // Stamps are stored UTC (D61): build the business wall-clock time and convert, as a real request does.
        $attendance->checkIn($employee, Carbon::parse($date.' '.$in, Format::timezone())->utc(), AttendanceSource::Kiosk, null, $day);
        $attendance->checkOut($employee, Carbon::parse($date.' '.$out, Format::timezone())->utc(), AttendanceSource::Kiosk, null, $day);
    }

    private function statusOn(Employee $employee, string $date): ?AttendanceStatus
    {
        return Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $date)
            ->first()?->status;
    }

    /**
     * @return Collection<int, AttendanceDeduction>
     */
    private function deductions(Employee $employee): Collection
    {
        return AttendanceDeduction::query()
            ->where('employee_id', $employee->getKey())
            ->orderBy('deduction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The dates of the rows still owed (pending or applied).
     *
     * @return list<string>
     */
    private function dates(Employee $employee): array
    {
        return $this->deductions($employee)
            ->reject(fn (AttendanceDeduction $row): bool => $row->status === AttendanceDeductionStatus::Reversed)
            ->map(fn (AttendanceDeduction $row): string => $row->deduction_date->toDateString())
            ->values()
            ->all();
    }

    private function item(int $runId, Employee $employee): PayrollRunItem
    {
        return PayrollRunItem::query()
            ->where('payroll_run_id', $runId)
            ->where('employee_id', $employee->getKey())
            ->firstOrFail();
    }

    /**
     * @return array<string, string> component code => amount
     */
    private function lines(PayrollRunItem $item): array
    {
        return PayrollRunItemComponent::query()
            ->where('payroll_run_item_id', $item->getKey())
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (PayrollRunItemComponent $line): array => [$line->component_code => (string) $line->amount])
            ->all();
    }

    /**
     * A March with one late arrival (9 March), generated and locked.
     *
     * @return array{0: Employee, 1: PayrollRunItem, 2: AttendanceDeduction}
     */
    private function lockedMonthWithOneLateArrival(string $name): array
    {
        $employee = $this->employee($name);
        $this->structure($employee, '40000');
        $this->workMonth($employee, 2026, 3);
        $this->rules(late: true, absence: false);
        $this->mark($employee, '2026-03-09', 'late');
        app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $this->actor);
        $runs->generate($run, null, $this->actor);
        $runs->lock($run->fresh(), $this->actor);

        $row = $this->deductions($employee)->firstOrFail();
        $this->assertSame(AttendanceDeductionStatus::Applied, $row->status);

        return [$employee, $this->item($run->getKey(), $employee), $row];
    }

    private function department(): Department
    {
        return Department::query()->firstOrCreate(['code' => 'ADT'], ['name' => 'Attendance Deduction Test']);
    }

    private function shift(): WorkShift
    {
        $shift = WorkShift::query()->where('code', 'ADT-D')->first();

        if ($shift !== null) {
            return $shift;
        }

        $shift = new WorkShift;
        $shift->forceFill([
            'code' => 'ADT-D', 'name' => 'Deduction Test Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
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
            'employee_code' => 'ADT-'.str_pad((string) (Employee::query()->withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
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
        $type = LeaveType::query()->where('code', 'ADT-AL')->first();

        if ($type !== null) {
            return $type;
        }

        $type = new LeaveType;
        $type->forceFill([
            'code' => 'ADT-AL', 'name' => 'Annual (deduction tests)', 'annual_quota_days' => '14.00', 'is_paid' => true,
            'accrual_method' => 'annual_grant', 'excludes_weekends' => true, 'excludes_holidays' => true,
            'allow_half_day' => true, 'approval_levels' => 1, 'color' => 'sky', 'is_active' => true,
        ])->save();

        return $type->fresh();
    }

    /**
     * Basic plus a 10% house allowance and a fixed 5,000 fuel allowance — 49,000 gross on a 40,000 basic.
     */
    private function structure(Employee $employee, string $basic): SalaryStructure
    {
        $house = SalaryComponent::query()->where('code', 'ADT-HOUSE')->first();

        if ($house === null) {
            $house = new SalaryComponent;
            $house->forceFill([
                'code' => 'ADT-HOUSE', 'name' => 'House allowance',
                'component_group' => SalaryComponentGroup::Allowance->value, 'side' => 'earning',
                'calculation_type' => SalaryComponentCalculation::PercentageOfBasic->value,
                'default_rate' => '10.0000', 'is_taxable' => true, 'affects_gross' => true, 'is_active' => true,
            ])->save();
            $house = $house->fresh();
        }

        $fuel = SalaryComponent::query()->where('code', 'ADT-FUEL')->first();

        if ($fuel === null) {
            $fuel = new SalaryComponent;
            $fuel->forceFill([
                'code' => 'ADT-FUEL', 'name' => 'Fuel allowance',
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
     * A month of attendance: every date closed, and every working day punched on time.
     *
     * @param  list<string>  $skip  working days left unpunched — absences
     */
    private function workMonth(Employee $employee, int $year, int $month, array $skip = []): void
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

            $day = $date->toDateString();
            $attendance->checkIn($employee, Carbon::parse($day.' 09:00:00', Format::timezone())->utc(), AttendanceSource::Kiosk, null, $date);
            $attendance->checkOut($employee, Carbon::parse($day.' 17:00:00', Format::timezone())->utc(), AttendanceSource::Kiosk, null, $date);
        }
    }
}
