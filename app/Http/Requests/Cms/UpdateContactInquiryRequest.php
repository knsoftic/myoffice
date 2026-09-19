<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Enums\ContactInquiryStatus;
use Illuminate\Validation\Rule;

/**
 * Save the internal handling notes of an inquiry — `admin.contact-inquiries.update`,
 * `can:contact_inquiries.edit` (phase-04 §6.11 `UpdateContactInquiryRequest`, §8.10).
 *
 * `response_notes` ≤ 5000 and an optional `status` (`ContactInquiryStatus`). What the visitor submitted
 * is the permanent record and is never editable (§6.10 principle); routing, spam and assignment have their
 * own endpoints under their own abilities.
 */
final class UpdateContactInquiryRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'contact_inquiries.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'response_notes' => ['sometimes', 'bail', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'bail', 'nullable', 'string', Rule::enum(ContactInquiryStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['response_notes', 'status']);
    }

    public function hasNotes(): bool
    {
        return array_key_exists('response_notes', $this->validated());
    }

    public function notes(): ?string
    {
        $notes = $this->validated('response_notes');

        return is_string($notes) ? $notes : null;
    }

    public function inquiryStatus(): ?ContactInquiryStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? ContactInquiryStatus::tryFrom($status) : null;
    }
}
