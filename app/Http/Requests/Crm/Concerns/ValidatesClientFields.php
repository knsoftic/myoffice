<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm\Concerns;

use App\Enums\ClientType;
use App\Enums\InquirySource;
use App\Http\Requests\Crm\CrmFormRequest;
use Illuminate\Validation\Rule;

/**
 * The §19 client fields, declared once for the client form and for the client half of the conversion wizard
 * (phase-05 §2.7, §6.4 step 3, §6.7) — exactly the `ClientData::FIELDS` keys a person may type.
 *
 * `client_code`, `user_id`, `status`, `portal_enabled`, `portal_invited_at`, `lead_id`, the normalised columns and
 * every timestamp are not listed, so a posted value never reaches `validated()`. Tax percentages follow the
 * decimal(8,4) 0-100 convention and are refused outside it here **and** by the CHECK constraint (test 56).
 *
 * @mixin CrmFormRequest
 */
trait ValidatesClientFields
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function clientFieldRules(string $prefix = '', bool $partial = false): array
    {
        $rules = [
            'client_type' => ['required', 'string', Rule::enum(ClientType::class)],
            'name' => ['required', 'string', 'max:150'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'phone' => $this->phoneRules(),
            'whatsapp' => $this->phoneRules(),
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'industry' => ['nullable', 'string', 'max:96'],
            'about' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:96'],
            'state' => ['nullable', 'string', 'max:96'],
            'postal_code' => ['nullable', 'string', 'max:24'],
            'country' => ['nullable', 'string', 'max:64'],
            'country_code' => $this->countryCodeRules(),
            'billing_same_as_address' => ['nullable', 'boolean'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'tax_registered' => ['nullable', 'boolean'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'sales_tax_number' => ['nullable', 'string', 'max:64'],
            'cnic' => ['nullable', 'string', 'max:24', 'regex:/^[0-9][0-9\-]{4,22}[0-9]$/'],
            'tax_exempt' => ['nullable', 'boolean'],
            'tax_rate_override' => $this->rateRules(),
            'withholding_tax_rate' => $this->rateRules(),
            'tax_notes' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'source' => ['nullable', 'string', Rule::enum(InquirySource::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];

        $out = [];

        foreach ($rules as $field => $fieldRules) {
            $out[$prefix.$field] = $partial ? array_merge(['sometimes'], $fieldRules) : $fieldRules;
        }

        return $out;
    }

    /**
     * The client field names (without prefix), for `prepareForValidation()` trimming.
     *
     * @return list<string>
     */
    protected function clientFieldNames(): array
    {
        return array_keys($this->clientFieldRules());
    }

    /**
     * The nested primary-contact block of a new client (`primary_contact.*`, `ClientContactData` keys).
     *
     * @return array<string, list<mixed>>
     */
    protected function primaryContactRules(): array
    {
        return [
            'primary_contact' => ['nullable', 'array:name,designation,department,email,phone,whatsapp,is_billing_contact,receives_notifications,notes'],
            'primary_contact.name' => ['required_with:primary_contact.email,primary_contact.phone,primary_contact.designation', 'nullable', 'string', 'max:150'],
            'primary_contact.designation' => ['nullable', 'string', 'max:96'],
            'primary_contact.department' => ['nullable', 'string', 'max:96'],
            'primary_contact.email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'primary_contact.phone' => $this->phoneRules(),
            'primary_contact.whatsapp' => $this->phoneRules(),
            'primary_contact.is_billing_contact' => ['nullable', 'boolean'],
            'primary_contact.receives_notifications' => ['nullable', 'boolean'],
            'primary_contact.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
