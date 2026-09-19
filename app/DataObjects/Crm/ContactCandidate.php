<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;

/**
 * The raw contact fields a duplicate check compares (phase-05 §6.2 `LeadDuplicateDetector::check()`).
 *
 * Values arrive as typed; `LeadDuplicateDetector` normalises them through `ContactNormalizer` before any query.
 */
final readonly class ContactCandidate
{
    use ReadsInput;

    public function __construct(
        public ?string $phone = null,
        public ?string $whatsapp = null,
        public ?string $email = null,
        public ?string $countryCode = null,
    ) {}

    /**
     * Keys: `phone`, `whatsapp`, `email`, `country_code` — the duplicate-check endpoint's payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $country = self::str($data, 'country_code', 2);

        return new self(
            phone: self::str($data, 'phone', 32),
            whatsapp: self::str($data, 'whatsapp', 32),
            email: self::str($data, 'email', 150),
            countryCode: $country === null ? null : strtoupper($country),
        );
    }

    public function isEmpty(): bool
    {
        return $this->phone === null && $this->whatsapp === null && $this->email === null;
    }
}
