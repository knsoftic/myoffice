<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\ContactCandidate;
use App\Models\Crm\Lead;

/**
 * The live duplicate check of the lead form — `admin.leads.duplicate-check`, `can:leads.create`, throttled
 * (phase-05 §6.2, §8.4).
 *
 * The keys `ContactCandidate::fromArray()` reads, at least one of them. `ignore_lead_id` excludes the lead being
 * edited from its own report; it is honoured only for a lead the actor can see, so it can never be used to learn
 * whether some other id exists.
 */
final class DuplicateCheckRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('leads.create');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'phone' => array_merge(['required_without_all:whatsapp,email'], $this->phoneRules()),
            'whatsapp' => array_merge(['required_without_all:phone,email'], $this->phoneRules()),
            'email' => ['required_without_all:phone,whatsapp', 'nullable', 'string', 'email:rfc', 'max:150'],
            'country_code' => $this->countryCodeRules(),
            'ignore_lead_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required_without_all' => 'Enter a phone, WhatsApp number or email to check.',
            'whatsapp.required_without_all' => 'Enter a phone, WhatsApp number or email to check.',
            'email.required_without_all' => 'Enter a phone, WhatsApp number or email to check.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['phone', 'whatsapp', 'email', 'country_code', 'ignore_lead_id']);
    }

    public function candidate(): ContactCandidate
    {
        return ContactCandidate::fromArray($this->validated());
    }

    /**
     * The lead to leave out of the report — only when the actor can see it (the lead model's visibility scope
     * applies to this lookup).
     */
    public function ignoredLeadId(): ?int
    {
        $id = $this->validated('ignore_lead_id');

        if (! is_numeric($id)) {
            return null;
        }

        return Lead::query()->whereKey((int) $id)->exists() ? (int) $id : null;
    }
}
