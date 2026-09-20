<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of one salary structure version (phase-07 §2.20).
 *
 * Every descriptive column is a **snapshot** of the component as it was when the version was written.
 * Renaming "House Rent" to "Accommodation" next year must not change what last year's structure says it
 * paid — and because the snapshot is here, it does not.
 */
class SalaryStructureComponent extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'salary_structure_components';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salary_structure_id' => 'integer',
            'salary_component_id' => 'integer',
            'component_group' => SalaryComponentGroup::class,
            'side' => SalaryComponentType::class,
            'calculation_type' => SalaryComponentCalculation::class,
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'affects_gross' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'salary_structures';
    }

    protected function activityModule(): ?string
    {
        return 'salary_structures';
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
