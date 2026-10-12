<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\AttendanceCorrectionType;
use App\Enums\AttendanceSource;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Models\Hr\AttendanceDeduction;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\WorkShift;
use App\Models\User;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\AttendanceDeductionService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\AttendanceSummaryService;
use App\Services\Hr\MySalaryService;
use App\Services\Hr\PayrollRunService;
use App\Services\Hr\SalaryStructureService;
use App\Services\Hr\WorkCalendarService;
use App\Support\Format;
use App\Support\Money;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * "My Salary & Deductions" (`SalaryController`, `MySalaryService`): an employee's own finalized payroll by
 * month, day and year.
 *
 * March 2026 (31 days, 1 March a Sunday) on a 40,000 basic + 10% house + 5,000 fuel structure: 49,000 gross,
 * 1,580.65 a day. The month has one unpunched day (3 March, a full unpaid day), one half day (4 March, half
 * unpaid) and one late arrival (9 March, 25% — 395.16). "Now" is 10 April 2026.
 */
final class MySalaryTest extends TestCase
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

    #[Test]
    public function nothing_is_shown_until_hr_finalizes_the_month(): void
    {
        [$user, , $run] = $this->marchPayroll(lock: false);

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'month', 'month' => '2026-03']))
            ->assertOk()
            ->assertSee('Payroll is being prepared')
            ->assertDontSee('Net payable')
            ->assertDontSee('2,766.14');

        app(PayrollRunService::class)->lock($run->fresh(), $this->actor);

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'month', 'month' => '2026-03']))
            ->assertOk()
            ->assertSee('Finalized')
            ->assertSee('Net payable')
            ->assertSee('2,766.14');
    }

    #[Test]
    public function the_month_history_dates_each_deduction_and_adds_up_to_the_slip(): void
    {
        [$user, $employee] = $this->marchPayroll(lock: true);

        $slip = PayrollRunItem::query()->where('employee_id', $employee->getKey())->firstOrFail();
        $month = app(MySalaryService::class)->month($employee, 2026, 3);

        $this->assertSame('finalized', $month['status']['key']);
        $this->assertSame($slip->getKey(), $month['slip']->getKey());

        $dated = $month['history']
            ->filter(fn (array $row): bool => $row['date'] !== null)
            ->map(fn (array $row): array => [$row['date']->toDateString(), $row['reason'], $row['amount']])
            ->values()
            ->all();

        $this->assertSame([
            ['2026-03-03', 'Absence', '1580.65'],
            ['2026-03-04', 'Half day', '790.33'],
            ['2026-03-09', 'Late arrival', '395.16'],
        ], $dated);

        $sum = Money::round(Money::sum(['0', ...$month['history']->pluck('amount')->all()]));
        $this->assertSame(Money::round((string) $slip->total_deductions), $sum, 'The history always adds up to the slip.');
        $this->assertSame('2766.14', $sum);

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'month', 'month' => '2026-03']))
            ->assertOk()
            ->assertSee('Deduction history')
            ->assertSee('Late arrival')
            ->assertSee('Half day')
            ->assertSee('1,580.65')
            ->assertSee('How it was calculated');
    }

    #[Test]
    public function the_day_view_shows_that_days_deduction_only(): void
    {
        [$user] = $this->marchPayroll(lock: true);

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'day', 'date' => '2026-03-09']))
            ->assertOk()
            ->assertSee('Late arrival')
            ->assertSee('395.16')
            ->assertDontSee('790.33');

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'day', 'date' => '2026-03-10']))
            ->assertOk()
            ->assertSee('Nothing was deducted for this day.');
    }

    #[Test]
    public function the_year_view_lists_every_month_with_finalized_figures_only(): void
    {
        [$user, $employee] = $this->marchPayroll(lock: true);

        $year = app(MySalaryService::class)->year($employee, 2026);

        $this->assertCount(12, $year['months']);
        $this->assertSame('finalized', $year['months'][2]['status']['key']);
        $this->assertSame('2766.14', $year['months'][2]['deductions']);
        $this->assertNull($year['months'][3]['net'], 'April has no finalized slip.');
        $this->assertSame($year['months'][2]['net'], $year['totals']['net']);

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'year', 'year' => 2026]))
            ->assertOk()
            ->assertSee('March')
            ->assertSee('Finalized total');
    }

    #[Test]
    public function a_refund_shows_once_its_correction_slip_is_finalized(): void
    {
        [$user, $employee] = $this->marchPayroll(lock: true);

        $late = AttendanceDeduction::query()->where('employee_id', $employee->getKey())->firstOrFail();
        $reversed = app(AttendanceDeductionService::class)->reverse($late, 'The office lift failed that morning.', $this->actor);
        $correction = PayrollRunItem::query()->findOrFail($reversed->correction_item_id);

        $month = app(MySalaryService::class)->month($employee, 2026, 3);
        $this->assertTrue($month['corrections']->isEmpty(), 'A draft correction is not shown.');

        app(PayrollRunService::class)->lock(PayrollRun::query()->findOrFail($correction->payroll_run_id), $this->actor);

        $month = app(MySalaryService::class)->month($employee, 2026, 3);
        $refund = $month['history']->firstWhere('kind', 'refund');

        $this->assertNotNull($refund);
        $this->assertSame('-395.16', $refund['amount']);
        $this->assertSame('395.16', $month['summary']['adjustments']);

        $this->actingAs($user)
            ->get(route('admin.my.salary.index', ['view' => 'month', 'month' => '2026-03']))
            ->assertOk()
            ->assertSee($correction->slip_number);
    }

    #[Test]
    public function an_employee_sees_only_their_own_salary(): void
    {
        [$user, $employee] = $this->marchPayroll(lock: true);

        $other = $this->createUserWithPermissions(['employee_self_service.view_financial']);
        $colleague = $this->employee('Colleague', ['user_id' => $other->getKey()]);

        // The colleague has no slip; an id in the request changes nothing.
        $this->actingAs($other)
            ->get(route('admin.my.salary.index', ['view' => 'month', 'month' => '2026-03', 'employee_id' => $employee->getKey()]))
            ->assertOk()
            ->assertSee('No finalized payroll for this month')
            ->assertDontSee('2,766.14');

        $this->actingAs($this->createUserWithPermissions(['employee_self_service.view']))
            ->get(route('admin.my.salary.index'))
            ->assertForbidden();

        // Staff with the permission but no employee record: 404, never another person's page.
        $this->actingAs($this->createUserWithPermissions(['employee_self_service.view_financial']))
            ->get(route('admin.my.salary.index'))
            ->assertNotFound();

        $this->assertNotSame($colleague->getKey(), $employee->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * March 2026 for one employee with a login: an unpunched day, a half day and a late arrival, the
     * late-arrival rule on, the summary built and the run generated — and locked when asked.
     *
     * @return array{0: User, 1: Employee, 2: PayrollRun}
     */
    private function marchPayroll(bool $lock): array
    {
        $this->actor = $this->createSuperAdmin();
        $this->actingAs($this->actor);

        $user = $this->createUserWithPermissions(['employee_self_service.view_financial', 'employee_self_service.view']);
        $employee = $this->employee('Salary Viewer', ['user_id' => $user->getKey()]);
        $this->structure($employee);
        $this->workMonth($employee, skip: ['2026-03-03'], half: ['2026-03-04']);

        $settings = app(SettingsRepository::class);
        $settings->set('hr.late_arrival_deduction_percent', '25.0000');
        $settings->set('hr.late_arrival_deduction_enabled', true);
        $settings->set('hr.excess_absence_deduction_enabled', false);

        app(AttendanceCorrectionService::class)->applyDirect(
            employee: $employee,
            date: Carbon::parse('2026-03-09'),
            type: AttendanceCorrectionType::StatusChange,
            newValues: ['status' => 'late', 'payable_factor' => '1'],
            reason: 'Arrived late, marked by HR for the test.',
            actor: $this->actor,
        );

        app(AttendanceSummaryService::class)->build($employee, 2026, 3);

        $runs = app(PayrollRunService::class);
        $run = $runs->create(2026, 3, actor: $this->actor);
        $runs->generate($run, null, $this->actor);

        if ($lock) {
            $runs->lock($run->fresh(), $this->actor);
        }

        return [$user, $employee->fresh(), $run->fresh()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, array $attributes = []): Employee
    {
        $department = Department::query()->firstOrCreate(['code' => 'MSL'], ['name' => 'My Salary Test']);

        $shift = WorkShift::query()->where('code', 'MSL-D')->first();

        if ($shift === null) {
            $shift = new WorkShift;
            $shift->forceFill([
                'code' => 'MSL-D', 'name' => 'My Salary Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
                'break_minutes' => 0, 'grace_in_minutes' => 15, 'grace_out_minutes' => 10,
                'min_full_day_minutes' => 400, 'min_half_day_minutes' => 200, 'expected_minutes' => 480,
                'crosses_midnight' => false, 'weekly_off_days' => ['sunday'], 'is_default' => false, 'is_active' => true,
            ])->save();
        }

        $employee = new Employee;
        $employee->forceFill(array_merge([
            'employee_code' => 'MSL-'.str_pad((string) (Employee::query()->withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'department_id' => $department->getKey(),
            'work_shift_id' => $shift->getKey(),
            'name' => $name,
            'joining_date' => '2025-01-01',
            'employment_type' => 'full_time',
            'status' => 'active',
        ], $attributes))->save();

        return $employee->fresh();
    }

    /**
     * 40,000 basic + 10% house + 5,000 fuel = 49,000 gross.
     */
    private function structure(Employee $employee): void
    {
        $house = SalaryComponent::query()->where('code', 'MSL-HOUSE')->first();

        if ($house === null) {
            $house = new SalaryComponent;
            $house->forceFill([
                'code' => 'MSL-HOUSE', 'name' => 'House allowance',
                'component_group' => SalaryComponentGroup::Allowance->value, 'side' => 'earning',
                'calculation_type' => SalaryComponentCalculation::PercentageOfBasic->value,
                'default_rate' => '10.0000', 'is_taxable' => true, 'affects_gross' => true, 'is_active' => true,
            ])->save();
        }

        $fuel = SalaryComponent::query()->where('code', 'MSL-FUEL')->first();

        if ($fuel === null) {
            $fuel = new SalaryComponent;
            $fuel->forceFill([
                'code' => 'MSL-FUEL', 'name' => 'Fuel allowance',
                'component_group' => SalaryComponentGroup::Allowance->value, 'side' => 'earning',
                'calculation_type' => SalaryComponentCalculation::Fixed->value,
                'default_amount' => '5000.00', 'is_taxable' => true, 'affects_gross' => true, 'is_active' => true,
            ])->save();
        }

        app(SalaryStructureService::class)->createVersion(
            employee: $employee,
            effectiveFrom: Carbon::parse('2025-01-01'),
            basicSalary: '40000',
            components: [
                ['salary_component_id' => $house->getKey(), 'rate' => '10'],
                ['salary_component_id' => $fuel->getKey(), 'amount' => '5000'],
            ],
            reason: 'Joining package.',
        );
    }

    /**
     * March 2026: every date closed, every working day punched 09:00–17:00 except the skipped ones, and the
     * half days punched 09:00–11:00.
     *
     * @param  list<string>  $skip
     * @param  list<string>  $half
     */
    private function workMonth(Employee $employee, array $skip = [], array $half = []): void
    {
        $calendar = app(WorkCalendarService::class);
        $attendance = app(AttendanceService::class);

        $start = Carbon::create(2026, 3, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $calendar->forget();

        foreach ($calendar->datesIn($start, $end) as $date) {
            $attendance->closeDay($date);
        }

        foreach ($calendar->datesIn($start, $end) as $date) {
            if (! $calendar->dayTypeFor($employee, $date)->countsTowardWorkingDays() || in_array($date->toDateString(), $skip, true)) {
                continue;
            }

            $out = in_array($date->toDateString(), $half, true) ? 11 : 17;

            // Punches are stored UTC; the shift is read in the business timezone (D61).
            $day = $date->toDateString();
            $attendance->checkIn($employee, Carbon::parse($day.' 09:00:00', Format::timezone())->utc(), AttendanceSource::Kiosk, null, $date);
            $attendance->checkOut($employee, Carbon::parse(sprintf('%s %02d:00:00', $day, $out), Format::timezone())->utc(), AttendanceSource::Kiosk, null, $date);
        }
    }
}
