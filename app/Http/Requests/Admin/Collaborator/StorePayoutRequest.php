<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Collaborator;

use App\Enums\PayoutMethod;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a payout on a partner's behalf (phase-10-12 §7.4, §8.6).
 *
 * **It deliberately does not validate the amount against the balance.** That check belongs inside
 * `PayoutService`, under the wallet lock, against the derivation rather than the cache — a Form Request
 * reads the balance a moment before the transaction opens, which is exactly the window a second
 * accountant submitting the same form would slip through. What is validated here is shape: a positive
 * figure, a real method, an account that belongs to this partner.
 */
final class StorePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('collaborator_payouts.create') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'collaborator_id' => ['required', 'integer', 'exists:collaborators,id'],
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::enum(PayoutMethod::class)],
            'payout_account_id' => [
                'nullable', 'integer',
                // The destination must belong to the partner being paid. Without this, a well-formed
                // request could send one partner's money to another partner's bank account.
                Rule::exists('collaborator_payout_accounts', 'id')
                    ->where('collaborator_id', $this->integer('collaborator_id'))
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'statement_from' => ['nullable', 'date'],
            'statement_to' => ['nullable', 'date', 'after_or_equal:statement_from'],
            'notes' => ['nullable', 'string', 'max:255'],
            // The wizard sends the key it generated when the form was opened, so a double submit
            // returns the payout that already exists instead of raising a second one.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'An amount in rupees and paisa, such as 12500.00.',
            'payout_account_id.exists' => 'That destination does not belong to this partner, or is not active.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('amount')) {
            $this->merge(['amount' => str_replace([',', ' '], '', (string) $this->input('amount'))]);
        }
    }

    protected function passedValidation(): void
    {
        $this->merge(['amount' => Money::of((string) $this->input('amount'))]);
    }
}
