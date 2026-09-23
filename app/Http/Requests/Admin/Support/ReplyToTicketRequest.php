<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Support;

use App\Enums\ReplyVisibility;
use App\Models\Support\SupportTicket;
use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reply, from any panel (phase-19-23 §6.16).
 *
 * **`visibility` is validated but not trusted.** `TicketService::reply()` forces a portal user's
 * reply public whatever arrives here, because a field the requester controls must never be able to
 * mint a note only staff were meant to read. Validating it as well means a staff member who posts a
 * value the enum does not know is told so, rather than having it silently fall back.
 *
 * **The `addInternalNote` authorisation is a separate policy method**, so asking for an internal
 * note from a panel that may not write one is refused here rather than downgraded silently — a
 * downgrade would publish to the requester something the author believed was private.
 */
class ReplyToTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        if (! $ticket instanceof SupportTicket || $this->user()?->can('reply', $ticket) !== true) {
            return false;
        }

        if ((string) $this->input('visibility') === ReplyVisibility::InternalNote->value) {
            return $this->user()->can('addInternalNote', $ticket) === true;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
            'visibility' => ['nullable', 'string', Rule::in(array_column(ReplyVisibility::cases(), 'value'))],
            'attachments_count' => ['nullable', 'integer', 'min:0', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => RichText::sanitize($body, 'cms')]);
        }
    }
}
