<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\FollowUpData;
use App\Enums\LeadFollowUpType;
use App\Models\Crm\Lead;
use Illuminate\Validation\Rule;

/**
 * Schedule a follow-up on a lead — `admin.leads.follow-ups.store`, `can:update,lead` (phase-05 §2.3, §6.3
 * `schedule()`, §8.3).
 *
 * The keys `FollowUpData::fromArray()` reads. `scheduled_at` is typed in the display timezone and may not be
 * earlier than the start of today there; `remind_before_minutes` blank means `crm.follow_up_reminder_minutes`;
 * `assigned_to` blank means the lead's assignee and otherwise must be an active user holding `leads.view`. A second
 * open follow-up is refused by the database (`uq_lfu_open`) and surfaced by the controller as a 422, never a 500
 * (test 26).
 */
final class StoreLeadFollowUpRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $lead = $this->boundModel('lead', Lead::class);

        return $lead instanceof Lead && $this->actorCan('update', $lead);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(LeadFollowUpType::class)],
            'scheduled_at' => ['required', 'string', 'date', $this->notBeforeTodayRule()],
            'remind_before_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            'assigned_to' => [
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('leads.view', 'Choose an active user who can work on leads.'),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scheduled_at' => 'date and time',
            'remind_before_minutes' => 'reminder',
            'assigned_to' => 'owner',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['type', 'scheduled_at', 'remind_before_minutes', 'assigned_to', 'notes']);
    }

    public function toData(): FollowUpData
    {
        return FollowUpData::fromArray($this->validated());
    }
}
