<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\LeadDuplicateMatchType;

/**
 * The conversion wizard's submission (phase-05 §6.4 `convert()`).
 *
 *   · `existingClientId` — link to a client a human explicitly picked from the duplicate report; a client is
 *     never auto-matched silently. Null creates a new client from `client` (or the proposed field map).
 *   · `matchedBy` — which duplicate match led the human to that client, recorded on the conversion.
 *   · `promoteToWon` — "mark as won and convert" in one transaction; honoured only for `leads.change_status`.
 *   · `createProject` + `project` — the Phase 6 hand-off; honoured only when `ProjectCreator::isAvailable()`
 *     and the actor holds `projects.create`.
 */
final readonly class ConvertLeadData
{
    use ReadsInput;

    public function __construct(
        public ?int $existingClientId = null,
        public ?LeadDuplicateMatchType $matchedBy = null,
        public ?ClientData $client = null,
        public bool $promoteToWon = false,
        public ?string $promotionReason = null,
        public bool $createProject = false,
        public ?ProjectDraftData $project = null,
        public ?string $notes = null,
    ) {}

    /**
     * Keys: `existing_client_id`, `matched_by`, nested `client` (the client form), `promote_to_won`,
     * `promotion_reason`, `create_project`, nested `project` (`project_name`, …), `notes`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $client = is_array($data['client'] ?? null) && self::str($data['client'], 'name') !== null
            ? ClientData::fromArray($data['client'])
            : null;

        $createProject = self::bool($data, 'create_project');
        $project = $createProject && is_array($data['project'] ?? null)
            ? ProjectDraftData::fromArray($data['project'])
            : null;

        return new self(
            existingClientId: self::int($data, 'existing_client_id'),
            matchedBy: self::enum($data, 'matched_by', LeadDuplicateMatchType::class),
            client: $client,
            promoteToWon: self::bool($data, 'promote_to_won'),
            promotionReason: self::str($data, 'promotion_reason', 500),
            createProject: $createProject,
            project: $project,
            notes: self::str($data, 'notes', 255),
        );
    }
}
