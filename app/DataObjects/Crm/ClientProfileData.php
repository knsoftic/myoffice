<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;

/**
 * What a client may change about itself from the portal (phase-05 §6.9 `updateProfile()`, test 81).
 *
 * A strict whitelist. `fromArray()` reads only these keys, so `client_code`, `status`, `portal_enabled`,
 * `account_manager_id`, tax numbers, `payment_terms_days`, `currency` and `notes` cannot travel through it
 * even if a crafted request carries them. `contact*` fields apply to the signed-in contact's own row.
 */
final readonly class ClientProfileData
{
    use ReadsInput;

    /** @var list<string> */
    public const CLIENT_FIELDS = ['name', 'phone', 'whatsapp', 'website', 'address', 'city', 'postal_code', 'about', 'logo_path'];

    /** @var list<string> */
    public const CONTACT_FIELDS = ['contact_name', 'contact_designation', 'contact_phone'];

    /**
     * @param  array<string, string|null>  $client  CLIENT_FIELDS key => value, supplied keys only
     * @param  array<string, string|null>  $contact  CONTACT_FIELDS key => value, supplied keys only
     */
    public function __construct(
        public array $client = [],
        public array $contact = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $limits = ['name' => 150, 'phone' => 32, 'whatsapp' => 32, 'website' => 255, 'address' => 255, 'city' => 96, 'postal_code' => 24, 'about' => null, 'logo_path' => 255];
        $client = [];

        foreach (self::CLIENT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $client[$field] = self::str($data, $field, $limits[$field]);
            }
        }

        $contactLimits = ['contact_name' => 150, 'contact_designation' => 96, 'contact_phone' => 32];
        $contact = [];

        foreach (self::CONTACT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $contact[$field] = self::str($data, $field, $contactLimits[$field]);
            }
        }

        return new self($client, $contact);
    }
}
