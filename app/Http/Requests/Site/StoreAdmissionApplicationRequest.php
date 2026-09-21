<?php

declare(strict_types=1);

namespace App\Http\Requests\Site;

use App\Enums\CourseStatus;
use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §67's public admission form (phase-14-17 §6.4, §7.10).
 *
 * **Nothing the server decides is accepted from the browser.** `collaborator_id`,
 * `referral_code_valid`, `status`, `application_number` and the conversion columns have no rules here
 * and are stripped by `validated()` never containing them — who referred somebody is resolved
 * server-side through Phase 9's ladder, and a form that could name a partner would let anybody assign
 * a commission (INV-I4). The typed `referral_code` IS accepted, because it is a claim to be checked,
 * not a decision.
 *
 * **Two anti-spam guards that cost a real applicant nothing.** The honeypot must be empty — a bot
 * fills every field it finds — and the form must be at least two seconds old, because nobody reads
 * and completes an admission form faster than that. Both are silent to a human and both refuse
 * without telling the sender which one caught them.
 *
 * The course must be published and open. That is re-checked in the service too: this stops the
 * obvious case early and with a field error, and the service is what makes it true under a race.
 */
final class StoreAdmissionApplicationRequest extends FormRequest
{
    /** How long a human takes, at the very least, to fill this in. */
    private const MINIMUM_SECONDS = 2;

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
            'name' => ['required', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{7,32}$/'],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{7,32}$/'],
            // `rfc` and not `dns`: a DNS lookup on every submit makes the form as slow and as
            // available as somebody else's nameserver, and it rejects real addresses at domains with
            // no MX record. The address is confirmed by somebody calling the applicant, not by us.
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'city' => ['nullable', 'string', 'max:100'],
            'education' => ['nullable', 'string', 'max:150'],

            'course_id' => [
                'required', 'integer',
                Rule::exists('courses', 'id')
                    ->where('status', CourseStatus::Published->value)
                    ->whereNull('deleted_at'),
            ],
            'batch_id' => [
                (bool) setting('institute.admission_form_require_batch', false) ? 'required' : 'nullable',
                'integer',
            ],
            'preferred_timing' => ['nullable', Rule::enum(PreferredTiming::class)],
            'preferred_delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'message' => ['nullable', 'string', 'max:2000'],

            // A claim, kept verbatim whether it resolves or not.
            'referral_code' => ['nullable', 'string', 'max:32'],
            // Phase 9's hashed visit token, rendered by <x-site.referral-field>. Never a raw code and
            // never a collaborator id (INV-R2).
            'referral_visit_token' => ['nullable', 'string', 'max:255'],

            'idempotency_key' => ['required', 'string', 'max:64'],
            'form_rendered_at' => ['required', 'numeric'],
            // The honeypot. Named for something a bot wants to fill and hidden from a person.
            'website' => ['nullable', 'prohibited'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $rendered = (int) $this->input('form_rendered_at', 0);

            if ($rendered > 0 && (time() - $rendered) < self::MINIMUM_SECONDS) {
                // Deliberately attached to a real field and worded as a retry: a message naming the
                // timing check would tell a bot author exactly what to change.
                $v->errors()->add('name', 'That was submitted a little too quickly. Please try again.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.required' => 'Choose the course you want to apply for.',
            'course_id.exists' => 'That course is not open for admission.',
            'batch_id.required' => 'Choose the batch you would like to join.',
            'phone.required' => 'We need a phone number to be able to call you back.',
            'phone.regex' => 'That does not look like a phone number.',
            'website.prohibited' => 'Something went wrong with that submission. Please try again.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'course_id' => 'course',
            'batch_id' => 'batch',
            'preferred_delivery_mode' => 'how you want to study',
            'preferred_timing' => 'preferred timing',
        ];
    }
}
