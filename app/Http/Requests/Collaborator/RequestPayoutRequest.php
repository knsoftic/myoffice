<?php

declare(strict_types=1);

namespace App\Http\Requests\Collaborator;

use App\Enums\PayoutMethod;
use App\Models\Collaborator\Collaborator;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A partner asking to be paid (phase-10-12 §7.5).
 *
 * The destination is checked against **the requester's own** accounts, resolved from the session —
 * never from a field in the request. Without that, a well-formed post could name another partner's
 * bank account and the payout would be raised against this partner's balance.
 *
 * The amount is not validated against the balance here. That check belongs inside `PayoutService`,
 * under the wallet lock and against the derivation, because a Form Request reads a balance a moment
 * before the transaction opens — exactly the window a double submit slips through.
 */
final class RequestPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('collaborator_portal.payout_request') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $collaborator = Collaborator::query()->where('user_id', $this->user()?->getKey())->first();

        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::enum(PayoutMethod::class)],
            'payout_account_id' => [
                'nullable', 'integer',
                Rule::exists('collaborator_payout_accounts', 'id')
                    ->where('collaborator_id', $collaborator?->getKey() ?? 0)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
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
            'payout_account_id.exists' => 'That is not one of your active accounts.',
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
