<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ResolvesContentModule;

/**
 * Approve or reject one testimonial or student review (phase-04 §6.5, §6.11 `ModerationRequest`,
 * acceptance tests 16-17):
 *
 *   `admin.testimonials.approve`     `can:testimonials.approve`
 *   `admin.testimonials.reject`      `can:testimonials.reject`
 *   `admin.student-reviews.approve`  `can:student_reviews.approve`
 *   `admin.student-reviews.reject`   `can:student_reviews.reject`
 *
 * The action is the route, never a form field: `action` is overwritten from the route name before the
 * rules run, so `reason` — `required_if:action,reject,reset, max:255` — cannot be dodged by posting
 * `action=approve` to the reject endpoint. On approve the text is an optional note for the activity log.
 */
final class ModerationRequest extends CmsFormRequest
{
    use ResolvesContentModule;

    private const MODULES = ['testimonials', 'student_reviews'];

    private const ACTIONS = ['approve' => 'approve', 'reject' => 'reject'];

    protected function permission(): ?string
    {
        $action = $this->contentAction();

        if (! in_array($this->contentModule(), self::MODULES, true) || ! isset(self::ACTIONS[(string) $action])) {
            return null;
        }

        return $this->contentPermission(self::ACTIONS[$action]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject,reset'],
            'reason' => ['bail', 'nullable', 'required_if:action,reject,reset', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'Give a reason — it is stored with the record and shown to the team.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        $this->merge([
            'action' => (string) $this->contentAction(),
            'reason' => is_string($reason) ? (trim($reason) === '' ? null : trim($reason)) : $reason,
        ]);
    }

    /**
     * The approval note (optional) or the rejection reason (required).
     */
    public function note(): ?string
    {
        return $this->reason();
    }
}
