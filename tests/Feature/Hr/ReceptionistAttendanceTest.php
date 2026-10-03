<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\WorkShift;
use App\Services\Hr\EmployeeScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The front desk keeps the staff attendance register.
 *
 * The Receptionist holds `attendance.view_any`, `view` and `create`. The register lists only the employees
 * the user may see, and seeing everybody used to need `employees.view_any` — which would also open the
 * staff directory. Holding `attendance.create` now widens the HR scope to every employee, and nothing else:
 * the Employees screen and payslips still refuse.
 */
final class ReceptionistAttendanceTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function the_receptionist_sees_every_employee_on_the_register_and_can_punch_one_in(): void
    {
        $receptionist = $this->createUserWithRole('Receptionist');
        $first = $this->employee('Front Desk Colleague');
        $second = $this->employee('Back Office Colleague');

        $this->assertSame('all', app(EmployeeScopeResolver::class)->visibility($receptionist)['mode']);

        $this->actingAs($receptionist)
            ->get(route('admin.attendance.index', ['date' => '2026-03-17']))
            ->assertOk()
            ->assertSee('Front Desk Colleague')
            ->assertSee('Back Office Colleague');

        $this->actingAs($receptionist)
            ->post(route('admin.attendance.punch'), [
                'employee_id' => $second->getKey(),
                'direction' => 'in',
                'at' => '2026-03-17 09:02:00',
                'date' => '2026-03-17',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $second->getKey(),
            'attendance_date' => '2026-03-17',
        ]);

        $this->assertNotNull($first);
    }

    #[Test]
    public function the_register_does_not_open_the_staff_directory_or_payslips(): void
    {
        $receptionist = $this->createUserWithRole('Receptionist');

        $this->actingAs($receptionist)->get(route('admin.employees.index'))->assertForbidden();
        $this->actingAs($receptionist)->get(route('admin.payslips.index'))->assertForbidden();
    }

    #[Test]
    public function without_attendance_create_an_employee_still_sees_only_themselves(): void
    {
        $user = $this->createUserWithPermissions(['employee_self_service.view']);
        $own = $this->employee('Only Me');
        $own->forceFill(['user_id' => $user->getKey()])->save();
        $this->employee('Somebody Else');

        $this->assertSame(
            ['mode' => 'own', 'ids' => [(int) $own->getKey()]],
            app(EmployeeScopeResolver::class)->visibility($user),
        );
    }

    private function employee(string $name): Employee
    {
        $department = Department::query()->firstOrCreate(['code' => 'RCA'], ['name' => 'Reception Attendance']);

        $shift = WorkShift::query()->where('code', 'RCA-D')->first();

        if ($shift === null) {
            $shift = new WorkShift;
            $shift->forceFill([
                'code' => 'RCA-D', 'name' => 'Reception Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
                'break_minutes' => 0, 'grace_in_minutes' => 15, 'grace_out_minutes' => 10,
                'min_full_day_minutes' => 400, 'min_half_day_minutes' => 200, 'expected_minutes' => 480,
                'crosses_midnight' => false, 'weekly_off_days' => ['sunday'], 'is_default' => true, 'is_active' => true,
            ])->save();
        }

        $employee = new Employee;
        $employee->forceFill([
            'employee_code' => 'EMP-'.str_pad((string) (Employee::query()->withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'department_id' => $department->getKey(),
            'work_shift_id' => $shift->getKey(),
            'name' => $name,
            'joining_date' => '2025-01-01',
            'employment_type' => 'full_time',
            'status' => 'active',
        ])->save();

        return $employee->fresh();
    }
}
