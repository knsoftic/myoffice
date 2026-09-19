<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\LeadData;
use App\Enums\InquirySource;
use App\Enums\LeadFollowUpType;
use App\Models\Crm\Lead;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Create a lead — `admin.leads.store`, `can:leads.create` (phase-05 §2.1, §6.1 `create()`, §8.4).
 *
 * The §18 fields plus the operational ones a person may type: contact, interest (service, budget), source and
 * referral, notes, an optional assignee and an optional first follow-up. `lead_no`, `status`, `client_id`,
 * `contact_inquiry_id`, the normalised columns and every timestamp are **not** accepted — the service writes them.
 *
 *   · `assigned_to` is accepted only from someone holding `leads.assign`, and must name an active user who holds
 *     `leads.view` (the auto-assign pool rule of §5); anyone else is prohibited from sending it at all.
 *   · `budget_amount` is a decimal(15,2) string, never a float, and never negative (CHECK `chk_leads_budget`).
 *   · `confirm_duplicate` is the "I have seen the duplicate warning" tick of §8.4 (test 22).
 *   · `duplicate_of_lead_id` + `duplicate_note` come from "Link as duplicate" on a match; the controller calls
 *     `LeadService::linkDuplicate()` once the lead exists. The original must be a lead the actor can see.
 *   · The form's `referral_code_captured` is accepted as `referral_code`, and `add_follow_up` off discards the
 *     follow-up block.
 */
class StoreLeadRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('leads.create') && $this->actorCan('create', Lead::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->profileRules(), [
            'assigned_to' => [
                Rule::prohibitedIf(fn (): bool => ! $this->actorCan('leads.assign')),
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('leads.view', 'Choose an active user who can work on leads.'),
            ],
            'referral_code' => ['nullable', 'string', 'max:32', 'regex:'.self::REFERRAL_CODE_PATTERN],
            'confirm_duplicate' => ['nullable', 'boolean'],
            'add_follow_up' => ['nullable', 'boolean'],
            'duplicate_of_lead_id' => ['nullable', 'integer', 'min:1', $this->visibleLeadRule()],
            'duplicate_note' => ['required_with:duplicate_of_lead_id', 'nullable', 'string', 'max:255'],
        ], $this->followUpRules('follow_up', false, LeadFollowUpType::values()));
    }

    /**
     * The §18 profile fields shared by create and update — exactly the `LeadData::PROFILE_FIELDS` keys.
     *
     * @return array<string, list<mixed>>
     */
    protected function profileRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'phone' => $this->phoneRules(),
            'whatsapp' => $this->phoneRules(),
            'country' => ['nullable', 'string', 'max:64'],
            'country_code' => $this->countryCodeRules(),
            'service_id' => ['nullable', 'integer', 'min:1', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'interested_service' => ['nullable', 'string', 'max:150'],
            'budget_amount' => $this->moneyRules(),
            'source' => ['nullable', 'string', Rule::enum(InquirySource::class)],
            'source_detail' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'service_id' => 'interested service',
            'budget_amount' => 'budget',
            'assigned_to' => 'assignee',
            'follow_up.type' => 'follow-up type',
            'follow_up.scheduled_at' => 'follow-up date',
            'follow_up.remind_before_minutes' => 'reminder',
            'follow_up.assigned_to' => 'follow-up owner',
            'follow_up.notes' => 'follow-up notes',
        ];
    }

    /**
     * An id naming a lead the actor can see (the model's visibility scope applies); anything else reads as "not
     * found", so ids cannot be probed.
     */
    protected function visibleLeadRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_numeric($value)) {
                return;
            }

            $lead = Lead::query()->find((int) $value);

            if (! $lead instanceof Lead || ! $this->actorCan('view', $lead)) {
                $fail('That lead was not found.');
            }
        };
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
            'name', 'company', 'email', 'phone', 'whatsapp', 'country', 'country_code', 'service_id',
            'interested_service', 'budget_amount', 'source', 'source_detail', 'notes', 'assigned_to', 'referral_code',
            'referral_code_captured', 'duplicate_of_lead_id', 'duplicate_note',
        ]);

        if (! $this->filled('referral_code') && is_string($this->input('referral_code_captured'))) {
            $this->merge(['referral_code' => $this->input('referral_code_captured')]);
        }

        if ($this->has('add_follow_up') && ! $this->boolean('add_follow_up')) {
            $this->merge(['follow_up' => null]);

            return;
        }

        $followUp = $this->input('follow_up');

        // An untouched follow-up block (every input blank) is "no follow-up", not a half-filled one.
        if (is_array($followUp) && array_filter($followUp, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
            $this->merge(['follow_up' => null]);
        }
    }

    /**
     * The lead the new one should be linked to as a duplicate, with the note, or null.
     *
     * @return array{lead: Lead, note: string}|null
     */
    public function duplicateLink(): ?array
    {
        $id = $this->validated('duplicate_of_lead_id');

        if (! is_numeric($id)) {
            return null;
        }

        $lead = Lead::query()->find((int) $id);

        return $lead instanceof Lead && $this->actorCan('view', $lead)
            ? ['lead' => $lead, 'note' => trim((string) $this->validated('duplicate_note'))]
            : null;
    }

    /**
     * The validated payload as the service's DTO.
     */
    public function toData(): LeadData
    {
        return LeadData::fromArray($this->validated());
    }
}
