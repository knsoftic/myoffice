<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One department (phase-07 §2.2, requirement §25).
 *
 * The ten departments of §25 are **seeded, not hardcoded** — a business that works differently edits them
 * rather than asking for a code change.
 *
 * `head_employee_id` points at an employee while `employees.department_id` points back: the circular pair
 * is why the FK is added by its own migration. The head is a **duty**, so it is an `employees.id` (D32).
 *
 * `employee_count` is a cache written only by `DepartmentService` — recomputed by COUNT, never
 * incremented, so a replayed job cannot inflate it.
 */
class Department extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'departments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'head_employee_id',
        'branch_id',
        'is_active',
        'sort_order',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'head_employee_id' => 'integer',
            'branch_id' => 'integer',
            'employee_count' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'departments';
    }

    protected function activityModule(): ?string
    {
        return 'departments';
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class, 'department_id');
    }
}
