<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One typed allowance or deduction (phase-07 §2.18, requirement §28).
 *
 * **The group decides the side.** `side` is written from the group and never by hand, so a deduction can
 * never be configured in a way that makes it sum as an earning — the one mistake that would make every
 * slip add up wrongly while looking perfectly reasonable.
 *
 * `is_system` marks the components payroll produces itself. They cannot be renamed, re-sided or deleted:
 * each is the output of a calculation, and a second hand-editable source under the same total would make
 * the figure impossible to explain.
 *
 * `affects_gross = false` exists for a reimbursement — paying somebody back what they already spent is not
 * pay, and must not inflate gross or the tax base.
 */
class SalaryComponent extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'salary_components';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'component_group',
        'calculation_type',
        'default_amount',
        'default_rate',
        'is_taxable',
        'affects_gross',
        'is_statutory',
        'is_attendance_dependent',
        'print_label',
        'is_active',
        'sort_order',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_group' => SalaryComponentGroup::class,
            'side' => SalaryComponentType::class,
            'calculation_type' => SalaryComponentCalculation::class,
            'default_amount' => 'decimal:2',
            'default_rate' => 'decimal:4',
            'is_taxable' => 'boolean',
            'affects_gross' => 'boolean',
            'is_statutory' => 'boolean',
            'is_attendance_dependent' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The group is the side; keeping them in step here means no screen and no import can separate them.
        static::saving(static function (SalaryComponent $component): void {
            $group = $component->component_group;

            if ($group instanceof SalaryComponentGroup) {
                $component->side = $group->side();
            }
        });
    }

    /**
     * What the slip prints for this component.
     */
    public function printableName(): string
    {
        return $this->print_label ?: $this->name;
    }

    public function moduleSlug(): string
    {
        return 'salary_components';
    }

    protected function activityModule(): ?string
    {
        return 'salary_components';
    }

    public function structureLines(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class, 'salary_component_id');
    }

    public function slipLines(): HasMany
    {
        return $this->hasMany(PayrollRunItemComponent::class, 'salary_component_id');
    }
}
