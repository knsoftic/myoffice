<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Enums\DiscountMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Writing or rewriting an invoice (phase-13 §7.1).
 *
 * **No total, no subtotal, no tax amount.** Every money column is derived by `InvoiceCalculator` from
 * the quantities and rates below, so a hand-crafted POST cannot ask a client for a figure the lines do
 * not add up to. What is validated here is the shape of what was typed.
 *
 * The project, when given, must belong to the same client. Without that check an invoice could be
 * raised against one client for another client's work, and the first person to notice would be the one
 * who received it.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.create') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'project_id' => [
                'nullable', 'integer',
                // The project has to be this client's. An invoice raised against the wrong one is a
                // document that looks perfectly normal and is addressed to somebody who owes nothing.
                Rule::exists('projects', 'id')->where('client_id', $this->integer('client_id')),
            ],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'title' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:64'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],

            'discount_mode' => ['required', Rule::enum(DiscountMode::class)],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_fixed' => ['nullable', 'numeric', 'min:0'],

            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.details' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit' => ['nullable', 'string', 'max:24'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_mode' => ['nullable', Rule::enum(DiscountMode::class)],
            'lines.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.discount_fixed' => ['nullable', 'numeric', 'min:0'],
            'lines.*.is_taxable' => ['nullable', 'boolean'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.project_milestone_id' => ['nullable', 'integer', 'exists:project_milestones,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'An invoice with no lines is not a bill. Add what is being charged for.',
            'lines.*.quantity.gt' => 'A line for nothing is not a line.',
            'project_id.exists' => 'That project belongs to a different client.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mode = DiscountMode::tryFrom((string) $this->input('discount_mode', 'none')) ?? DiscountMode::None;

            // The database refuses a row whose discount mode and payload disagree
            // (`chk_inv_discount_payload`); this is the readable half of the same rule.
            if ($mode->requiresRate() && ! $this->filled('discount_rate')) {
                $validator->errors()->add('discount_rate', 'A percentage discount needs a rate.');
            }

            if ($mode->requiresAmount() && ! $this->filled('discount_fixed')) {
                $validator->errors()->add('discount_fixed', 'A fixed discount needs an amount.');
            }

            foreach ((array) $this->input('lines', []) as $index => $line) {
                $lineMode = DiscountMode::tryFrom((string) ($line['discount_mode'] ?? 'none')) ?? DiscountMode::None;

                if ($lineMode->requiresRate() && ! filled($line['discount_rate'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.discount_rate", 'A percentage discount needs a rate.');
                }

                if ($lineMode->requiresAmount() && ! filled($line['discount_fixed'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.discount_fixed", 'A fixed discount needs an amount.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['discount_mode' => $this->input('discount_mode', DiscountMode::None->value)]);
    }
}
