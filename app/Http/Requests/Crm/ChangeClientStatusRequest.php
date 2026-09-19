<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Enums\ClientStatus;
use App\Models\Crm\Client;
use Illuminate\Validation\Rule;

/**
 * Change a client's status — `admin.clients.status`, `can:changeStatus,client` (phase-05 §6.7 `changeStatus()`).
 *
 * The reason is mandatory for `suspended` and `closed`. A status whose `canUsePortal()` is false revokes the client's
 * portal sessions in the service and `EnsureClientContext` refuses the next panel request (test 63).
 */
final class ChangeClientStatusRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client && $this->actorCan('changeStatus', $client);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(ClientStatus::class)],
            'reason' => [
                'required_if:status,'.ClientStatus::Suspended->value.','.ClientStatus::Closed->value,
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'Say why the client is being suspended or closed.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['status', 'reason']);
    }

    public function newStatus(): ClientStatus
    {
        return ClientStatus::from((string) $this->validated('status'));
    }
}
