<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\SkillLevel;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One skill on one employee (phase-07 §2.5, requirement §24).
 *
 * Deliberately **not** a dictionary ([D-HR-6]): §24 asks for skills on a person, not a taxonomy to
 * maintain. `INDEX (name)` answers "who knows Laravel" today, and a `skill_id` can be added later without
 * moving a row.
 */
class EmployeeSkill extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'employee_skills';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'level',
        'years_experience',
        'sort_order',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'level' => SkillLevel::class,
            'years_experience' => 'decimal:1',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'employees';
    }

    protected function activityModule(): ?string
    {
        return 'employees';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
