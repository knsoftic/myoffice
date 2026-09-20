<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;

/**
 * One line of a salary slip (phase-07 §6.1, §6.6).
 *
 * **The line is the figure.** No total on a slip is ever recomputed from the inputs (HR-13): every step of
 * §6.6 that produces money produces one of these, the totals are the sum of them, and the stored rows are
 * what the slip prints years later. A component retired next year cannot change a slip printed this year,
 * because the code, the name and the side are all carried here rather than joined.
 *
 * `calculationNote` is the sentence a human reads when they ask why: "34,000.00 / 31 × 2.5000 days". It is
 * written at the moment the arithmetic happens, when the inputs are still in hand.
 */
final readonly class PayslipLine
{
    public function __construct(
        public string $componentCode,
        public string $componentName,
        public SalaryComponentGroup $group,
        public SalaryComponentType $side,
        public SalaryComponentCalculation $calculation,
        public string $amount,
        public string $rate = '0.0000',
        public string $baseAmount = '0.00',
        public string $quantity = '0.0000',
        public bool $isTaxable = true,
        public ?int $salaryComponentId = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $calculationNote = null,
        public int $sortOrder = 0,
    ) {}

    public function isEarning(): bool
    {
        return $this->side === SalaryComponentType::Earning;
    }

    public function isDeduction(): bool
    {
        return $this->side === SalaryComponentType::Deduction;
    }

    /**
     * The same line with a different amount — used by §6.6 step 9, where a recovery is trimmed rather than
     * recomputed from scratch.
     */
    public function withAmount(string $amount, ?string $note = null): self
    {
        return new self(
            componentCode: $this->componentCode,
            componentName: $this->componentName,
            group: $this->group,
            side: $this->side,
            calculation: $this->calculation,
            amount: $amount,
            rate: $this->rate,
            baseAmount: $this->baseAmount,
            quantity: $this->quantity,
            isTaxable: $this->isTaxable,
            salaryComponentId: $this->salaryComponentId,
            sourceType: $this->sourceType,
            sourceId: $this->sourceId,
            calculationNote: $note ?? $this->calculationNote,
            sortOrder: $this->sortOrder,
        );
    }

    /**
     * The row this line becomes in `payroll_run_item_components`.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'salary_component_id' => $this->salaryComponentId,
            'component_code' => $this->componentCode,
            'component_name' => $this->componentName,
            'component_group' => $this->group->value,
            'side' => $this->side->value,
            'calculation_type' => $this->calculation->value,
            'rate' => $this->rate,
            'base_amount' => $this->baseAmount,
            'quantity' => $this->quantity,
            'amount' => $this->amount,
            'is_taxable' => $this->isTaxable,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'calculation_note' => $this->calculationNote,
            'sort_order' => $this->sortOrder,
        ];
    }
}
