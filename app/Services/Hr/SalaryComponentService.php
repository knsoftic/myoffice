<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Enums\SalaryComponentGroup;
use App\Models\Hr\SalaryComponent;
use App\Models\Hr\SalaryStructureComponent;
use App\Services\Hr\Exceptions\HrRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue of allowances and deductions (phase-07 §2.18, §6.2, requirement §28).
 *
 * **`side` is never typed.** It is always written from `component_group->side()`, because a component
 * filed under "allowance" but marked a deduction would silently subtract money on every slip that used
 * it, and the slip would still add up.
 *
 * A **system** component's `code`, `component_group` and `side` are immutable: a slip printed last year
 * carries the code it was written with, and changing what that code means would make the old slip say
 * something it never said (§6.10 #14).
 *
 * A component **in use** cannot be deleted. Retiring it (`is_active = false`) is the right act: it stops
 * appearing in new structures and every past slip keeps its snapshot.
 */
class SalaryComponentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): SalaryComponent
    {
        return DB::transaction(function () use ($data): SalaryComponent {
            $component = new SalaryComponent;
            $component->fill($this->prepare($data));

            $this->persist($component);

            return $component;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalaryComponent $component, array $data): SalaryComponent
    {
        if ($component->is_system) {
            unset($data['code'], $data['component_group'], $data['side']);
        }

        return DB::transaction(function () use ($component, $data): SalaryComponent {
            $component->fill($this->prepare($data, $component));

            $this->persist($component);

            return $component;
        });
    }

    /**
     * Retire or restore a component. Retiring is always allowed — that is the whole point of it.
     */
    public function toggle(SalaryComponent $component): SalaryComponent
    {
        $component->forceFill(['is_active' => ! $component->is_active])->save();

        return $component;
    }

    public function destroy(SalaryComponent $component): void
    {
        if ($component->is_system) {
            throw HrRuleException::refuse('id', sprintf(
                '%s is produced by payroll itself. Retiring it would leave a figure with nowhere to go; '
                .'it cannot be removed.',
                $component->name
            ));
        }

        $uses = SalaryStructureComponent::query()
            ->where('salary_component_id', $component->getKey())
            ->count();

        if ($uses > 0) {
            throw HrRuleException::refuse('id', sprintf(
                '%s is part of %d salary structure(s). Retire it instead — it will stop appearing in new '
                .'structures and every past slip keeps what it actually said.',
                $component->name,
                $uses
            ));
        }

        $component->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?SalaryComponent $existing = null): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        $group = isset($data['component_group'])
            ? ($data['component_group'] instanceof SalaryComponentGroup
                ? $data['component_group']
                : SalaryComponentGroup::from((string) $data['component_group']))
            : $existing?->component_group;

        if ($group !== null) {
            $data['component_group'] = $group->value;

            // Always derived (§2.18): a mis-filed side would subtract money and still add up.
            $data['side'] = $group->side()->value;

            if (! array_key_exists('is_taxable', $data) && $existing === null) {
                $data['is_taxable'] = $group->isTaxableByDefault();
            }
        }

        return $data;
    }

    private function persist(SalaryComponent $component): void
    {
        try {
            $component->save();
        } catch (UniqueConstraintViolationException) {
            throw HrRuleException::refuse('code', sprintf(
                'The code %s is already used by another component. Codes are printed on slips, so they '
                .'have to mean one thing.',
                $component->code
            ));
        }
    }
}
