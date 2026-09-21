<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Collaborator;

use App\Support\Format;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Recording that the bank actually sent it (phase-10-12 §8.6).
 *
 * `finance.payout_reference_required` decides whether a transaction id is mandatory. When it is, the
 * reference is also the thing `uq_cp_txn(method, transaction_id)` makes unique — so a double submit
 * fails at the index rather than paying twice, and this rule is the friendly half of that pair.
 *
 * A future `paid_on` is refused outright: money that has not left yet has not been paid, and a payout
 * dated forward would sit in a statement period it does not belong to.
 */
final class MarkPayoutPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('collaborator_payouts.change_status') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = (bool) setting('finance.payout_reference_required', true);

        return [
            'transaction_id' => [$required ? 'required' : 'nullable', 'string', 'max:100'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:'.Carbon::now(Format::timezone())->toDateString()],
            'receipt_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transaction_id.required' => 'The bank reference is how this payment is traced when the partner '
                .'says it never arrived. It is also what stops the same transfer being recorded twice.',
            'paid_on.before_or_equal' => 'Money that has not left yet has not been paid.',
        ];
    }
}
