<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\ClientData;
use App\Http\Requests\Crm\Concerns\ValidatesClientFields;
use App\Models\Crm\Client;
use Illuminate\Validation\Rule;

/**
 * Create a client — `admin.clients.store`, `can:clients.create` (phase-05 §2.7, §6.7 `create()`, §8.7).
 *
 * The §19 profile, address, tax details and commercial terms, an optional account manager (only
 * from someone holding `clients.assign`, and only an active user who can see clients) and an optional primary
 * contact created in the same transaction. The generated `client_code`, the portal columns and `status` are the
 * service's and are dropped if posted.
 *
 * `billing_same_as_address = true` nulls `billing_address` in the service (test 57); the percentages are refused
 * outside 0-100 (test 56). `logo_path` is not accepted: `ClientService` publishes no logo upload yet, and a path typed
 * by a person must never reach the column.
 */
class StoreClientRequest extends CrmFormRequest
{
    use ValidatesClientFields;

    public function authorize(): bool
    {
        return $this->actorCan('clients.create') && $this->actorCan('create', Client::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(
            $this->clientFieldRules(),
            [
                'account_manager_id' => [
                    Rule::prohibitedIf(fn (): bool => ! $this->actorCan('clients.assign')),
                    'nullable',
                    'integer',
                    'min:1',
                    $this->activeUserHolding('clients.view', 'Choose an active user who can work with clients.'),
                ],
                'referral_code' => ['nullable', 'string', 'max:32', 'regex:'.self::REFERRAL_CODE_PATTERN],
            ],
            $this->primaryContactRules(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tax_rate_override' => 'tax rate override',
            'withholding_tax_rate' => 'withholding tax rate',
            'account_manager_id' => 'account manager',
            'primary_contact.name' => 'contact name',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(array_merge($this->clientFieldNames(), ['account_manager_id', 'referral_code']));
    }

    public function toData(): ClientData
    {
        return ClientData::fromArray($this->validated());
    }
}
