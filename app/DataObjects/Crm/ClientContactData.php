<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;

/**
 * A named contact of a client (phase-05 §2.8, `ClientContactService`).
 *
 * `portal_access` and `user_id` are **not** here: a contact reaches the portal only through
 * `ClientService::enablePortal()`, which checks both UNIQUE bindings first.
 */
final readonly class ClientContactData
{
    use ReadsInput;

    public function __construct(
        public string $name,
        public ?string $designation = null,
        public ?string $department = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $whatsapp = null,
        public bool $isPrimary = false,
        public bool $isBillingContact = false,
        public bool $receivesNotifications = true,
        public ?string $notes = null,
    ) {}

    /**
     * Keys: `name`, `designation`, `department`, `email`, `phone`, `whatsapp`, `is_primary`,
     * `is_billing_contact`, `receives_notifications`, `notes`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) self::str($data, 'name', 150),
            designation: self::str($data, 'designation', 96),
            department: self::str($data, 'department', 96),
            email: self::str($data, 'email', 150),
            phone: self::str($data, 'phone', 32),
            whatsapp: self::str($data, 'whatsapp', 32),
            isPrimary: self::bool($data, 'is_primary'),
            isBillingContact: self::bool($data, 'is_billing_contact'),
            receivesNotifications: self::bool($data, 'receives_notifications', true),
            notes: self::str($data, 'notes', 255),
        );
    }

    /**
     * The nested primary-contact payload of a client form, or null when no name was given.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromNested(array $data, string $key): ?self
    {
        $nested = $data[$key] ?? null;

        if (! is_array($nested) || self::str($nested, 'name') === null) {
            return null;
        }

        return self::fromArray($nested);
    }
}
