<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One job title (phase-07 §2.3, requirement §24).
 *
 * A null `department_id` means a title any department may use. `level` mirrors `roles.level`: **lower is
 * more senior**, so an org list sorts without anybody having to decide again.
 *
 * `department_guard` is generated and carries `uq_desig_guard` — one title per department, and reusable
 * again once the old one is soft-deleted ([D-HR-5]).
 */
class Designation extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'designations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'title',
        'code',
        'level',
        'description',
        'is_active',
        'sort_order',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'level' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'designations';
    }

    protected function activityModule(): ?string
    {
        return 'designations';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_id');
    }
}
