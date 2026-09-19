<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\InquiryType;
use App\Models\Cms\Service;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public contact form — `site.contact.store`, `throttle:public-contact` (phase-04 §6.9, §6.10.5,
 * §6.11 `PublicContactRequest`, §8.11, acceptance tests 49-54, 61-62).
 *
 *   · `inquiry_type` — an `InquiryType` (a form that omits it is a general inquiry);
 *   · `service_id` — nullable, an existing **published** service, required when the type is `service`;
 *   · `course_name` — required when the type is `course` and no `course_id` is given; `course_id` is a
 *     deferred link (§2.1) and is ignored while Phase 14's `courses` table does not exist;
 *   · `budget` — nullable, one of `website.contact_budget_options` (stored verbatim);
 *   · `message` — required, 15-5000 characters (tags are stripped by the service);
 *   · the honeypot (`website_url`) and the signed render token (`form_token`) are declared as nullable
 *     strings only: a filled honeypot or a missing/forged token is **not** a validation error — it
 *     reaches `SpamGuard`, the row is stored as spam and the visitor sees the same success (tests 50-51);
 *   · **referral fields are not accepted from the body.** `collaborator_id`, `referral_visit_id` and
 *     `referral_code` are not in the rules, so they never reach `validated()`; the service reads the
 *     `?ref=` code the public site preserved and discards anything the browser posted (§6.10.5, test 62).
 *
 * Authorization: public by design (the route carries no `can:`). Abuse control is the named limiter and
 * `SpamGuard`, not a login. While `maintenance.contact_form_enabled` is false the POST is a 404 **before**
 * anything is validated, so the answer never depends on the body (`ContactController::store()` repeats it).
 */
final class PublicContactRequest extends FormRequest
{
    /** phase-04 §6.9 `SpamGuard::honeypotField()` / `timestampField()`. */
    public const HONEYPOT_FIELD = 'website_url';

    public const TOKEN_FIELD = 'form_token';

    public function authorize(): bool
    {
        $enabled = setting('maintenance.contact_form_enabled', true);

        return is_bool($enabled) ? $enabled : (filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'inquiry_type' => ['bail', 'required', 'string', Rule::enum(InquiryType::class)],
            'name' => ['bail', 'required', 'string', 'max:150'],
            'email' => ['bail', 'required', 'string', 'max:150', 'email:rfc'],
            'phone' => ['bail', 'nullable', 'string', 'max:32'],
            'whatsapp' => ['bail', 'nullable', 'string', 'max:32'],
            'company' => ['bail', 'nullable', 'string', 'max:150'],
            'service_id' => [
                'bail',
                'nullable',
                'required_if:inquiry_type,'.InquiryType::Service->value,
                'integer',
                'min:1',
                // §9.2: "published" is decided by the model's one public scope, never a hand-written where.
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_numeric($value) && ! Service::query()->public()->whereKey((int) $value)->exists()) {
                        $fail('Choose one of the listed services.');
                    }
                },
            ],
            'course_id' => $this->courseIdRules(),
            'course_name' => [
                'bail',
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('inquiry_type') === InquiryType::Course->value && ! is_numeric($this->input('course_id'))),
                'string',
                'max:150',
            ],
            'budget' => ['bail', 'nullable', 'string', 'max:100', Rule::in($this->budgetOptions())],
            'subject' => ['bail', 'nullable', 'string', 'max:200'],
            'message' => ['bail', 'required', 'string', 'min:15', 'max:5000'],

            // Where the form was and where the visitor came from, as the browser reports them; the service
            // keeps only a safe http(s) URL and truncates it to the column width.
            'page_url' => ['bail', 'nullable', 'string', 'max:2048'],
            'referrer_url' => ['bail', 'nullable', 'string', 'max:2048'],
            'utm_source' => ['bail', 'nullable', 'string', 'max:255'],
            'utm_medium' => ['bail', 'nullable', 'string', 'max:255'],
            'utm_campaign' => ['bail', 'nullable', 'string', 'max:255'],

            self::HONEYPOT_FIELD => ['nullable', 'string', 'max:5000'],
            self::TOKEN_FIELD => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.required_if' => 'Choose the service you are asking about.',
            'course_name.required' => 'Tell us which course you are interested in.',
            'budget.in' => 'Choose one of the listed budget ranges.',
            'message.min' => 'Please tell us a little more — at least :min characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ([
            'inquiry_type', 'name', 'email', 'phone', 'whatsapp', 'company', 'course_name', 'budget', 'subject',
            'message', 'page_url', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign',
        ] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if (! $this->has('inquiry_type') || $this->input('inquiry_type') === '') {
            $clean['inquiry_type'] = InquiryType::General->value;
        }

        if (isset($clean['email'])) {
            $clean['email'] = mb_strtolower($clean['email']);
        }

        $this->merge($clean);
    }

    /**
     * The inquiry fields for `ContactInquiryService::submit()` — the honeypot and token stay on the
     * request, where `SpamGuard` reads them.
     *
     * @return array<string, mixed>
     */
    public function inquiryPayload(): array
    {
        $data = $this->safe()->except([self::HONEYPOT_FIELD, self::TOKEN_FIELD]);

        if (($data['inquiry_type'] ?? null) !== InquiryType::Service->value) {
            $data['service_id'] = null;
        }

        return $data;
    }

    /**
     * `course_id` points at Phase 14's `courses`; until that table exists a posted id is dropped rather
     * than stored against nothing.
     *
     * @return list<mixed>
     */
    private function courseIdRules(): array
    {
        if (! Schema::hasTable('courses')) {
            return ['exclude'];
        }

        return ['bail', 'nullable', 'integer', 'min:1', Rule::exists('courses', 'id')];
    }

    /**
     * @return list<string>
     */
    private function budgetOptions(): array
    {
        $options = setting('website.contact_budget_options', []);

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($options)) {
            return [];
        }

        return array_values(array_filter($options, static fn (mixed $option): bool => is_string($option) && $option !== ''));
    }
}
