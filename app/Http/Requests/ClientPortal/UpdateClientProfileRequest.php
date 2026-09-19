<?php

declare(strict_types=1);

namespace App\Http\Requests\ClientPortal;

use App\DataObjects\Crm\ClientProfileData;
use App\Http\Requests\Crm\CrmFormRequest;
use App\Support\ClientContext;
use Throwable;

/**
 * A client edits its own profile — `client.profile.update`, `can:client_portal.profile` (phase-05 §6.9
 * `ClientPortalService::updateProfile()`, §8.10 Profile, test 81).
 *
 * **A strict whitelist.** Only `name`, `phone`, `whatsapp`, `website`, `address`, `city`, `postal_code`, `about`,
 * and — when the signed-in user is a contact rather than the primary login — the contact's own
 * `contact_name`, `contact_designation`, `contact_phone`. `client_code`, `status`, `portal_enabled`,
 * `account_manager_id`, every tax field, `payment_terms_days`, `currency`, `notes` and anything else a client
 * posts are **dropped**: they are not in the rules, so they never reach `validated()` and the DB value is unchanged.
 *
 * The client is never taken from the request: `ClientContext` resolves it from the session.
 */
final class UpdateClientProfileRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        return $this->actorCan('client_portal.profile');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => array_merge(['sometimes'], $this->phoneRules()),
            'whatsapp' => array_merge(['sometimes'], $this->phoneRules()),
            'website' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:96'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'about' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];

        if ($this->signedInAsContact()) {
            $rules['contact_name'] = ['sometimes', 'required', 'string', 'max:150'];
            $rules['contact_designation'] = ['sometimes', 'nullable', 'string', 'max:96'];
            $rules['contact_phone'] = array_merge(['sometimes'], $this->phoneRules());
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
            'name', 'phone', 'whatsapp', 'website', 'address', 'city', 'postal_code', 'about',
            'contact_name', 'contact_designation', 'contact_phone',
        ]);
    }

    public function toData(): ClientProfileData
    {
        return ClientProfileData::fromArray($this->validated());
    }

    private function signedInAsContact(): bool
    {
        try {
            return app(ClientContext::class)->isContact();
        } catch (Throwable) {
            return false;
        }
    }
}
