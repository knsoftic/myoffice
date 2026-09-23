<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Support;

use App\Enums\Priority;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a ticket from the admin panel (phase-19-23 §6.16, §7.6).
 *
 * **Staff may raise a ticket *on behalf of* somebody**, which is what `user_id` is for — a client
 * phones, and the agent files it under their name so it appears in their portal and the replies
 * reach them. It is the one place a requester is chosen rather than derived, and it is gated on
 * `support_tickets.create`, a permission no portal role holds.
 *
 * **No subject foreign key is accepted.** `client_id`, `student_id` and the rest are derived by
 * `TicketService::subjectsFor()` from the requester's own profiles. A form that could set them could
 * file a ticket *against* another company, and §9.4 reads exactly those columns to decide who sees
 * it.
 *
 * **The description is sanitised here rather than in the service**, because the service takes a
 * plain array from four different panels and each would otherwise have to remember. One place.
 */
class StoreTicketRequest extends FormRequest
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
            'priority' => ['nullable', 'string', Rule::in(array_column(Priority::cases(), 'value'))],

            // Raising one for somebody else. Absent means "for me".
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],

            'is_private_to_creator' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ticket_department_id.exists' => 'That desk is not open for new tickets.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        if (is_string($description)) {
            $this->merge(['description' => RichText::sanitize($description, 'cms')]);
        }
    }

    /**
     * Who the ticket is for.
     *
     * Defaults to the person filing it. A named requester who no longer exists falls back to the
     * filer rather than throwing: the validation rule already refused a deleted user, so reaching
     * here with nothing means the row went between the two, and losing the ticket would be worse
     * than filing it under the agent who typed it.
     */
    public function requester(): User
    {
        $id = $this->integer('user_id');

        if ($id > 0) {
            $requester = User::query()->find($id);

            if ($requester instanceof User) {
                return $requester;
            }
        }

        return $this->user();
    }
}
