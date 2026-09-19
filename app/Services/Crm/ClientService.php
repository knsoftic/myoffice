<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Contracts\Crm\ClientFinancialsProvider;
use App\Contracts\Crm\ClientReferenceGuard;
use App\DataObjects\Crm\ClientContactData;
use App\DataObjects\Crm\ClientData;
use App\DataObjects\Crm\ClientFinancialSummary;
use App\DataObjects\Crm\PortalInviteData;
use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\PanelType;
use App\Events\Crm\ClientCreated;
use App\Events\Crm\ClientPortalDisabled;
use App\Events\Crm\ClientPortalEnabled;
use App\Events\Crm\ClientStatusChanged;
use App\Events\Crm\ClientUpdated;
use App\Jobs\Crm\SendClientPortalInvitation;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Core\UserService;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Services\Finance\DocumentNumberService;
use App\Support\ImageSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The client master (phase-05 §2.7, §6.7). Every public write is one `DB::transaction()`; events dispatch after
 * commit.
 *
 *   · **`client_code`** — `crm.client_code_prefix` + counter through `DocumentNumberService` (pad from
 *     `crm.number_padding`), with the single 1062 retry on `uq_clients_code`; immutable afterwards (the model
 *     hook throws) and never reused, even after a soft delete (tests 4-6).
 *   · **`update()`** never writes `client_code`, `user_id`, `status` or `portal_enabled`, and every change —
 *     a tax number included — is one audit row with old and new values (test 86).
 *   · **Portal** — `enablePortal()` binds exactly one login (the client or one contact), assigns the client-panel
 *     role, forces a password change and queues a password-set invitation; it **never sets or mails a password**
 *     the person could read (test 62). It refuses a user already bound to another client or contact, and the two
 *     UNIQUE indexes back that refusal (test 61). A status that forbids the portal, or `disablePortal()`, ends the
 *     sessions immediately without touching the binding (test 63).
 *   · **Money** — `financialSummary()` sums nothing itself: it asks the owning phases' tagged read models and
 *     returns an explicit `unavailable` snapshot while none is bound (test 59).
 */
