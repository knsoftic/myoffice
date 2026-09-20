<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Collaborator;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Post a manual adjustment or a write-off against a partner's balance (phase-10-12 §7.4, §6.3).
 *
 * The amount is **signed**: a credit is positive, a debit negative, and the direction is part of what
 * somebody typed rather than a separate radio button they can leave on the wrong setting. Zero is
 * refused here as well as in the service, because an adjustment of nothing is a row somebody will
 * later sum.
 *
 * `write_off` is a different act with a different permission — it says the business has given up
 * recovering a clawback — so it is a field, not a shade of the amount.
 */
final class StoreCommissionAdjustmentRequest extends FormRequest
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
            'collaborator_id' => ['required', 'integer', 'exists:collaborators,id'],
            'amount' => ['required', 'string', 'max:20', 'regex:/^-?\d{1,13}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'write_off' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $amount = (string) $this->input('amount', '0');

                if ($validator->errors()->has('amount')) {
                    return;
                }

                if (Money::isZero(Money::of($amount))) {
                    $validator->errors()->add('amount',
                        'An adjustment of nothing is not an adjustment. Enter what the balance should move by — '
                        .'positive to credit the partner, negative to debit them.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter an amount like 250.00, or -100.00 to take money back.',
            'reason.required' => 'A manual adjustment is a figure somebody put into the ledger by hand. The reason is the whole of its explanation.',
        ];
    }

    public function signedAmount(): string
    {
        return Money::of((string) $this->input('amount', '0'));
    }

    public function isWriteOff(): bool
    {
        return (bool) $this->boolean('write_off');
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason', ''));
    }
}
