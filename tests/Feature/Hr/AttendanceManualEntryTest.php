<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\AttendanceStatus;
use App\Models\Hr\Attendance;
use App\Models\Hr\AttendanceCorrection;
use App\Models\Hr\AttendanceMonthlySummary;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\WorkShift;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * "Record attendance" on the HR register (`AttendanceManualEntryController`): HR enters an employee's day by
 * hand, as an HR-direct correction with the reason on the trail — and a future day, a day outside the
 * employment, a payroll-locked month or an employee out of the user's sight is refused on the form.
 */
final class AttendanceManualEntryTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_day_is_recorded_with_its_times_as_an_hr_direct_correction(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('Forgot To Punch');
        $day = Carbon::yesterday()->toDateString();

        $this->actingAs($actor)
            ->post(route('admin.attendance.record'), $this->entry($employee, $day, [
                'entry_check_in' => '09:05',
                'entry_check_out' => '17:00',
                'entry_remarks' => 'Device down',
            ]))
            ->assertRedirect(route('admin.attendance.index', ['date' => $day]))
            ->assertSessionHasNoErrors();

        $row = Attendance::query()->where('employee_id', $employee->getKey())->whereDate('attendance_date', $day)->firstOrFail();

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame('1.0000', (string) $row->payable_factor);
        $this->assertSame('09:05', $row->check_in_at?->copy()->setTimezone(Format::timezone())->format('H:i'));
        $this->assertSame('17:00', $row->check_out_at?->copy()->setTimezone(Format::timezone())->format('H:i'));
        $this->assertSame('Device down', $row->remarks);
        $this->assertTrue((bool) $row->is_manual);

        $correction = AttendanceCorrection::query()->where('employee_id', $employee->getKey())->latest('id')->firstOrFail();

        $this->assertSame('hr_direct', $correction->source->value);
        $this->assertNotNull($correction->applied_at, 'Applied in the same act (HR-6).');
        $this->assertStringContainsString('biometric', (string) $correction->reason);
    }

    #[Test]
    public function a_future_day_or_a_day_before_joining_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('New Joiner', ['joining_date' => Carbon::today()->subDays(3)->toDateString()]);

        $this->actingAs($actor)
            ->post(route('admin.attendance.record'), $this->entry($employee, Carbon::tomorrow()->toDateString()))
            ->assertSessionHasErrors('entry_date');

        $this->actingAs($actor)
            ->post(route('admin.attendance.record'), $this->entry($employee, Carbon::today()->subDays(10)->toDateString()))
            ->assertSessionHasErrors('entry_date');

        $this->assertSame(0, Attendance::query()->where('employee_id', $employee->getKey())->count());
    }

    #[Test]
    public function check_out_before_check_in_and_a_short_reason_are_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('Bad Times');
        $day = Carbon::yesterday()->toDateString();

        $this->actingAs($actor)
            ->post(route('admin.attendance.record'), $this->entry($employee, $day, ['entry_check_in' => '17:00', 'entry_check_out' => '09:00']))
            ->assertSessionHasErrors('entry_check_out');

        $this->actingAs($actor)
            ->post(route('admin.attendance.record'), $this->entry($employee, $day, ['entry_reason' => 'short']))
            ->assertSessionHasErrors('entry_reason');
    }

    #[Test]
    public function a_month_payroll_has_locked_is_refused_on_the_form(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('Paid Month');
        $day = Carbon::yesterday();

        $summary = new AttendanceMonthlySummary;
        $summary->forceFill([
            'employee_id' => $employee->getKey(),
            'period_year' => $day->year,
            'period_month' => $day->month,
            'period_start' => $day->copy()->startOfMonth()->toDateString(),
            'period_end' => $day->copy()->endOfMonth()->toDateString(),
            'calendar_days' => $day->daysInMonth,
            'working_days' => '26.0000',
            'present_days' => '26.0000',
            'payable_days' => '26.0000',
            'lop_days' => '0.0000',
            'generated_at' => now(),
            'locked_at' => now(),
        ])->save();

        $this->actingAs($actor)
            ->from(route('admin.attendance.index'))
            ->post(route('admin.attendance.record'), $this->entry($employee, $day->toDateString()))
            ->assertRedirect(route('admin.attendance.index'))
            ->assertSessionHasErrors('entry_date');

        $this->assertSame(0, Attendance::query()->where('employee_id', $employee->getKey())->count());
    }

    #[Test]
    public function the_form_needs_attendance_create_and_an_employee_the_user_may_see(): void
    {
        $this->createSuperAdmin();
        $employee = $this->employee('Out Of Sight');
        $day = Carbon::yesterday()->toDateString();

        $viewer = $this->createUserWithPermissions(['attendance.view_any']);

        $this->actingAs($viewer)
            ->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertDontSee('id="record-employee-attendance-form"', false);

        $this->actingAs($viewer)
            ->post(route('admin.attendance.record'), $this->entry($employee, $day))
            ->assertForbidden();

        // May mark, but sees no employees (no `employees.view_any`, no employee record of their own).
        $narrow = $this->createUserWithPermissions(['attendance.view_any', 'attendance.create']);

        $this->actingAs($narrow)
            ->post(route('admin.attendance.record'), $this->entry($employee, $day))
            ->assertSessionHasErrors('entry_employee_id');

        $this->assertSame(0, Attendance::query()->where('employee_id', $employee->getKey())->count());

        $this->actingAs($this->createUserWithPermissions(['attendance.view_any', 'attendance.create', 'employees.view_any']))
            ->get(route('admin.attendance.index'))
            ->assertOk()
            ->assertSee('id="record-employee-attendance-form"', false)
            ->assertSee('Record attendance');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function entry(Employee $employee, string $date, array $overrides = []): array
    {
        return array_merge([
            'entry_employee_id' => $employee->getKey(),
            'entry_date' => $date,
            'entry_status' => 'present',
            'entry_payable_factor' => '1',
            'entry_reason' => 'The biometric device was down; confirmed by the team lead.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, array $attributes = []): Employee
    {
        $department = Department::query()->firstOrCreate(['code' => 'AME'], ['name' => 'Manual Entry Test']);

        $shift = WorkShift::query()->where('code', 'AME-D')->first();

        if ($shift === null) {
            $shift = new WorkShift;
            $shift->forceFill([
                'code' => 'AME-D', 'name' => 'Manual Entry Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
                'break_minutes' => 0, 'grace_in_minutes' => 15, 'grace_out_minutes' => 10,
                'min_full_day_minutes' => 400, 'min_half_day_minutes' => 200, 'expected_minutes' => 480,
                'crosses_midnight' => false, 'weekly_off_days' => ['sunday'], 'is_default' => false, 'is_active' => true,
            ])->save();
        }

        $employee = new Employee;
        $employee->forceFill(array_merge([
            'employee_code' => 'AME-'.str_pad((string) (Employee::query()->withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'department_id' => $department->getKey(),
            'work_shift_id' => $shift->getKey(),
            'name' => $name,
            'joining_date' => Carbon::today()->subYear()->toDateString(),
            'employment_type' => 'full_time',
            'status' => 'active',
        ], $attributes))->save();

        return $employee->fresh();
    }
}
