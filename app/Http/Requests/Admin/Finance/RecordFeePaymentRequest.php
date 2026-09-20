<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\DataObjects\Finance\RecordPaymentData;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Take money against a fee charge (phase-10-12 §7.1, §8.1).
 *
 * **`idempotency_key` is required and comes from the form**, minted once when the modal opened. That
 * is the whole of the double-post guard: a second submit, a browser retry and a redelivered request
 * all carry the same key and produce one receipt. Generating it here would mint a fresh one per
 * request and guard nothing, which is why it is a field rather than a convenience.
 *
 * `confirm_duplicate` is the separate, human-facing acknowledgement: two receipts for the same charge,
 * amount, day and method are usually a mistake and occasionally real, and the cashier ticks this after
 * being shown the earlier receipt number.
 */
final class RecordFeePaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'student_fee_installment_id' => ['nullable', 'integer', 'exists:student_fee_installments,id'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'gateway_txn_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'A receipt records money that arrived, so the amount is more than zero. A correction is a refund, not a negative receipt.',
            'paid_on.before_or_equal' => 'A receipt cannot be dated in the future. Money that has not arrived is not a receipt.',
            'idempotency_key.required' => 'This form is missing its duplicate guard. Reload the page rather than retrying — without the key a double submit would take the money twice.',
        ];
    }

    public function toPaymentData(): RecordPaymentData
    {
        return new RecordPaymentData(
            amount: (string) $this->input('amount'),
            method: PaymentMethod::from((string) $this->input('payment_method')),
            paidOn: Carbon::parse((string) $this->input('paid_on')),
            installmentId: $this->input('student_fee_installment_id') === null
                ? null
                : (int) $this->input('student_fee_installment_id'),
            referenceNo: $this->input('reference_no') === null ? null : (string) $this->input('reference_no'),
            gatewayTxnId: $this->input('gateway_txn_id') === null ? null : (string) $this->input('gateway_txn_id'),
            notes: $this->input('notes') === null ? null : (string) $this->input('notes'),
            idempotencyKey: (string) $this->input('idempotency_key'),
            confirmDuplicate: $this->boolean('confirm_duplicate'),
        );
    }
}
