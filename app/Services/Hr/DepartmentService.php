<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\EmployeeStatus;
use App\Models\Hr\Department;
use App\Models\Hr\Designation;
use App\Models\Hr\Employee;
use App\Services\Hr\Exceptions\HrRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Departments and designations (phase-07 §2.2, §2.3, §6.2, requirement §25).
 *
 * The department **head** is an `employees.id`, not a `users.id` (D32): heading a department is an
 * organisational duty, and a duty belongs to a post rather than to a login that may be closed tomorrow.
 *
 * A head from **another** department is allowed — a small business genuinely does have one person running
 * two teams — but only deliberately, with the reason recorded, because the usual case is a mis-click.
 */
class DepartmentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Department
    {
        return DB::transaction(function () use ($data): Department {
            $department = new Department;
            $department->fill($this->prepare($data));

            $this->persist($department);

            return $department;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data): Department {
            $department->fill($this->prepare($data));

            $this->persist($department);

            return $department;
        });
    }

    public function toggle(Department $department): Department
    {
        $department->forceFill(['is_active' => ! $department->is_active])->save();

        return $department;
    }

    /**
     * Name the head of a department (§6.2).
     *
     * The reason is mandatory only when the head comes from elsewhere, because that is the case somebody
     * will query later.
     */
    public function setHead(Department $department, ?Employee $head, string $reason = ''): Department
    {
        if ($head === null) {
            $department->forceFill(['head_employee_id' => null])->save();

            return $department;
        }

        if ($head->status !== EmployeeStatus::Active && $head->status !== EmployeeStatus::Probation) {
            throw HrRuleException::refuse('head_employee_id', sprintf(
                '%s is %s. A department is headed by somebody who is actually working here.',
                $head->name,
                $head->status->label()
            ));
        }

        if ((int) $head->department_id !== (int) $department->getKey() && trim($reason) === '') {
            throw HrRuleException::reasonRequired('reason', sprintf(
                '%s is in %s, not in %s. Heading another team is allowed, but say why — the usual cause '
                .'of this is picking the wrong name from a list.',
                $head->name,
                $head->department?->name ?? 'another department',
                $department->name
            ));
        }

        $department->forceFill(['head_employee_id' => $head->getKey()])->save();

        return $department;
    }

    /**
     * Remove a department. Refused while live employees are in it, naming the count — the alternative is
     * a set of employees pointing at nothing.
     */
    public function destroy(Department $department): void
    {
        $employees = Employee::query()
            ->where('department_id', $department->getKey())
            ->whereNull('deleted_at')
            ->count();

        if ($employees > 0) {
            throw HrRuleException::refuse('id', sprintf(
                '%d employee(s) are in %s. Move them first — a department cannot be removed out from '
                .'under the people in it.',
                $employees,
                $department->name
            ));
        }

        DB::transaction(function () use ($department): void {
            $department->designations()->delete();
            $department->delete();
        });
    }

    /**
     * Re-derive the cached headcount. A cache, and therefore always re-derivable.
     */
    public function recountEmployees(Department $department): Department
    {
        $department->forceFill([
            'employee_count' => Employee::query()
                ->where('department_id', $department->getKey())
                ->whereNull('deleted_at')
                ->count(),
        ])->save();

        return $department;
    }

    /*
    |--------------------------------------------------------------------------
    | Designations
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeDesignation(array $data): Designation
    {
        $designation = new Designation;
        $designation->fill($this->prepareDesignation($data));
        $designation->save();

        return $designation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDesignation(Designation $designation, array $data): Designation
    {
        $designation->fill($this->prepareDesignation($data));
        $designation->save();

        return $designation;
    }

    public function destroyDesignation(Designation $designation): void
    {
        $employees = Employee::query()
            ->where('designation_id', $designation->getKey())
            ->whereNull('deleted_at')
            ->count();

        if ($employees > 0) {
            throw HrRuleException::refuse('id', sprintf(
                '%d employee(s) hold the title %s.',
                $employees,
                $designation->title
            ));
        }

        $designation->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareDesignation(array $data): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        return $data;
    }

    private function persist(Department $department): void
    {
        try {
            $department->save();
        } catch (UniqueConstraintViolationException) {
            throw HrRuleException::refuse('code', sprintf(
                'The code %s is already used by another department.',
                $department->code
            ));
        }
    }
}
