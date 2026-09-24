<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Models\Support\SupportTicket;
use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A portal user answering their own ticket (phase-19-23 §6.16).
 *
 * **There is no `visibility` field**, and that is the point rather than an omission. An internal
 * note is the thing a requester must not be able to write, and a field they could post is a field
 * somebody will post. The controller forces `public` and so does the service — two layers saying
 * the same thing, because this is the one where being wrong is silent.
 */
final class ReplyToOwnTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof SupportTicket
            && $this->user()?->can('reply', $ticket) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required' => 'Write something before sending.'];
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => RichText::sanitize($body, 'cms')]);
        }
    }
}
