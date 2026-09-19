<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\Crm\Client;
use App\Models\User;

/**
 * Set or clear a client's account manager — `admin.clients.account-manager`, `can:assign,client` (phase-05 §6.7
 * `assignAccountManager(Client, ?User, ?string $reason)`).
 *
 * `user_id` is present and nullable (null clears it); the manager must be an active user who can see clients.
 */
final class AssignAccountManagerRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client && $this->actorCan('assign', $client);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'present',
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('clients.view', 'Choose an active user who can work with clients.'),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['user_id', 'reason']);
    }

    public function manager(): ?User
    {
        $id = $this->validated('user_id');

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }
}
