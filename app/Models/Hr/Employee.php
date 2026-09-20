<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One member of staff (phase-07 §2.4, requirement §24).
 *
 * **The bridge in D32.** `user_id` is nullable and unique: an employee record exists without a login — a
 * labourer, somebody whose account was closed — and a login belongs to at most one employee. An
 * organisational **duty** (department head, reporting line, leave approver) points at an `employees.id`;
 * **who performed an act or is assigned work** points at a `users.id`. This column is the only crossing
 * between the two, and phases 6, 13 and 14-17 are bound by the same rule.
 *
 * **`employee_code` is issued once** by `DocumentNumberService` (D27) and immutable afterwards — it is
 * printed on slips and quoted in letters, so a changed code would orphan paper that already exists.
 *
 * **`current_gross_salary` is a cache** of the active structure's gross (HR-11), written only by
 * `SalaryStructureService`. **No payroll figure is ever read from it**: a slip reads the structure version
 * it actually used, because the cache is a convenience for a screen and not a source of truth.
 *
 * **`is_attendance_exempt` is a pay decision.** An exempt employee is never marked absent or late and
 * always carries a payable factor of 1, so the flag is documented on the employee screen rather than
 * hidden in a checkbox — silently exempting somebody is a raise nobody approved.
 *
 * @property int $id
 * @property string $employee_code
 * @property int|null $user_id
 * @property int $department_id
 * @property string $name
 * @property Carbon $joining_date
 * @property EmploymentType $employment_type
 * @property EmployeeStatus $status
 * @property Carbon|null $exit_date
 * @property bool $is_attendance_exempt
 * @property string $current_gross_salary
 */
class Employee extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'employees';

    /**
     * What an employee form may carry. `employee_code`, `status`, the exit columns and
     * `current_gross_salary` each have their own service method and are written with `forceFill()` there.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'branch_id',
        'department_id',
        'designation_id',
        'reports_to_id',
        'work_shift_id',
        'name',
        'photo_path',
        'phone',
        'whatsapp',
        'email',
        'address',
        'city',
        'joining_date',
        'employment_type',
        'weekly_off_days',
        'is_attendance_exempt',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
        'emergency_contact_alt_phone',
        'emergency_contact_address',
        'bio',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'employment_type' => 'full_time',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'department_id' => 'integer',
            'designation_id' => 'integer',
            'reports_to_id' => 'integer',
            'work_shift_id' => 'integer',
            'joining_date' => 'date',
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
            'status_changed_at' => 'datetime',
            'exit_date' => 'date',
            'weekly_off_days' => 'array',
            'is_attendance_exempt' => 'boolean',
            'current_gross_salary' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Employee $employee): void {
            if ($employee->isDirty('employee_code') && $employee->getRawOriginal('employee_code') !== null) {
                throw new LogicException(sprintf(
                    'Employee #%s: employee_code is issued once and printed on documents that already '
                    .'exist (phase-07 §2.4, D27).',
                    (string) $employee->getKey()
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'employees';
    }

    protected function activityModule(): ?string
    {
        return 'employees';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions the rest of the phase asks
    |--------------------------------------------------------------------------
    */

    /**
     * May a payroll run produce a slip for this person for the period starting on this date?
     *
     * **The exit date decides, not the status** (phase-07 §6.10 #2). Somebody who resigned on 31 March is
     * `resigned` the moment HR records it, and their status alone would say "never pay this person" — so
     * the final month's salary would silently never be generated. An exit closes the door from the day
     * after it instead: paid for March, not for April.
     *
     * Somebody still employed is decided by their status, so a suspended employee is not paid.
     */
    public function isPayrollEligibleOn(Carbon $periodStart): bool
    {
        if ($this->exit_date !== null) {
            return $this->exit_date->greaterThanOrEqualTo($periodStart->copy()->startOfDay());
        }

        return $this->status->isPayrollEligible();
    }

    /**
     * The days this person does not work: their own override, then their shift's, then the business-wide
     * setting. Each step is a deliberate narrowing, and the first one that answers wins.
     *
     * @return list<string>
     */
    public function offDays(): array
    {
        if (is_array($this->weekly_off_days) && $this->weekly_off_days !== []) {
            return array_values($this->weekly_off_days);
        }

        return $this->workShift?->offDays() ?? (array) setting('hr.weekend_days', ['sunday']);
    }

    /**
     * Has this person left?
     */
    public function hasExited(): bool
    {
        return $this->status->isExited();
    }

    /**
     * The employees a user may see when they hold no "view everybody" permission (§9):
     * themselves, and anybody reporting to them.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('employees.view_any')) {
            return $query;
        }

        $self = self::query()->where('user_id', $user->getKey())->value('id');

        return $query->where(function (Builder $scoped) use ($user, $self): void {
            $scoped->where('user_id', $user->getKey());

            if ($self !== null) {
                $scoped->orWhere('reports_to_id', $self);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (§2.4)
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'work_shift_id');
    }

    /**
     * The post this employee reports to — a duty, so an employee rather than a user (D32).
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    public function headedDepartment(): HasOne
    {
        return $this->hasOne(Department::class, 'head_employee_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id')->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class, 'employee_id');
    }

    public function attendanceSummaries(): HasMany
    {
        return $this->hasMany(AttendanceMonthlySummary::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function leaveLedger(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'employee_id');
    }

    public function salaryStructures(): HasMany
    {
        return $this->hasMany(SalaryStructure::class, 'employee_id')->orderByDesc('version');
    }

    /**
     * The version in force — the one payroll reads. At most one exists (HR-10, `uq_ss_open`).
     */
    public function activeSalaryStructure(): HasOne
    {
        return $this->hasOne(SalaryStructure::class, 'employee_id')
            ->where('status', 'active')
            ->latestOfMany('effective_from');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'employee_id');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollRunItem::class, 'employee_id');
    }
}
