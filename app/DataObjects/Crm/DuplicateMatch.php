<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Enums\LeadDuplicateMatchType;
use Carbon\CarbonInterface;

/**
 * One hit of a duplicate check (phase-05 §6.2).
 *
 * **Isolation-safe by construction (R-4, test 23).** A match on a row the actor may not open is built with
 * `restricted()`: it carries the match type, the record type and "owned by another user" — no id, no name, no
 * contact, no status, no owner, no link. `toArray()` emits exactly those keys for a restricted match, so the
 * JSON of the duplicate-check endpoint cannot leak another rep's pipeline.
 */
final readonly class DuplicateMatch
{
    public const RECORD_LEAD = 'lead';

    public const RECORD_CLIENT = 'client';

    public const RECORD_CLIENT_CONTACT = 'client_contact';

    public function __construct(
        public LeadDuplicateMatchType $matchType,
        public string $recordType,
        public bool $restricted,
        public ?int $id = null,
        public ?string $reference = null,
        public ?string $name = null,
        public ?string $company = null,
        public ?string $status = null,
        public ?string $statusLabel = null,
        public ?string $ownerName = null,
        public ?CarbonInterface $lastActivityAt = null,
        public bool $isTrashed = false,
        public ?string $url = null,
        public ?CarbonInterface $sortAt = null,
    ) {}

    public static function restricted(LeadDuplicateMatchType $matchType, string $recordType, ?CarbonInterface $sortAt = null): self
    {
        return new self(matchType: $matchType, recordType: $recordType, restricted: true, sortAt: $sortAt);
    }

    public function isExact(): bool
    {
        return $this->matchType->isExact();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->restricted) {
            return [
                'match_type' => $this->matchType->value,
                'match_label' => $this->matchType->label(),
                'record_type' => $this->recordType,
                'restricted' => true,
                'message' => 'Owned by another user',
            ];
        }

        return [
            'match_type' => $this->matchType->value,
            'match_label' => $this->matchType->label(),
            'record_type' => $this->recordType,
            'restricted' => false,
            'is_exact' => $this->isExact(),
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'company' => $this->company,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'owner_name' => $this->ownerName,
            'last_activity_at' => $this->lastActivityAt?->toIso8601String(),
            'is_trashed' => $this->isTrashed,
            'url' => $this->url,
        ];
    }
}
