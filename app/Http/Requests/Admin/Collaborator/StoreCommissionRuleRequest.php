<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Collaborator;

use App\DataObjects\Collaborator\RuleData;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionScope;
use App\Enums\FixedCommissionRelease;
use App\Enums\StudentFeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new commission rule **version** (phase-10-12 §7.3, §8.4).
 *
 * There is no update request beside this one, and there never will be: a rate is never edited, it is
 * superseded (INV-17). The form the user fills in is therefore always the whole of what the new row
 * will say, and `reason` is mandatory because the timeline shows it beside the version for ever.
 *
 * The cross-field rules mirror `chk_ccs_payload` and `chk_ccs_rate` so the refusal is a message on a
 * field rather than a constraint violation from inside a transaction.
 */
final class StoreCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commission_for' => ['required', Rule::enum(CommissionScope::class)],
            'calculation_type' => ['required', Rule::in([
                CommissionCalculationType::Percentage->value,
                CommissionCalculationType::Fixed->value,
            ])],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'fixed_release' => ['nullable', Rule::enum(FixedCommissionRelease::class)],
            'base_override' => ['nullable', Rule::enum(CommissionBase::class)],
            'is_enabled' => ['sometimes', 'boolean'],
            'min_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'max_commission_amount' => ['nullable', 'numeric', 'min:0'],
            'applies_to_fee_types' => ['nullable', 'array'],
            'applies_to_fee_types.*' => [Rule::enum(StudentFeeType::class)],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $type = CommissionCalculationType::from((string) $this->input('calculation_type'));

                if ($type === CommissionCalculationType::Percentage && $this->input('rate') === null) {
                    $validator->errors()->add('rate', 'A percentage rule needs a rate. A blank rate is not 0 %.');
                }

                if ($type === CommissionCalculationType::Fixed && $this->input('fixed_amount') === null) {
                    $validator->errors()->add('fixed_amount', 'A fixed rule needs an amount.');
                }

                $base = $this->input('base_override');
                $scope = CommissionScope::from((string) $this->input('commission_for'));

                if ($base !== null && ! CommissionBase::from((string) $base)->appliesTo($scope)) {
                    $validator->errors()->add('base_override', sprintf(
                        'That base does not apply to %s commission. Saving it would make every payment '
                        .'under this rule fall back to "paid" and log a warning.',
                        $scope->value,
                    ));
                }
            },
        ];
    }

    public function scope(): CommissionScope
    {
        return CommissionScope::from((string) $this->input('commission_for'));
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason', ''));
    }

    public function toRuleData(): RuleData
    {
        $type = CommissionCalculationType::from((string) $this->input('calculation_type'));
        $isPercentage = $type === CommissionCalculationType::Percentage;

        /** @var list<string>|null $feeTypes */
        $feeTypes = $this->input('applies_to_fee_types');

        return new RuleData(
            scope: $this->scope(),
            calculationType: $type,
            effectiveFrom: Carbon::parse((string) $this->input('effective_from')),
            rate: $isPercentage ? (string) $this->input('rate') : null,
            fixedAmount: $isPercentage ? null : (string) $this->input('fixed_amount'),
            release: $this->input('fixed_release') === null
                ? null
                : FixedCommissionRelease::from((string) $this->input('fixed_release')),
            baseOverride: $this->input('base_override') === null
                ? null
                : CommissionBase::from((string) $this->input('base_override')),
            isEnabled: $this->boolean('is_enabled', true),
            minPaymentAmount: $this->input('min_payment_amount') === null ? null : (string) $this->input('min_payment_amount'),
            maxCommissionAmount: $this->input('max_commission_amount') === null ? null : (string) $this->input('max_commission_amount'),
            appliesToFeeTypes: $feeTypes === null || $feeTypes === [] ? null : array_values($feeTypes),
            notes: $this->input('notes') === null ? null : (string) $this->input('notes'),
        );
    }
}
