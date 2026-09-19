<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\ContactInquiryStatus;
use Illuminate\Validation\Rule;

/**
 * Mark an inquiry read / in progress / responded / closed — `admin.contact-inquiries.status`,
 * `can:contact_inquiries.change_status` (phase-04 §6.10.5 `changeStatus()`, §8.10).
 *
 * `status` is a `ContactInquiryStatus`; `note` is optional and travels into the activity entry.
 */
final class ChangeInquiryStatusRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'contact_inquiries.change_status';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::enum(ContactInquiryStatus::class)],
            'note' => ['bail', 'nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['status', 'note']);
    }

    public function inquiryStatus(): ContactInquiryStatus
    {
        return ContactInquiryStatus::from((string) $this->validated('status'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
