<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A basket of admissions: one student, one or more courses, one agreed figure ([D172]).
 *
 * **Why this request exists at all.** `AdmissionController::store()` validated inline, with a single
 * `course_id` and one flat set of money fields. Posted against several courses that shape is actively
 * wrong, not merely insufficient: `AdmissionService::figuresFrom()` prefers an override over the
 * course's own price, so one `course_fee` in the payload would price every course in the basket at
 * the first one's fee — quietly, with a valid-looking admission for each.
 *
 * So the money splits in two here. **Per course** (`lines`): the three fees and the monthly fee, each
 * defaulting to that course's catalogue price when the operator leaves it alone. **Per basket**
 * (top level): the discount, the scholarship and the reason, because that is what was agreed with the
 * student — `AdmissionService` divides those across the lines pro-rata.
 *
 * **`course_id` is still accepted.** `StudentApplicationService` and every existing caller post one
 * course, and a request that broke them to gain a plural would be a worse trade than reading both
 * spellings. `prepareForValidation()` folds the singular into `course_ids`, and from there there is
 * one code path.
 *
 * Nothing here validates a fee against the course it belongs to. A negotiated price is the point of
 * the screen — the only money rule is the one the service and `chk_sadm_discount_ceiling` enforce
 * together: reductions may not exceed what is being charged.
 */
final class StoreAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries `can:admissions.create`. Returning true here rather than
        // re-deriving it keeps one answer to "who may do this" instead of two that can disagree.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('course_ids');

        // The singular spelling, folded in. Kept first so an explicit `course_ids` always wins.
        if (! is_array($ids) || $ids === []) {
            $single = $this->input('course_id');

            if ($single !== null && $single !== '') {
                $this->merge(['course_ids' => [$single]]);
            }
        }

        $this->merge(['fees_once' => $this->boolean('fees_once', true)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $exists = Rule::exists('courses', 'id')->whereNull('deleted_at');

        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],

            'course_ids' => ['required', 'array', 'min:1', 'max:12'],
            'course_ids.*' => ['required', 'integer', 'distinct', $exists],

            // Keyed by course id, and every key is checked against the ticked list in withValidator()
            // so a fee cannot be smuggled onto a course that is not in the basket.
            'lines' => ['nullable', 'array'],
            'lines.*.course_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.admission_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.registration_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],

            'admission_date' => ['nullable', 'date'],
            'counselor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'preferred_timing' => ['nullable', Rule::enum(PreferredTiming::class)],

            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'scholarship_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'fees_once' => ['boolean'],

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
            'course_ids.*.distinct' => 'The same course is in this basket twice. Pick it once.',
            'course_ids.max' => 'Twelve courses in one basket is already past the point of a quote — split it.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ticked = array_map('intval', (array) $this->input('course_ids', []));
            $lines = (array) $this->input('lines', []);

            foreach (array_keys($lines) as $key) {
                if (! in_array((int) $key, $ticked, true)) {
                    // Not paranoia about an attacker — it is the shape of a stale form. The operator
                    // unticks a course, the browser keeps its fee inputs, and the payload now carries
                    // a price for something that is not being sold. Silently ignoring it would be the
                    // wrong kind of forgiving: the figure on their screen and the figure stored would
                    // differ and nothing would say so.
                    $validator->errors()->add('lines', sprintf(
                        'A fee was submitted for a course that is not ticked (id %s). Reload the form '
                        .'and enter the basket again.',
                        (string) $key,
                    ));
                }
            }
        });
    }

    /**
     * The basket, in the order the courses were ticked.
     *
     * Order decides which line carries the one-off admission and registration fees, so it is read
     * from `course_ids` rather than from `lines` — a browser is free to reorder an object's keys and
     * is not free to reorder an array.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $lines = (array) $this->input('lines', []);

        return array_map(static function (int|string $id) use ($lines): array {
            $line = (array) ($lines[(string) $id] ?? $lines[(int) $id] ?? []);

            return [
                'course_id' => (int) $id,
                'course_fee' => $line['course_fee'] ?? null,
                'admission_fee' => $line['admission_fee'] ?? null,
                'registration_fee' => $line['registration_fee'] ?? null,
                'monthly_fee' => $line['monthly_fee'] ?? null,
            ];
        }, (array) $this->validated()['course_ids']);
    }

    /**
     * What the basket agreed once.
     *
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        $validated = $this->validated();

        return array_intersect_key($validated, array_flip([
            'admission_date', 'counselor_id', 'delivery_mode', 'preferred_timing',
            'discount_amount', 'scholarship_amount', 'discount_reason', 'notes', 'fees_once',
        ]));
    }
}
