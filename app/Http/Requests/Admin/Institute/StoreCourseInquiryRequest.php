<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\DeliveryMode;
use App\Enums\InquirySource;
use App\Enums\PreferredTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A course enquiry typed by a member of staff (§86, phase-14-17 §7.3).
 *
 * The public form has its own request: this one may name an assignee and a source, which a visitor
 * may not. The referral fields are absent entirely — a staff pick goes through the resolver, and a
 * `collaborator_id` accepted here would be a second way to decide who earns a commission (INV-I4).
 */
final class StoreCourseInquiryRequest extends FormRequest
{
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
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'city' => ['nullable', 'string', 'max:100'],
            'education' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'preferred_delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'preferred_timing' => ['nullable', Rule::enum(PreferredTiming::class)],
            'source' => ['required', Rule::enum(InquirySource::class)],
            'source_url' => ['nullable', 'url', 'max:500'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'follow_up_date' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'An enquiry needs a phone number — it is the only way anybody follows it up.',
            'source.required' => 'Say where this enquiry came from: the §88 conversion report is counted by source.',
        ];
    }
}
