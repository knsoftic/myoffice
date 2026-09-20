<?php

declare(strict_types=1);

namespace App\DataObjects\Project;

use App\Enums\Priority;
use App\Enums\ProgressBasis;
use App\Enums\ProjectType;

/**
 * The writable record of a project (phase-06 §2.1, §6.1 `create()` / `update()`).
 *
 * Deliberately absent: `code` (issued once by `ProjectNumberService`), `status` (`changeStatus()`), the
 * five value and commission columns (`ProjectValueService::revise()`, INV-P1), the attribution snapshot
 * (`ProjectReferralService`, INV-P13) and every progress column (`ProjectProgressService`, INV-P8). A data
 * object that carried them would make the invariants depend on a controller remembering not to pass them.
 *
 * `$provided` lists the payload keys that were actually supplied, so `update()` writes only those and a
 * partial form cannot blank a field it never showed.
 *
 * `projectValue` is the one exception, and only on **create**: §6.1 writes the opening value with the
 * project and records it as revision 1 when it is non-zero, because a project that starts at 260,000 with
 * no revision row would have an unexplained first number.
 */
final readonly class ProjectData
{
    /**
     * Columns `create()` / `update()` write, payload key => constructor argument.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'name' => 'name',
        'client_id' => 'clientId',
        'lead_id' => 'leadId',
        'project_manager_id' => 'projectManagerId',
        'service_id' => 'serviceId',
        'description' => 'description',
        'project_type' => 'projectType',
        'priority' => 'priority',
        'start_date' => 'startDate',
        'deadline' => 'deadline',
        'currency' => 'currency',
        'budget_amount' => 'budgetAmount',
        'progress_basis' => 'progressBasis',
    ];

    /**
     * Columns this object must never carry into `update()`, each with the service that owns it.
     *
     * Dropping them silently would leave a caller with a 200 and no change — the quiet no-op that hides a
     * bug for months. `ProjectService::update()` refuses the request instead and names the right door.
     *
     * @var array<string, string>
     */
    public const SERVICE_OWNED = [
        'project_value' => 'ProjectValueService::revise()',
        'discount_amount' => 'ProjectValueService::revise()',
        'commission_type' => 'ProjectValueService::revise()',
        'commission_rate' => 'ProjectValueService::revise()',
        'commission_fixed_amount' => 'ProjectValueService::revise()',
        'collaborator_id' => 'ProjectReferralService',
        'referral_code' => 'ProjectReferralService',
        'referral_date' => 'ProjectReferralService',
        'progress_percent' => 'ProjectProgressService',
        'progress_mode' => 'ProjectProgressService',
        'progress_reason' => 'ProjectProgressService',
        'status' => 'ProjectService::changeStatus()',
        'code' => 'ProjectNumberService (it is issued once)',
    ];

    /**
     * @param  list<string>  $provided
     * @param  list<string>  $serviceOwned  keys the payload carried that belong to another service
     */
    public function __construct(
        public ?string $name = null,
        public ?int $clientId = null,
        public ?int $leadId = null,
        public ?int $projectManagerId = null,
        public ?int $serviceId = null,
        public ?string $description = null,
        public ProjectType|string|null $projectType = null,
        public Priority|string|null $priority = null,
        public ?string $startDate = null,
        public ?string $deadline = null,
        public ?string $currency = null,
        public ?string $budgetAmount = null,
        public ProgressBasis|string|null $progressBasis = null,
        public ?string $projectValue = null,
        public array $provided = [],
        public array $serviceOwned = [],
    ) {}

    /**
     * Build from a validated Form Request payload, remembering which keys it carried.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $read = static fn (string $key): mixed => $input[$key] ?? null;

        return new self(
            name: self::string($read('name')),
            clientId: self::int($read('client_id')),
            leadId: self::int($read('lead_id')),
            projectManagerId: self::int($read('project_manager_id')),
            serviceId: self::int($read('service_id')),
            description: self::string($read('description')),
            projectType: self::string($read('project_type')),
            priority: self::string($read('priority')),
            startDate: self::string($read('start_date')),
            deadline: self::string($read('deadline')),
            currency: self::string($read('currency')),
            budgetAmount: self::string($read('budget_amount')),
            progressBasis: self::string($read('progress_basis')),
            projectValue: self::string($read('project_value')),
            provided: array_values(array_intersect(array_keys(self::FIELDS), array_keys($input))),
            serviceOwned: array_values(array_intersect(array_keys(self::SERVICE_OWNED), array_keys($input))),
        );
    }

    /**
     * The columns to write, as `column => value`, limited to what the payload supplied.
     *
     * @return array<string, mixed>
     */
    public function attributes(bool $onlyProvided = false): array
    {
        $attributes = [];

        foreach (self::FIELDS as $column => $property) {
            if ($onlyProvided && ! in_array($column, $this->provided, true)) {
                continue;
            }

            $value = $this->{$property};

            if ($value === null && ! in_array($column, $this->provided, true)) {
                continue;
            }

            $attributes[$column] = $value instanceof ProjectType
                || $value instanceof Priority
                || $value instanceof ProgressBasis
                    ? $value->value
                    : $value;
        }

        return $attributes;
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
