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
 * One typed line of a salary slip (phase-07 §2.25).
 *
 * `amount` is the **stored, quantised figure** the totals sum (HR-13) — nothing is recomputed at render
 * time, so a printed slip always adds up even if a rate or a rule changed since.
 *
 * `calculation_note` carries the human formula — "34,000.00 / 31 x 2.5000 days" — which is the difference
 * between a slip somebody can check and one they have to trust.
 *
 * `run_type` is denormalised so `chk_pric_sign` can exist: a negative line is legal only on a correction
 * run, where the component's side says which total it reduces (HR-17).
 */
class PayrollRunItemComponent extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'payroll_run_item_components';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_run_item_id' => 'integer',
            'salary_component_id' => 'integer',
            'component_group' => SalaryComponentGroup::class,
            'side' => SalaryComponentType::class,
            'calculation_type' => SalaryComponentCalculation::class,
            'rate' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'quantity' => 'decimal:4',
            'amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'source_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'payroll';
    }

    protected function activityModule(): ?string
    {
        return 'payroll';
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItem::class, 'payroll_run_item_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
