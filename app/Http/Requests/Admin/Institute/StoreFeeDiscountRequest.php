<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\DataObjects\Institute\DiscountData;
use App\Enums\FeeDiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reduce what a student owes (phase-18 §6.5, §8.5).
 *
 * **Amount or percentage, never both, and never neither.** A percentage discount stores the percentage
 * *and* the amount it worked out to, so the arithmetic is never re-done against a gross that has since
 * changed. Accepting both from the form would let the two disagree before anything else got a look at
 * them, and the row would be permanently self-contradictory — it is append-only.
 *
 * **`reverses_discount_id` is deliberately absent.** A reversal is not something a form composes; it is
 * `StudentFeeService::reverseDiscount()`, which reads the original row and mirrors it. A field here
 * would let somebody post a reversal of an arbitrary amount against an unrelated discount.
 *
 * `idempotency_key` is minted once when the modal opens. `uq_sfd_idem` turns a double-submitted form
 * into a no-op rather than two discounts, and generating the key here instead of in the form would mint
 * a fresh one per request and guard nothing.
 */
final class StoreFeeDiscountRequest extends FormRequest
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
        $cap = (string) setting('institute.discount_max_percentage', '100.0000');

        return [
            'type' => ['required', Rule::enum(FeeDiscountType::class)->except([
                // Three types the service composes and a form never posts: a waiver comes from the
                // installment modal with a line attached, and a reversal mirrors a row it has read.
                FeeDiscountType::Waiver,
                FeeDiscountType::Reversal,
            ])],
            'amount' => ['nullable', 'required_without:percentage', 'prohibits:percentage', 'numeric', 'gt:0', 'max:9999999999999'],
            'percentage' => ['nullable', 'required_without:amount', 'numeric', 'gt:0', 'lte:'.$cap],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'approved_by' => ['nullable', 'integer', 'exists:users,id'],
            'effective_on' => ['nullable', 'date', 'before_or_equal:today'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // The approver is required by configuration rather than by the shape of the form, so it
            // cannot live in `rules()` — and saying so here means the user is told which setting is
            // making the demand rather than just that a field is missing.
            if (! (bool) setting('institute.discount_approval_required', true)) {
                return;
            }

            if ($this->input('approved_by') === null) {
                $validator->errors()->add('approved_by',
                    'This institute requires every discount to be approved. Name who approved it — the '
                    .'record of who agreed to reduce a fee is the point of the field.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.prohibits' => 'Give an amount or a percentage, not both. Two figures that can disagree are two figures that eventually will.',
            'amount.required_without' => 'A discount needs an amount or a percentage.',
            'percentage.lte' => 'That is above the largest percentage discount this institute allows. Change the setting, or enter the amount directly.',
            'reason.required' => 'Say why. A discount changes what a student owes, and the reason is what the figure is explained by months later.',
            'effective_on.before_or_equal' => 'A discount takes effect on a date that has arrived — a future one would change a net fee that payments have already been measured against.',
            'idempotency_key.required' => 'This form is missing its duplicate guard. Reload the page rather than retrying.',
        ];
    }

    public function toData(): DiscountData
    {
        return new DiscountData(
            type: FeeDiscountType::from((string) $this->input('type')),
            reason: (string) $this->input('reason'),
            amount: $this->filled('amount') ? (string) $this->input('amount') : null,
            percentage: $this->filled('percentage') ? (string) $this->input('percentage') : null,
            approvedBy: $this->filled('approved_by') ? (int) $this->input('approved_by') : null,
            effectiveOn: $this->filled('effective_on') ? Carbon::parse((string) $this->input('effective_on')) : null,
            idempotencyKey: (string) $this->input('idempotency_key'),
        );
    }
}
