<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Models\Support\SupportTicket;
use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A portal user raising their own ticket (phase-19-23 §6.16, §7.6).
 *
 * **No `priority` and no `user_id`.** A requester who could declare "urgent" would, every time, and
 * within a month the word would mean nothing (§12.2 Q6) — a portal ticket starts at
 * `support.ticket_default_priority` and staff move it. And a portal user files as themselves, full
 * stop: `user_id` is the admin form's field, gated on a permission no portal role holds.
 *
 * **No subject foreign keys either.** `client_id`, `student_id` and the rest are derived from the
 * requester's own profiles by the service, because §9.4 reads exactly those columns to decide who
 * may see the ticket — a form that could set them could file one against another company.
 */
class RaiseTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SupportTicket::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'ticket_department_id' => [
                'required', 'integer',
                Rule::exists('ticket_departments', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ticket_department_id.exists' => 'That desk is not taking new tickets.',
            'subject.required' => 'Give it a subject — it is the first thing the desk reads.',
            'description.required' => 'Describe what is wrong.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        if (is_string($description)) {
            $this->merge(['description' => RichText::sanitize($description, 'cms')]);
        }
    }
}
