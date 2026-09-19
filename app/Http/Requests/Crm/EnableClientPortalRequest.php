<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\PortalInviteData;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Give a client (or one of its contacts) a portal login — `admin.clients.portal.enable`, `can:managePortal,client`
 * (phase-05 §6.7 `enablePortal()`, test 61, 62).
 *
 * Requires `clients.change_status` **and** `users.create` (§6.7): the policy answers the first, this request
 * repeats both. The login is either an existing user (`existing_user_id`) or a new one built from `email` + `name`;
 * `client_contact_id`, when given, must be a contact **of this client**. A user already bound to another client or
 * contact is refused here with a readable message; the two UNIQUE indexes and the service's own check back it.
 * No password is ever accepted — the service sends a password-set invitation.
 */
final class EnableClientPortalRequest extends CrmFormRequest
{
    public function authorize(): bool
    {
        $client = $this->boundModel('client', Client::class);

        return $client instanceof Client
            && $this->actorCan('managePortal', $client)
            && $this->actorCan('clients.change_status')
            && $this->actorCan('users.create');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $client = $this->boundModel('client', Client::class);

        return [
            'client_contact_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('client_contacts', 'id')
                    ->where('client_id', $client?->getKey() ?? 0)
                    ->whereNull('deleted_at'),
            ],
            'existing_user_id' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'email' => ['required_without:existing_user_id', 'nullable', 'string', 'email:rfc', 'max:150'],
            'name' => ['nullable', 'string', 'max:150'],
            'send_invitation' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $client = $this->boundModel('client', Client::class);
                $user = $this->targetUser();

                if (! $client instanceof Client || ! $user instanceof User) {
                    return;
                }

                $contactId = $this->input('client_contact_id');
                $contactId = is_numeric($contactId) ? (int) $contactId : null;

                // One user is one client (D2, §9.2): an invite for the client itself may re-use only this client's
                // own login; an invite for a contact may re-use only that contact's own login.
                $boundToClient = Client::query()
                    ->withTrashed()
                    ->where('user_id', $user->getKey())
                    ->when($contactId === null, static fn ($query) => $query->whereKeyNot($client->getKey()))
                    ->exists();

                $boundToContact = ClientContact::query()
                    ->withTrashed()
                    ->where('user_id', $user->getKey())
                    ->when($contactId !== null, static fn ($query) => $query->whereKeyNot($contactId))
                    ->exists();

                if ($boundToClient || $boundToContact) {
                    $validator->errors()->add(
                        $this->filled('existing_user_id') ? 'existing_user_id' : 'email',
                        'That account is already the portal login of another client or contact.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['client_contact_id', 'existing_user_id', 'email', 'name']);

        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower($email)]);
        }
    }

    public function toData(): PortalInviteData
    {
        return PortalInviteData::fromArray($this->validated());
    }

    /**
     * The user this invite would bind: the chosen existing user, or the account already registered under the
     * e-mail address (the service links rather than duplicates it).
     */
    private function targetUser(): ?User
    {
        $id = $this->input('existing_user_id');

        if (is_numeric($id)) {
            return User::query()->find((int) $id);
        }

        $email = $this->input('email');

        return is_string($email) && $email !== '' ? User::query()->where('email', $email)->first() : null;
    }
}
