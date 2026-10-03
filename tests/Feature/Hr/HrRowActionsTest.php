<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\AttendanceSource;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\WorkShift;
use App\Models\User;
use App\Services\Hr\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The employee list and page offer Edit and Delete, and Delete exists only where nothing was recorded.
 *
 * An employee with attendance, leave, an advance or a payslip is exited through their status, never
 * archived out of the lists. The control's absence is courtesy; the 403 on the route is the rule — and it
 * holds for Super Admin too, who passes every policy.
 */
final class HrRowActionsTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function an_employee_with_no_history_offers_edit_and_delete_and_can_be_archived(): void
    {
        $hr = $this->hrUser();
        $fresh = $this->employee('Fresh Hire');

        $this->actingAs($hr)->get(route('admin.employees.index'))
            ->assertOk()
            ->assertSee(route('admin.employees.edit', $fresh), false)
            ->assertSee('action="'.route('admin.employees.destroy', $fresh).'"', false);

        $this->actingAs($hr)->get(route('admin.employees.show', $fresh))
            ->assertOk()
            ->assertSee('action="'.route('admin.employees.destroy', $fresh).'"', false);

        $this->actingAs($hr)->delete(route('admin.employees.destroy', $fresh))
            ->assertRedirect(route('admin.employees.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('employees', ['id' => $fresh->getKey()]);
    }

    #[Test]
    public function an_employee_with_attendance_offers_no_delete_and_the_route_refuses_it(): void
    {
        $hr = $this->hrUser();
        $worked = $this->employee('Has Worked');

        $this->actingAs($this->createSuperAdmin());
        app(AttendanceService::class)->checkIn($worked, Carbon::parse('2026-03-02 09:05:00'), AttendanceSource::Kiosk);

        $this->actingAs($hr)->get(route('admin.employees.index'))
            ->assertOk()
            ->assertSee(route('admin.employees.edit', $worked), false)
            ->assertDontSee('action="'.route('admin.employees.destroy', $worked).'"', false);

        $this->actingAs($hr)->get(route('admin.employees.show', $worked))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.employees.destroy', $worked).'"', false);

        $this->actingAs($hr)->delete(route('admin.employees.destroy', $worked))->assertForbidden();
        $this->actingAs($this->createSuperAdmin())->delete(route('admin.employees.destroy', $worked))->assertForbidden();

        $this->assertNotSoftDeleted('employees', ['id' => $worked->getKey()]);
    }

    #[Test]
    public function without_the_delete_permission_no_row_offers_it(): void
    {
        $reader = $this->createUserWithPermissions(['employees.view_any', 'employees.view']);
        $fresh = $this->employee('Read Only');

        $this->actingAs($reader)->get(route('admin.employees.index'))
            ->assertOk()
            ->assertDontSee(route('admin.employees.edit', $fresh), false)
            ->assertDontSee('action="'.route('admin.employees.destroy', $fresh).'"', false);

        $this->actingAs($reader)->delete(route('admin.employees.destroy', $fresh))->assertForbidden();
    }

    private function hrUser(): User
    {
        return $this->createUserWithPermissions([
            'employees.view_any', 'employees.view', 'employees.edit', 'employees.delete',
        ]);
    }

    private function employee(string $name): Employee
    {
        $department = Department::query()->firstOrCreate(['code' => 'HRA'], ['name' => 'HR Actions']);

        $shift = WorkShift::query()->where('code', 'HRA-D')->first();

        if ($shift === null) {
            $shift = new WorkShift;
            $shift->forceFill([
                'code' => 'HRA-D', 'name' => 'HR Actions Day', 'start_time' => '09:00:00', 'end_time' => '17:00:00',
                'break_minutes' => 0, 'grace_in_minutes' => 15, 'grace_out_minutes' => 10,
                'min_full_day_minutes' => 400, 'min_half_day_minutes' => 200, 'expected_minutes' => 480,
                'crosses_midnight' => false, 'weekly_off_days' => ['sunday'], 'is_default' => false, 'is_active' => true,
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
