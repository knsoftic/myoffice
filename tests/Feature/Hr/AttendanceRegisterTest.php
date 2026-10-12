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
 * "Mark attendance" — the employee register (`AttendanceRegisterController`, `AttendanceRegisterService`):
 * every employee for a day marked from one screen, each changed day an HR-direct correction, nothing
 * written for a row the save would leave as it is, and a payroll-locked month shown and refused.
 */
final class AttendanceRegisterTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function the_screen_lists_every_employee_for_the_day(): void
    {
        $actor = $this->createSuperAdmin();
        $first = $this->employee('Register Alpha');
        $second = $this->employee('Register Beta');

        $this->actingAs($actor)
            ->get(route('admin.attendance.register', ['date' => Carbon::yesterday()->toDateString()]))
            ->assertOk()
            ->assertSee('Register Alpha')
            ->assertSee('Register Beta')
            ->assertSee('name="marks['.$first->getKey().'][status]"', false)
            ->assertSee('name="marks['.$second->getKey().'][status]"', false)
            ->assertSee('Save attendance');

        $this->actingAs($actor)
            ->get(route('admin.attendance.register', ['date' => Carbon::tomorrow()->toDateString()]))
            ->assertRedirect(route('admin.attendance.register', ['date' => Carbon::today()->toDateString()]));
    }

    #[Test]
    public function one_save_marks_several_employees_and_a_second_save_writes_nothing(): void
    {
        $actor = $this->createSuperAdmin();
        $first = $this->employee('Saved Alpha');
        $second = $this->employee('Saved Beta');
        $third = $this->employee('Left Alone');
        $day = Carbon::yesterday()->toDateString();

        $body = [
            'date' => $day,
            'reason' => 'Daily attendance register marked by HR.',
            'marks' => [
                $first->getKey() => ['status' => 'late', 'check_in' => '09:40', 'check_out' => '17:30', 'remarks' => 'Traffic'],
                $second->getKey() => ['status' => 'absent'],
                $third->getKey() => ['status' => ''],
            ],
        ];

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), $body)
            ->assertRedirect(route('admin.attendance.register', ['date' => $day]))
            ->assertSessionHasNoErrors();

        $rowOf = fn (Employee $employee): ?Attendance => Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $day)
            ->first();

        $late = $rowOf($first);
        $this->assertSame(AttendanceStatus::Late, $late->status);
        $this->assertSame('1.0000', (string) $late->payable_factor);
        $this->assertSame('09:40', $late->check_in_at?->copy()->setTimezone(Format::timezone())->format('H:i'));
        $this->assertSame('17:30', $late->check_out_at?->copy()->setTimezone(Format::timezone())->format('H:i'));
        $this->assertSame('Traffic', $late->remarks);

        $absent = $rowOf($second);
        $this->assertSame(AttendanceStatus::Absent, $absent->status);
        $this->assertSame('0.0000', (string) $absent->payable_factor);

        $this->assertNull($rowOf($third), 'A blank mark leaves the employee alone.');

        $corrections = AttendanceCorrection::query()->count();

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), $body)
            ->assertRedirect();

        $this->assertSame($corrections, AttendanceCorrection::query()->count(), 'Saving the same register again writes nothing.');
    }

    #[Test]
    public function a_month_payroll_has_locked_is_shown_and_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('Paid Already');
        $day = Carbon::yesterday();
        $this->lockMonth($employee, $day);

        $this->actingAs($actor)
            ->get(route('admin.attendance.register', ['date' => $day->toDateString()]))
            ->assertOk()
            ->assertSee('cannot be changed on this day')
            ->assertDontSee('name="marks['.$employee->getKey().'][status]"', false);

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), [
                'date' => $day->toDateString(),
                'reason' => 'Trying to change a paid month.',
                'marks' => [$employee->getKey() => ['status' => 'absent']],
            ])
            ->assertRedirect()
            ->assertSessionHas('toast', fn (array $toast): bool => str_contains($toast['message'], 'Paid Already'));

        $this->assertSame(0, Attendance::query()->where('employee_id', $employee->getKey())->count());
    }

    #[Test]
    public function the_register_needs_attendance_create_and_marks_only_employees_the_user_may_see(): void
    {
        $this->createSuperAdmin();
        $employee = $this->employee('Out Of Reach');
        $day = Carbon::yesterday()->toDateString();
        $body = ['date' => $day, 'reason' => 'Daily attendance register marked by HR.', 'marks' => [$employee->getKey() => ['status' => 'present']]];

        $viewer = $this->createUserWithPermissions(['attendance.view_any']);
        $this->actingAs($viewer)->get(route('admin.attendance.register'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.attendance.register.store'), $body)->assertForbidden();

        // May mark, but sees no employees: the posted id is reported, never written.
        $narrow = $this->createUserWithPermissions(['attendance.view_any', 'attendance.create']);

        $this->actingAs($narrow)
            ->get(route('admin.attendance.register', ['date' => $day]))
            ->assertOk()
            ->assertDontSee('Out Of Reach');

        $this->actingAs($narrow)->post(route('admin.attendance.register.store'), $body)->assertRedirect();

        $this->assertSame(0, Attendance::query()->where('employee_id', $employee->getKey())->count());
    }

    #[Test]
    public function bad_input_is_refused_on_the_form(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('Bad Input');
        $id = $employee->getKey();
        $day = Carbon::yesterday()->toDateString();

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), ['date' => Carbon::tomorrow()->toDateString(), 'reason' => 'Daily register by HR.', 'marks' => [$id => ['status' => 'present']]])
            ->assertSessionHasErrors('date');

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), ['date' => $day, 'reason' => 'Daily register by HR.', 'marks' => [$id => ['status' => 'excused']]])
            ->assertSessionHasErrors('marks.'.$id.'.status');

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), ['date' => $day, 'reason' => 'Daily register by HR.', 'marks' => [$id => ['status' => 'present', 'check_in' => '17:00', 'check_out' => '09:00']]])
            ->assertSessionHasErrors('marks.'.$id.'.check_out');

        $this->actingAs($actor)
            ->post(route('admin.attendance.register.store'), ['date' => $day, 'reason' => 'short', 'marks' => [$id => ['status' => 'present']]])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Attendance::query()->where('employee_id', $id)->count());
    }

    #[Test]
    public function the_attendance_page_links_to_the_register_and_names_a_locked_month(): void
    {
        $actor = $this->createSuperAdmin();
        $employee = $this->employee('Linked Up');
        $day = Carbon::yesterday();

        $this->actingAs($actor)
            ->get(route('admin.attendance.index', ['date' => $day->toDateString()]))
            ->assertOk()
            ->assertSee('Mark attendance')
            ->assertSee(route('admin.attendance.register', ['date' => $day->toDateString()]), false);

        $this->lockMonth($employee, $day);

        $this->actingAs($actor)
            ->get(route('admin.attendance.index', ['date' => $day->toDateString()]))
            ->assertOk()
            ->assertSee('Payroll has already locked');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function lockMonth(Employee $employee, Carbon $day): void
    {
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
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(string $name, array $attributes = []): Employee
    {
        $department = Department::query()->firstOrCreate(['code' => 'ART'], ['name' => 'Register Test']);

        $shift = WorkShift::query()->where('code', 'ART-D')->first();

        if ($shift === null) {
            $shift = new WorkShift;
            $shift->forceFill([
                'code' => 'ART-D', 'name' => 'Register Test Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
                'break_minutes' => 0, 'grace_in_minutes' => 15, 'grace_out_minutes' => 10,
                'min_full_day_minutes' => 400, 'min_half_day_minutes' => 200, 'expected_minutes' => 480,
                'crosses_midnight' => false, 'weekly_off_days' => ['sunday'], 'is_default' => false, 'is_active' => true,
            ])->save();
        }

        $employee = new Employee;
        $employee->forceFill(array_merge([
            'employee_code' => 'ART-'.str_pad((string) (Employee::query()->withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
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
