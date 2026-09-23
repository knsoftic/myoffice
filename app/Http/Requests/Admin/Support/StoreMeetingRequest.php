<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Support;

use App\DataObjects\Support\MeetingData;
use App\DataObjects\Support\ParticipantInput;
use App\Enums\DeliveryMode;
use App\Enums\MeetingParticipantRole;
use App\Models\Support\Meeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking a meeting (phase-19-23 §6.17, §7.6).
 *
 * **Status, the four counts and `rescheduled_from_id` are absent**, as they are from
 * {@see MeetingData}: what a person decides is here, what the system
 * concludes is not. A form that could post `attended_count` could disagree with the attendance rows
 * underneath it.
 *
 * **`participant_type` is absent too**, and that is the rule §6.17 states outright — it is derived
 * from the person's own profiles by `ParticipantResolver`, because a field on the form is a student
 * filing themselves as staff, and §9.4's scope reads that column.
 *
 * **The online / in-person rules are checked in the service as well.** `MeetingService` is reached
 * from four panels and a command; a rule that lived only in one Form Request would be a rule three
 * callers did not have.
 */
class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $meeting = $this->route('meeting');

        return $meeting instanceof Meeting
            ? $this->user()?->can('update', $meeting) === true
            : $this->user()?->can('create', Meeting::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'delivery_mode' => ['required', 'string', Rule::in(array_column(DeliveryMode::cases(), 'value'))],

            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'agenda' => ['nullable', 'string', 'max:20000'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')->whereNull('deleted_at')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->whereNull('deleted_at')],
            'collaborator_id' => ['nullable', 'integer', Rule::exists('collaborators', 'id')->whereNull('deleted_at')],
            'support_ticket_id' => ['nullable', 'integer', Rule::exists('support_tickets', 'id')->whereNull('deleted_at')],

            'is_private' => ['nullable', 'boolean'],
            'reminder_minutes_before' => ['nullable', 'integer', 'min:0', 'max:10080'],

            'participants' => ['nullable', 'array', 'max:100'],
            'participants.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'participants.*.external_name' => ['nullable', 'string', 'max:150'],
            'participants.*.external_email' => ['nullable', 'email:rfc', 'max:180'],
            'participants.*.role' => ['nullable', 'string', Rule::in(array_column(MeetingParticipantRole::cases(), 'value'))],
            'participants.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_minutes.min' => 'A meeting runs for at least five minutes.',
            'duration_minutes.max' => 'A meeting runs for at most a full day. Anything longer is two meetings.',
            'meeting_url.url' => 'A joining link has to be a web address people can click.',
        ];
    }

    /**
     * The guest list, as objects.
     *
     * Empty rows are dropped rather than refused: a repeater whose last line is blank is the normal
     * way this field arrives, and an error about a line nobody filled in is an error about the form
     * rather than about the meeting.
     *
     * @return list<ParticipantInput>
     */
    public function participants(): array
    {
        $out = [];

        foreach ((array) $this->validated('participants', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $input = ParticipantInput::fromArray($row);

            if ($input !== null) {
                $out[] = $input;
            }
        }

        return $out;
    }
}
