<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;

/**
 * Enabling the client portal (phase-05 §6.7 `enablePortal()`).
 *
 * Exactly one binding is created: the client's primary login (`clientContactId` null) or an additional login
 * for one of its contacts. `existingUserId` links an account that already exists instead of creating one; no
 * password is ever part of this payload — the user sets it through the invitation link.
 */
final readonly class PortalInviteData
{
    use ReadsInput;

    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?int $clientContactId = null,
        public ?int $existingUserId = null,
        public bool $sendInvitation = true,
    ) {}

    /**
     * Keys: `name`, `email`, `client_contact_id`, `existing_user_id`, `send_invitation`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $email = self::str($data, 'email', 150);

        return new self(
            name: self::str($data, 'name', 150),
            email: $email === null ? null : mb_strtolower($email),
            clientContactId: self::int($data, 'client_contact_id'),
            existingUserId: self::int($data, 'existing_user_id'),
            sendInvitation: self::bool($data, 'send_invitation', true),
        );
    }
}
