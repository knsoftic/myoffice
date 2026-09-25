<?php

declare(strict_types=1);

namespace App\Http\Requests\Site;

use App\Enums\InquiryType;
use App\Services\Cms\SpamGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public request-a-quote form — `site.quote.store`, `throttle:public-contact`.
 *
 * **It is a sibling of `Cms\PublicContactRequest`, not a replacement for it, and it could not reuse it.**
 * That request is the contact page's shape: it takes `inquiry_type` from the browser and makes
 * `service_id` **required** whenever that type is `service`. A quote page has no service picker — the
 * whole point is that the visitor describes what they want in their own words — so reusing it would
 * have meant either bolting a catalogue dropdown onto a conversion form or posting a type the visitor
 * could change. Here the type is decided on the server and never read from the body (see
 * `inquiryPayload()`), and the fields are the four a quote actually needs.
 *
 *   · `project`  — required, 20-5000 characters: what they want built, in their words;
 *   · `budget`   — nullable, one of `website.contact_budget_options`, stored verbatim in the `budget`
 *                  column exactly as the contact form stores it (the same list, so the inbox compares
 *                  like with like);
 *   · `timeline` — nullable, one of `TIMELINE_OPTIONS`. `contact_inquiries` has no timeline column and
 *                  this phase adds none, so the chosen band is appended to the message as a labelled
 *                  line. It is a form option list, not a status: it lives here, in one place, and the
 *                  controller hands the same constant to the view so the two can never drift.
 *   · `name`, `email`, `phone` — how to reach them. Anything else can be asked in the reply.
 *
 * **The spam guard is wired exactly as the contact form wires it.** The honeypot and the signed render
 * token are declared as nullable strings only: a filled honeypot or a missing, forged or stale token is
 * **not** a validation error. It reaches `SpamGuard` through `ContactInquiryService::submit()`, the row
 * is stored flagged as spam, and the visitor sees the same success a genuine sender sees — a bot learns
 * nothing from the answer. Both fields are kept off `inquiryPayload()`; the guard reads them from the
 * request itself.
 *
 * Authorization is public by design (the route carries no `can:`), but it is **not unconditional**: the
 * POST is a 404 while `website.quote_page_enabled` is off (the page does not exist) or while
 * `maintenance.contact_form_enabled` is off (the inbox is closed — quote requests land in it). Checking
 * it here as well as in the controller matters: a Form Request validates before the controller method
 * runs, so without this the body of a request to a switched-off page would decide between 404 and 422.
 */
final class PublicQuoteRequest extends FormRequest
{
    /**
     * The rough delivery bands offered on the form. The single source of truth: the controller passes
     * this constant to the view, and `Rule::in()` below validates against the same array.
     *
     * @var list<string>
     */
    public const TIMELINE_OPTIONS = [
        'As soon as possible',
        'Within a month',
        '1 - 3 months',
        '3 - 6 months',
        'More than 6 months away',
        'Just exploring for now',
    ];

    /** Every quote request carries this subject, so the shared inbox can see where it came from. */
    public const SUBJECT = 'Quote request';

    public function authorize(): bool
    {
        return $this->flag('website.quote_page_enabled', false)
            && $this->flag('maintenance.contact_form_enabled', true);
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
            'project' => ['bail', 'required', 'string', 'min:20', 'max:5000'],
            'budget' => ['bail', 'nullable', 'string', 'max:100', Rule::in($this->budgetOptions())],
            'timeline' => ['bail', 'nullable', 'string', 'max:100', Rule::in(self::TIMELINE_OPTIONS)],
            'name' => ['bail', 'required', 'string', 'max:150'],
            'email' => ['bail', 'required', 'string', 'max:150', 'email:rfc'],
            'phone' => ['bail', 'nullable', 'string', 'max:32'],

            // Where the form was and where the visitor came from, as the browser reports them; the
            // service keeps only a safe http(s) URL and truncates it to the column width.
            'page_url' => ['bail', 'nullable', 'string', 'max:2048'],
            'referrer_url' => ['bail', 'nullable', 'string', 'max:2048'],

            // Never validation errors — see the class docblock.
            SpamGuard::HONEYPOT_FIELD => ['nullable', 'string', 'max:5000'],
            SpamGuard::TIMESTAMP_FIELD => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project.required' => 'Tell us what you would like us to build.',
            'project.min' => 'A line or two more, please — at least :min characters.',
            'budget.in' => 'Choose one of the listed budget ranges.',
            'timeline.in' => 'Choose one of the listed timelines.',
            'email.email' => 'That does not look like an email address we could reply to.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'project' => 'project description',
            'timeline' => 'timeline',
            'budget' => 'budget',
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['project', 'budget', 'timeline', 'name', 'email', 'phone', 'page_url', 'referrer_url'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if (isset($clean['email'])) {
            $clean['email'] = mb_strtolower($clean['email']);
        }

        $this->merge($clean);
    }

    /**
     * The inquiry fields for `ContactInquiryService::submit()`.
     *
     * **`inquiry_type` is set here and is never read from the body.** A quote request is a service
     * enquiry — `InquiryType::Service`, the case the enum already has and the one that routes to a CRM
     * lead. There is no `quote` case and inventing one would be rejected by the CHECK constraint behind
     * the column anyway. Because the type is not a form field, no browser can retype a quote as
     * something that routes elsewhere.
     *
     * The chosen timeline has no column of its own, so it is appended to the message as a labelled
     * line; the budget goes to the `budget` column, so it is not repeated in the text. `service_id` is
     * deliberately absent — this form names no service, and the service stores null for a missing one.
     *
     * The honeypot and the token stay on the request, where `SpamGuard` reads them.
     *
     * @return array<string, mixed>
     */
    public function inquiryPayload(): array
    {
        $data = $this->safe()->except([SpamGuard::HONEYPOT_FIELD, SpamGuard::TIMESTAMP_FIELD]);

        $brief = (string) ($data['project'] ?? '');
        $timeline = is_string($data['timeline'] ?? null) ? $data['timeline'] : null;

        return [
            'inquiry_type' => InquiryType::Service->value,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'budget' => $data['budget'] ?? null,
            'subject' => self::SUBJECT,
            'message' => $timeline === null ? $brief : $brief."\n\nRough timeline: ".$timeline,
            'page_url' => $data['page_url'] ?? null,
            'referrer_url' => $data['referrer_url'] ?? null,
        ];
    }

    /**
     * The contact form's budget list, read from settings — the same labels, so one inbox does not hold
     * two vocabularies for the same question.
     *
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

    private function flag(string $key, bool $default): bool
    {
        $value = setting($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
