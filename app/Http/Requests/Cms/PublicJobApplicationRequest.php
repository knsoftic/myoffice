<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Services\Cms\ApplicationCvService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public application form — `site.careers.apply`, `site_module:jobs`, `throttle:public-apply`
 * (phase-04 §6.8, §6.11 `PublicJobApplicationRequest`, acceptance tests 33-36).
 *
 * The CV rule set of §6.8, verbatim:
 *
 *   · `required`, `file`;
 *   · `mimes:` — `pdf,doc,docx` intersected with `website.cv_allowed_types`;
 *   · `mimetypes:` — `application/pdf`, `application/msword`,
 *     `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, read with finfo from the
 *     file itself (a `.php` renamed `.pdf` fails here, and again in `ApplicationCvService::store()`);
 *   · `max:` — `min(website.cv_max_mb, security.max_upload_mb)` × 1024 KB (never above PHP's own limit).
 *
 * Plus: `email` `required, email:rfc,dns, max:150` (stored lower-cased), `cover_letter` ≤ 5000, the
 * money field `expected_salary` as a decimal string, and the honeypot + render token declared as
 * nullable strings — a bot's verdict is `SpamGuard`'s, and a spam application answers the same success
 * while storing nothing (§6.8 invariant 2). The stage, the source, the reviewer and the IP are never
 * accepted from the body.
 */
final class PublicJobApplicationRequest extends FormRequest
{
    /** The whole allowlist of §6.8; the setting can only narrow it. */
    public const CV_EXTENSIONS = ['pdf', 'doc', 'docx'];

    public const CV_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Public by design — but a switched-off careers board (`website.careers_enabled = false`) refuses the
     * POST as a 404 **before** anything is validated, so the answer never depends on the body (§8.11,
     * test 36). `CareerController::apply()` repeats the check.
     */
    public function authorize(): bool
    {
        $enabled = setting('website.careers_enabled', true);

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
            'applicant_name' => ['bail', 'required', 'string', 'max:150'],
            'email' => ['bail', 'required', 'string', 'max:150', 'email:rfc,dns'],
            'phone' => ['bail', 'required', 'string', 'max:32'],
            'whatsapp' => ['bail', 'nullable', 'string', 'max:32'],
            'city' => ['bail', 'nullable', 'string', 'max:100'],
            'experience_years' => ['bail', 'nullable', 'integer', 'between:0,40'],
            'expected_salary' => ['bail', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'cover_letter' => ['bail', 'nullable', 'string', 'max:5000'],
            'portfolio_url' => ['bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'linkedin_url' => ['bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'cv' => [
                'bail',
                'required',
                'file',
                'mimes:'.implode(',', self::allowedExtensions()),
                'mimetypes:'.implode(',', self::CV_MIME_TYPES),
                'max:'.self::maxKilobytes(),
            ],

            PublicContactRequest::HONEYPOT_FIELD => ['nullable', 'string', 'max:5000'],
            PublicContactRequest::TOKEN_FIELD => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cv.required' => 'Attach your CV.',
            'cv.mimes' => 'Your CV must be a '.strtoupper(implode(', ', self::allowedExtensions())).' file.',
            'cv.mimetypes' => 'Your CV must be a real PDF or Word document.',
            'cv.max' => 'Your CV is larger than the :max KB limit.',
            'cv.uploaded' => 'The CV did not finish uploading. It may be larger than the server allows.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['applicant_name', 'email', 'phone', 'whatsapp', 'city', 'cover_letter', 'portfolio_url', 'linkedin_url', 'expected_salary'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if (isset($clean['email'])) {
            $clean['email'] = mb_strtolower($clean['email']);
        }

        if (isset($clean['expected_salary'])) {
            $clean['expected_salary'] = str_replace([',', ' '], '', $clean['expected_salary']);
        }

        $this->merge($clean);
    }

    /**
     * The application fields for `JobApplicationService::apply()` — the CV, honeypot and token travel
     * on the request.
     *
     * @return array<string, mixed>
     */
    public function applicationPayload(): array
    {
        $data = $this->safe()->except(['cv', PublicContactRequest::HONEYPOT_FIELD, PublicContactRequest::TOKEN_FIELD]);

        if (array_key_exists('expected_salary', $data) && $data['expected_salary'] !== null) {
            $data['expected_salary'] = (string) $data['expected_salary'];
        }

        return $data;
    }

    public function cv(): UploadedFile
    {
        $file = $this->file('cv');

        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /**
     * `pdf,doc,docx` ∩ `website.cv_allowed_types` — read from `ApplicationCvService`, the second gate,
     * so the request and the service can never disagree about what a CV may be.
     *
     * @return list<string>
     */
    private static function allowedExtensions(): array
    {
        $allowed = array_values(array_intersect(self::CV_EXTENSIONS, app(ApplicationCvService::class)->allowedExtensions()));

        return $allowed === [] ? self::CV_EXTENSIONS : $allowed;
    }

    /**
     * `min(website.cv_max_mb, security.max_upload_mb)` in kilobytes, never above PHP's limit — the
     * service's own figure.
     */
    private static function maxKilobytes(): int
    {
        return app(ApplicationCvService::class)->maxKilobytes();
    }
}
