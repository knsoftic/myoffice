<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Flag an inquiry as spam by hand — `admin.contact-inquiries.spam`, `can:contact_inquiries.change_status`
 * (phase-04 §6.10.5 `markSpam(ContactInquiry, string $reason)`, §10.5 "inquiry marked spam — reason").
 *
 * The reason is required (≤ 100, the width of `spam_reason`). Marking spam never deletes a record the
 * inquiry was already routed to; the service records the fact instead.
 */
final class MarkInquirySpamRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'contact_inquiries.change_status';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['bail', 'required', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['reason']);
    }
}
