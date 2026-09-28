<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step 2 of the two-step registration: the courses, the bill and the first payment.
 *
 * **The fees are not posted.** The course fee comes from each course's own catalogue row, and the
 * admission fee, the registration fee, the extra fee and the tax come from settings — read on the
 * server, never from the form. The screen shows the arithmetic so the operator can check it; it does
 * not get a vote in it. That is the difference between this screen and the admissions basket, where
 * a negotiated price is the whole point: a registration takes the list price, and an exception to it
 * is a discount somebody signs for.
 *
 * What the form may decide: which courses, how much discount, and how much is being paid now.
 *
 * **`payment_amount` is checked against the bill on the server**, not merely here — this class cannot
 * know the total without recomputing it, and a second implementation of the arithmetic is how a
 * screen and a service come to disagree. `StudentRegistrationService::takePayment()` refuses an
 * overpayment with the real figure.
 */
final class CompleteRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // An unticked checkbox posts nothing, so the hidden companion is what makes "off" mean false.
        $this->merge(['fees_once' => $this->boolean('fees_once', true)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'course_ids' => ['required', 'array', 'min:1', 'max:12'],
            'course_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('courses', 'id')->whereNull('deleted_at'),
            ],

            'admission_date' => ['nullable', 'date'],
            'counselor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],

            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'discount_reason' => ['nullable', 'string', 'max:255', 'required_with:discount_amount'],
            'fees_once' => ['boolean'],

            // The money taken at the counter. Optional: a student may register today and pay Friday.
            'payment_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_ids.required' => 'Pick at least one course.',
            'course_ids.*.distinct' => 'The same course is ticked twice. Pick it once.',
            'discount_reason.required_with' => 'A discount takes a reason — it is the answer to "why is this student paying less than that one".',
            'idempotency_key.required' => 'This form is missing its duplicate guard. Reload it rather than retrying — without the key a double submit would take the money twice.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $paying = (string) $this->input('payment_amount', '');

            if ($paying !== '' && (float) $paying > 0 && $this->input('payment_method') === null) {
                // Not a formality: `PaymentMethod` decides how the receipt reads and, for a cheque or
                // a transfer, whether a reference is worth asking for.
                $validator->errors()->add('payment_method', 'Say how the money was taken.');
            }
        });
    }

    /**
     * One line per ticked course, in tick order.
     *
     * No fee overrides: the catalogue decides the price on this screen.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        return array_map(
            static fn (int|string $id): array => ['course_id' => (int) $id],
            (array) $this->validated()['course_ids'],
        );
    }

    /**
     * What the basket agrees once. The extra fee is added by the controller from settings, because
     * the form is not allowed to name it.
     *
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'admission_date', 'counselor_id', 'discount_amount', 'discount_reason', 'fees_once', 'notes',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'payment_amount', 'payment_method', 'paid_on', 'reference_no', 'idempotency_key',
        ]));
    }
}
