<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\DataObjects\Finance\RecordPaymentData;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Take a client payment against a project (phase-11 §7.2, §8.1 project variant).
 *
 * The student form's twin, with three fields it does not have: a milestone, an invoice, and nothing
 * else. `is_advance` is deliberately **not** a field — it is derived from the absence of an invoice,
 * because a box somebody ticks is a box somebody forgets to tick, and an advance mislabelled as an
 * invoiced payment is a receivables figure that quietly stops adding up.
 */
final class RecordProjectPaymentRequest extends FormRequest
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
            'project_milestone_id' => ['nullable', 'integer', 'exists:project_milestones,id'],
            'invoice_id' => ['nullable', 'integer'],
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
            'amount.gt' => 'A payment records money that arrived, so the amount is more than zero. A correction is a refund, not a negative payment.',
            'paid_on.before_or_equal' => 'A payment cannot be dated in the future. Money that has not arrived is not a payment.',
            'idempotency_key.required' => 'This form is missing its duplicate guard. Reload the page rather than retrying — without the key a double submit would record the money twice.',
        ];
    }

    public function toPaymentData(): RecordPaymentData
    {
        return new RecordPaymentData(
            amount: (string) $this->input('amount'),
            method: PaymentMethod::from((string) $this->input('payment_method')),
            paidOn: Carbon::parse((string) $this->input('paid_on')),
            milestoneId: $this->input('project_milestone_id') === null
                ? null
                : (int) $this->input('project_milestone_id'),
            invoiceId: $this->input('invoice_id') === null ? null : (int) $this->input('invoice_id'),
            referenceNo: $this->input('reference_no') === null ? null : (string) $this->input('reference_no'),
            gatewayTxnId: $this->input('gateway_txn_id') === null ? null : (string) $this->input('gateway_txn_id'),
            notes: $this->input('notes') === null ? null : (string) $this->input('notes'),
            idempotencyKey: (string) $this->input('idempotency_key'),
            confirmDuplicate: $this->boolean('confirm_duplicate'),
        );
    }
}
