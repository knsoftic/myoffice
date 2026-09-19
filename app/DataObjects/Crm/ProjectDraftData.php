<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;

/**
 * The project half of a lead conversion, handed to `ProjectCreator::createFromLead()` (phase-05 §6.4 step 8).
 *
 * `collaboratorId` and `referralCode` are filled by `LeadConversionService` from the lead's recorded
 * attribution — never from the request — so the project that will earn commission carries it (test 53).
 * `clientId` and `leadId` are likewise set by the service.
 */
final readonly class ProjectDraftData
{
    use ReadsInput;

    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?int $serviceId = null,
        public ?string $projectValue = null,
        public ?string $startDate = null,
        public ?string $deadline = null,
        public ?int $clientId = null,
        public ?int $leadId = null,
        public ?int $collaboratorId = null,
        public ?string $referralCode = null,
        public ?string $notes = null,
    ) {}

    /**
     * Keys: `project_name` (or `name`), `project_description`, `service_id`, `project_value`, `start_date`,
     * `deadline`, `project_notes`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) (self::str($data, 'project_name', 150) ?? self::str($data, 'name', 150) ?? ''),
            description: self::str($data, 'project_description'),
            serviceId: self::int($data, 'service_id'),
            projectValue: self::money($data, 'project_value'),
            startDate: self::date($data, 'start_date'),
            deadline: self::date($data, 'deadline'),
            notes: self::str($data, 'project_notes', 255) ?? self::str($data, 'notes', 255),
        );
    }

    /**
     * The same draft with the server-resolved links and attribution.
     */
    public function resolved(int $clientId, int $leadId, ?int $collaboratorId, ?string $referralCode): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            serviceId: $this->serviceId,
            projectValue: $this->projectValue,
            startDate: $this->startDate,
            deadline: $this->deadline,
            clientId: $clientId,
            leadId: $leadId,
            collaboratorId: $collaboratorId,
            referralCode: $referralCode,
            notes: $this->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'service_id' => $this->serviceId,
            'project_value' => $this->projectValue,
            'start_date' => $this->startDate,
            'deadline' => $this->deadline,
            'client_id' => $this->clientId,
            'lead_id' => $this->leadId,
            'collaborator_id' => $this->collaboratorId,
            'referral_code' => $this->referralCode,
            'notes' => $this->notes,
        ];
    }
}