final class ClientService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    private const MODULE = 'clients';

    /** Columns `update()` must never write. `logo_path` has its own upload method, so a typed path never reaches it. */
    private const PROTECTED_COLUMNS = ['client_code', 'user_id', 'status', 'portal_enabled', 'logo_path'];

    /** Directory on the `public` disk holding client logos (§2.7: a logo is not confidential). */
    private const LOGO_DIRECTORY = 'clients/logos';

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ClientContactService $contacts,
        private readonly ClientPortalAccess $portalAccess,
        private readonly UserService $users,
        private readonly Container $container,
    ) {}

    public function create(ClientData $data): Client
    {
        $actorId = $this->actorId();

        return DB::transaction(function () use ($data, $actorId): Client {
            $now = CarbonImmutable::now();
            $client = new Client;

            $client->forceFill($this->attributes($data, null));
            $client->forceFill([
                'client_type' => $data->clientType ?? ClientType::from('company'),
                'status' => ClientStatus::from('active'),
                'status_changed_at' => $now,
                'portal_enabled' => false,
                'lead_id' => $data->leadId,
                'referral_code_captured' => $data->referralCode === null ? null : mb_substr($data->referralCode, 0, 32),
            ]);

            $this->numbers->assign(
                'crm.client_code_prefix',
                'crm.client_code_next_number',
                $this->crmPad(),
                static function (string $code) use ($client): bool {
                    $client->setAttribute('client_code', $code);

                    return $client->save();
                },
                'uq_clients_code',
            );

            if ($data->primaryContact instanceof ClientContactData) {
                $this->contacts->create($client, new ClientContactData(
                    name: $data->primaryContact->name,
                    designation: $data->primaryContact->designation,
                    department: $data->primaryContact->department,
                    email: $data->primaryContact->email,
                    phone: $data->primaryContact->phone,
                    whatsapp: $data->primaryContact->whatsapp,
                    isPrimary: true,
                    isBillingContact: $data->primaryContact->isBillingContact,
                    receivesNotifications: $data->primaryContact->receivesNotifications,
                    notes: $data->primaryContact->notes,
                ));
            }

            event(new ClientCreated($client, $actorId));

            return $client;
        });
    }

    public function update(Client $client, ClientData $data): Client
    {
        return DB::transaction(function () use ($client, $data): Client {
            $locked = $this->lock($client);

            $attributes = $this->attributes($data, $locked);
            $keys = array_keys($attributes);
            $before = $this->snapshot($locked, $keys);

            $locked->forceFill($attributes);

            // billing_same_as_address nulls billing_address on save (the model hook), so the two cannot disagree.
            if ((bool) $locked->getAttribute('billing_same_as_address')) {
                $locked->setAttribute('billing_address', null);
                $keys[] = 'billing_address';
                $before['billing_address'] ??= $locked->getOriginal('billing_address');
            }

            $changes = $this->changes($before, $this->snapshot($locked, array_values(array_unique($keys))));

            if ($changes['attributes'] === []) {
                return $this->refreshInto($client, $locked);
            }

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit($locked, 'Client updated', $changes, self::MODULE);

            event(new ClientUpdated($locked, $changes));

            return $this->refreshInto($client, $locked);
        });
    }

    public function changeStatus(Client $client, ClientStatus $status, ?string $reason): Client
    {
        $reason = $this->cleanText($reason, 255);

        if (in_array($status->value, ['suspended', 'closed'], true) && $reason === null) {
            throw CrmRuleException::reasonRequired('reason', sprintf('Say why the client is being marked %s.', mb_strtolower($status->label())));
        }

        return DB::transaction(function () use ($client, $status, $reason): Client {
            $locked = $this->lock($client);
            $from = $this->statusOf($locked);

            if ($from === $status) {
                return $this->refreshInto($client, $locked);
            }

            $before = $this->snapshot($locked, ['status', 'status_reason']);

            $locked->forceFill([
                'status' => $status,
                'status_reason' => $status->canUsePortal() ? null : $reason,
                'status_changed_at' => CarbonImmutable::now(),
            ]);

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit(
                $locked,
                sprintf('Client status changed from %s to %s', $from->label(), $status->label()),
                $this->changes($before, $this->snapshot($locked, ['status', 'status_reason'])),
                self::MODULE,
                $reason,
            );

            if (! $status->canUsePortal()) {
                $this->portalAccess->revokeFor($locked);
            }

            event(new ClientStatusChanged($locked, $from, $status, $reason));

            return $this->refreshInto($client, $locked);
        });
    }

    public function assignAccountManager(Client $client, ?User $manager, ?string $reason): Client
    {
        $reason = $this->cleanText($reason, 500);

        return DB::transaction(function () use ($client, $manager, $reason): Client {
            $locked = $this->lock($client);
            $before = $this->snapshot($locked, ['account_manager_id']);

            $locked->forceFill(['account_manager_id' => $manager?->getKey()]);

            if (! $locked->isDirty('account_manager_id')) {
                return $this->refreshInto($client, $locked);
            }

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit(
                $locked,
                $manager === null ? 'Client account manager removed' : 'Client account manager assigned',
                $this->changes($before, $this->snapshot($locked, ['account_manager_id'])),
                self::MODULE,
                $reason,
            );

            return $this->refreshInto($client, $locked);
        });
    }

    /**
     * Replace (or, with `$remove`, clear) the client's logo — from the staff form or the portal profile.
     *
     * A logo is not confidential, so it lives on the `public` disk (§2.7). Only re-encoded pixels are written
     * (`ImageSanitizer::reencode()`): an image header followed by a script passes every validation rule, so the uploaded
     * bytes themselves are never stored. The previous file is removed after the new path has committed.
     */
    public function updateLogo(Client $client, ?UploadedFile $logo, bool $remove = false): Client
    {
        if (! $logo instanceof UploadedFile && ! $remove) {
            return $client;
        }

        $newPath = null;

        if ($logo instanceof UploadedFile) {
            $clean = ImageSanitizer::reencode($logo, 'logo');
            $newPath = sprintf('%s/%s.%s', self::LOGO_DIRECTORY, Str::random(40), $clean['extension']);

            if (! Storage::disk('public')->put($newPath, $clean['bytes'])) {
                throw CrmRuleException::refuse('logo', 'The logo could not be saved. Try again.');
            }
        }

        try {
            return DB::transaction(function () use ($client, $newPath): Client {
                $locked = $this->lock($client);
                $previous = $this->nullable($locked->getAttribute('logo_path'));

                $locked->forceFill(['logo_path' => $newPath]);

                if (! $locked->isDirty('logo_path')) {
                    return $this->refreshInto($client, $locked);
                }

                $this->withoutModelLogging(static fn (): bool => $locked->save());

                $this->audit($locked, $newPath === null ? 'Client logo removed' : 'Client logo updated', [
                    'old' => ['logo_path' => $previous],
                    'attributes' => ['logo_path' => $newPath],
                ], self::MODULE);

                if ($previous !== null && ! str_starts_with($previous, 'http') && ! str_starts_with($previous, '/')) {
                    DB::afterCommit(static function () use ($previous): void {
                        try {
                            Storage::disk('public')->delete($previous);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    });
                }

                return $this->refreshInto($client, $locked);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }
    }

    /**
     * Bind a portal login and queue its invitation. Idempotent: enabling a client whose login is already bound
     * re-enables the portal (and re-sends the invitation when asked) without creating a second user.
     */
    public function enablePortal(Client $client, PortalInviteData $data): User
    {
        $actor = $this->actor();

        if ($actor instanceof User && ! ($actor->can('clients.change_status') && $actor->can('users.create'))) {
            throw CrmRuleException::refuse('portal', 'Enabling the client portal needs permission to change client status and to create users.');
        }

        try {
            return DB::transaction(fn (): User => $this->bindPortalUser($client, $data)[0]);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'uq_clients_user') || str_contains($exception->getMessage(), 'uq_cc_user')) {
                throw CrmRuleException::portalUserTaken();
            }

            throw $exception;
        }
    }

    public function disablePortal(Client $client, string $reason): void
    {
        $reason = $this->cleanText($reason, 255);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired('reason', 'Say why portal access is being switched off.');
        }

        DB::transaction(function () use ($client, $reason): void {
            $locked = $this->lock($client);

            $this->disablePortalLocked($locked, $reason);

            $this->refreshInto($client, $locked);
        });
    }

    /**
     * Invoiced / paid / outstanding / overdue from the owning phases' read models only.
     *
     * @param  User|null  $viewer  null = the signed-in user; without `clients.view_financial` every figure is withheld
     */
    public function financialSummary(Client $client, ?User $viewer = null): ClientFinancialSummary
    {
        $viewer ??= $this->actor();

        if ($viewer instanceof User && ! $viewer->can('clients.view_financial')) {
            return ClientFinancialSummary::withheld();
        }

        $figures = [];
        $answered = false;

        foreach ($this->tagged(ClientFinancialsProvider::TAG) as $provider) {
            if (! $provider instanceof ClientFinancialsProvider) {
                continue;
            }

            try {
                if (! $provider->isAvailable()) {
                    continue;
                }

                $figures = [...$figures, ...$provider->figuresFor($client)];
                $answered = true;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $answered ? ClientFinancialSummary::fromFigures($figures) : ClientFinancialSummary::unavailable();
    }

    /**
     * What still references this client, as sentences — empty when a soft delete is allowed.
     *
     * @return list<string>
     */
    public function blockingReferences(Client $client): array
    {
        $out = [];

        foreach ($this->tagged(ClientReferenceGuard::TAG) as $guard) {
            if (! $guard instanceof ClientReferenceGuard) {
                continue;
            }

            $sentence = $guard->blockingReferences($client);

            if ($sentence !== null && trim($sentence) !== '') {
                $out[] = trim($sentence);
            }
        }

        return $out;
    }

    public function delete(Client $client, string $reason): void
    {
        $reason = $this->cleanText($reason, 500);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired();
        }

        DB::transaction(function () use ($client, $reason): void {
            $locked = $this->lock($client);
            $blocking = $this->blockingReferences($locked);

            if ($blocking !== []) {
                throw CrmRuleException::refuse('client', sprintf('This client cannot be deleted while it has %s.', implode(', ', $blocking)));
            }

            if ((bool) $locked->getAttribute('portal_enabled')) {
                $this->disablePortalLocked($locked, 'Client deleted: '.$reason);
            }

            $locked->withReason($reason)->delete();

            $this->refreshInto($client, $locked);
        });
    }

    /**
     * Restore a soft-deleted client. The portal stays off until a person switches it back on.
     */
    public function restore(Client $client): Client
    {
        return DB::transaction(function () use ($client): Client {
            $locked = $this->lock($client);

            if ($locked->getAttribute('deleted_at') !== null) {
                $locked->restore();
            }

            return $this->refreshInto($client, $locked);
        });
    }

    /**
     * @return array{0: User, 1: ClientContact|null, 2: bool}
     */
    private function bindPortalUser(Client $client, PortalInviteData $data): array
    {
        $locked = $this->lock($client);
        $contact = null;

        if ($data->clientContactId !== null) {
            /** @var ClientContact|null $contact */
            $contact = ClientContact::query()
                ->where('client_id', $locked->getKey())
                ->whereKey($data->clientContactId)
                ->lockForUpdate()
                ->first();

            if (! $contact instanceof ClientContact) {
                throw CrmRuleException::refuse('client_contact_id', 'Choose one of this client\'s contacts.');
            }
        }

        $boundId = $contact instanceof ClientContact ? $contact->getAttribute('user_id') : $locked->getAttribute('user_id');
        $created = false;

        if ($boundId !== null) {
            $user = User::query()->withTrashed()->whereKey((int) $boundId)->first();

            if (! $user instanceof User) {
                throw CrmRuleException::refuse('email', 'The login bound to this client no longer exists.');
            }
        } else {
            $user = $this->resolveOrCreateUser($locked, $contact, $data);
            $created = $user->wasRecentlyCreated;
        }

        $this->assertBindable($user, $locked, $contact);
        $this->ensurePortalRole($user);

        if (! (bool) $user->getAttribute('must_change_password') && ($created || $user->getAttribute('last_login_at') === null)) {
            $user->forceFill(['must_change_password' => true])->save();
        }

        $now = CarbonImmutable::now();
        $before = $this->snapshot($locked, ['user_id', 'portal_enabled', 'portal_invited_at']);

        if ($contact instanceof ClientContact) {
            $contact->forceFill(['user_id' => (int) $user->getKey(), 'portal_access' => true]);
            $contact->save();
        } else {
            $locked->forceFill(['user_id' => (int) $user->getKey()]);
        }

        $locked->forceFill([
            'portal_enabled' => true,
            'portal_invited_at' => $data->sendInvitation ? $now : $locked->getAttribute('portal_invited_at'),
        ]);

        if ($locked->isDirty()) {
            $this->withoutModelLogging(static fn (): bool => $locked->save());
        }

        $this->audit($locked, 'Client portal enabled', [
            ...$this->changes($before, $this->snapshot($locked, ['user_id', 'portal_enabled', 'portal_invited_at'])),
            'portal_user_id' => (int) $user->getKey(),
            'client_contact_id' => $contact?->getKey(),
            'user_created' => $created,
        ], self::MODULE);

        event(new ClientPortalEnabled($locked, $user, $contact, $created));

        if ($data->sendInvitation) {
            SendClientPortalInvitation::dispatch((int) $user->getKey(), (int) $locked->getKey());
        }

        $this->refreshInto($client, $locked);

        return [$user, $contact, $created];
    }

    private function resolveOrCreateUser(Client $client, ?ClientContact $contact, PortalInviteData $data): User
    {
        if ($data->existingUserId !== null) {
            $user = User::query()->whereKey($data->existingUserId)->first();

            if (! $user instanceof User) {
                throw CrmRuleException::refuse('existing_user_id', 'Choose an existing account.');
            }

            return $user;
        }

        $email = $data->email
            ?? $this->nullable($contact?->getAttribute('email'))
            ?? $this->nullable($client->getAttribute('email'));

        if ($email === null) {
            throw CrmRuleException::refuse('email', 'An email address is needed to invite this person to the portal.');
        }

        $email = mb_strtolower($email);

        $existing = User::query()->withTrashed()->where('email', $email)->first();

        if ($existing instanceof User) {
            if ($existing->trashed()) {
                throw CrmRuleException::refuse('email', 'That email belongs to a deleted account. Restore the account or use another address.');
            }

            return $existing;
        }

        $name = $data->name
            ?? $this->nullable($contact?->getAttribute('name'))
            ?? $this->nullable($client->getAttribute('name'))
            ?? $email;

        $role = $this->portalRole();

        // The password is random, never shown, never mailed and never logged: the person chooses their own through
        // the invitation's password-set link, and `must_change_password` is set on top of that.
        return $this->users->create([
            'name' => mb_substr($name, 0, 150),
            'email' => $email,
            'phone' => $this->nullable($contact?->getAttribute('phone')) ?? $this->nullable($client->getAttribute('phone')),
            'whatsapp' => $this->nullable($contact?->getAttribute('whatsapp')) ?? $this->nullable($client->getAttribute('whatsapp')),
            'password' => Str::password(48),
            'must_change_password' => true,
            'status' => 'active',
            'roles' => [(int) $role->getKey()],
        ]);
    }

    private function assertBindable(User $user, Client $client, ?ClientContact $contact): void
    {
        $boundClient = Client::query()
            ->withTrashed()
            ->where('user_id', $user->getKey())
            ->whereKeyNot($contact instanceof ClientContact ? 0 : $client->getKey())
            ->exists();

        $boundContact = ClientContact::query()
            ->withTrashed()
            ->where('user_id', $user->getKey())
            ->when($contact instanceof ClientContact, static fn ($query) => $query->whereKeyNot($contact?->getKey()))
            ->exists();

        if ($boundClient || $boundContact) {
            throw CrmRuleException::portalUserTaken();
        }

        // A staff login is never turned into a client login: the two panels would share one account.
        try {
            if ($user->canAccessPanel(PanelType::Admin)) {
                throw CrmRuleException::refuse('email', 'That email belongs to a staff account. Use the client\'s own address.');
            }
        } catch (CrmRuleException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Role tables unreadable: the unique bindings above still hold.
        }
    }

    private function ensurePortalRole(User $user): void
    {
        $role = $this->portalRole();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);

            $this->audit($user, 'Client portal role granted', [
                'old' => ['roles' => []],
                'attributes' => ['roles' => [$role->getAttribute('name')]],
            ], 'users');
        }
    }

    /**
     * The client panel's default role — resolved from the database by panel, never by a hard-coded name.
     */
    private function portalRole(): Role
    {
        $role = Role::query()
            ->where('panel', PanelType::Client->value)
            ->orderByDesc('is_default')
            ->orderBy('level')
            ->orderBy('id')
            ->first();

        if (! $role instanceof Role) {
            throw CrmRuleException::refuse('portal', 'No client-panel role exists yet. Run the role seeder first.');
        }

        return $role;
    }

    private function disablePortalLocked(Client $locked, string $reason): void
    {
        $before = $this->snapshot($locked, ['portal_enabled']);

        $locked->forceFill(['portal_enabled' => false]);

        if ($locked->isDirty('portal_enabled')) {
            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit($locked, 'Client portal disabled', $this->changes($before, $this->snapshot($locked, ['portal_enabled'])), self::MODULE, $reason);
        }

        $this->portalAccess->revokeFor($locked);

        event(new ClientPortalDisabled($locked, $reason));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ClientData $data, ?Client $existing): array
    {
        $attributes = [];

        foreach (ClientData::FIELDS as $column => $property) {
            if (in_array($column, self::PROTECTED_COLUMNS, true)) {
                continue;
            }

            if ($existing !== null && ! $data->supplies($column)) {
                continue;
            }

            $value = $data->{$property};

            if ($column === 'client_type' && $value === null) {
                continue;
            }

            if ($column === 'source' && $value === null && $existing !== null) {
                continue;
            }

            $attributes[$column] = $value;
        }

        if ($existing === null) {
            $attributes['name'] = $data->name;
        }

        return $attributes;
    }

    /**
     * @return iterable<mixed>
     */
    private function tagged(string $tag): iterable
    {
        try {
            return $this->container->tagged($tag);
        } catch (Throwable) {
            return [];
        }
    }

    private function lock(Client $client): Client
    {
        /** @var Client $locked */
        $locked = Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function refreshInto(Client $target, Client $source): Client
    {
        if ($target !== $source) {
            $target->setRawAttributes($source->getAttributes(), true);
        }

        return $target;
    }

    private function statusOf(Client $client): ClientStatus
    {
        $status = $client->getAttribute('status');

        return $status instanceof ClientStatus ? $status : ClientStatus::from((string) $status);
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
