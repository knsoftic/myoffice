<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ClientStatus;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\User;
use App\Support\Exceptions\NoClientContextException;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Throwable;

/**
 * Who the signed-in portal user is a client of — resolved once per request (phase-05 §6.9, §9.2, CLAUDE.md
 * rule 10).
 *
 * Resolution, in this order:
 *   1. `clients.user_id = auth()->id()` — the primary login (D2). When such a binding exists it is the **only**
 *      candidate ("primary binding wins"), usable or not, so a revoked company can never fall through to a
 *      second binding;
 *   2. otherwise `client_contacts.user_id = auth()->id() AND portal_access = 1`.
 * The client must have `portal_enabled = 1` and a status whose `canUsePortal()` is true.
 *
 * **Every panel query takes its client id from here and never from the request.** `EnsureClientContext`
 * (`client.context`) calls `inspect()` on every `/client` request, so a disabled portal, a suspended client or a
 * revoked contact is refused on the very next request, not at the next login (test 63).
 *
 * `#[Scoped]`: one instance per request (and per queued job), never shared across users.
 */
#[Scoped]
final class ClientContext
{
    public const REASON_NO_USER = 'no_user';

    public const REASON_NO_CLIENT = 'no_client';

    public const REASON_PORTAL_DISABLED = 'portal_disabled';

    public const REASON_STATUS = 'status_forbids_portal';

    public const REASON_CONTACT_REVOKED = 'contact_access_revoked';

    private bool $resolved = false;

    private ?Client $client = null;

    private ?ClientContact $contact = null;

    private ?string $failure = null;

    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function client(): Client
    {
        $this->resolve();

        if (! $this->client instanceof Client) {
            throw new NoClientContextException($this->failure ?? self::REASON_NO_CLIENT, $this->message());
        }

        return $this->client;
    }

    public function clientId(): int
    {
        return (int) $this->client()->getKey();
    }

    /**
     * The contact row the user signed in as, or null for the client's primary login.
     */
    public function contact(): ?ClientContact
    {
        $this->client();

        return $this->contact;
    }

    public function isContact(): bool
    {
        return $this->contact() instanceof ClientContact;
    }

    /**
     * Null when a usable client resolves, else the `REASON_*` code explaining why not.
     */
    public function inspect(): ?string
    {
        $this->resolve();

        return $this->client instanceof Client ? null : ($this->failure ?? self::REASON_NO_CLIENT);
    }

    public function has(): bool
    {
        return $this->inspect() === null;
    }

    /**
     * The explanation shown on the 403 page.
     */
    public function message(): string
    {
        return match ($this->failure) {
            self::REASON_PORTAL_DISABLED => 'Portal access for your company has been switched off. Please contact your account manager.',
            self::REASON_STATUS => 'Your company account is not active at the moment, so the client portal is unavailable. Please contact your account manager.',
            self::REASON_CONTACT_REVOKED => 'Your portal access has been removed. Please contact your company administrator or your account manager.',
            default => 'This login is not linked to a client account.',
        };
    }

    /**
     * Forget the resolution — after a change made in the same request (tests, profile edits).
     */
    public function forget(): void
    {
        $this->resolved = false;
        $this->client = null;
        $this->contact = null;
        $this->failure = null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        try {
            $user = $this->auth->guard()->user();
        } catch (Throwable) {
            $user = null;
        }

        if (! $user instanceof User) {
            $this->failure = self::REASON_NO_USER;

            return;
        }

        $userId = (int) $user->getKey();

        $primary = Client::query()->where('user_id', $userId)->first();

        if ($primary instanceof Client) {
            $this->accept($primary, null);

            return;
        }

        $contact = ClientContact::query()->where('user_id', $userId)->first();

        if (! $contact instanceof ClientContact) {
            $this->failure = self::REASON_NO_CLIENT;

            return;
        }

        if (! (bool) $contact->getAttribute('portal_access')) {
            $this->failure = self::REASON_CONTACT_REVOKED;

            return;
        }

        $client = Client::query()->whereKey($contact->getAttribute('client_id'))->first();

        if (! $client instanceof Client) {
            $this->failure = self::REASON_NO_CLIENT;

            return;
        }

        $this->accept($client, $contact);
    }

    private function accept(Client $client, ?ClientContact $contact): void
    {
        if (! (bool) $client->getAttribute('portal_enabled')) {
            $this->failure = self::REASON_PORTAL_DISABLED;

            return;
        }

        $status = $client->getAttribute('status');
        $status = $status instanceof ClientStatus ? $status : ClientStatus::tryFrom((string) $status);

        if (! $status instanceof ClientStatus || ! $status->canUsePortal()) {
            $this->failure = self::REASON_STATUS;

            return;
        }

        $this->client = $client;
        $this->contact = $contact;
        $this->failure = null;
    }
}
