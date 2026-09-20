<?php

declare(strict_types=1);

namespace App\Support\Collaborator;

use App\Enums\CollaborationType;
use Illuminate\Support\Carbon;

/**
 * Everything a collaborator form may say (phase-08-09 §6.2).
 *
 * **What is not here is the point.** There is no `status`, no `collaborator_code`, no `referral_code`
 * and no `user_id`: each of those has its own service method with its own permission and its own audit
 * row, and a data object that carried them would be a form that could rewrite history. `referralCode`
 * is the single exception, and only on **create** — an approver may hand out a vanity code at the
 * moment a partner is registered; afterwards it moves only through `CollaboratorCodeService`.
 */
final readonly class CollaboratorData
{
    /**
     * @param  list<string>  $skills  free text as typed; the slug is derived and the set replaces
     * @param  list<int>  $serviceIds  ids from Phase 4's catalogue
     */
    public function __construct(
        public string $name,
        public CollaborationType $collaborationType,
        public ?string $companyName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $whatsapp = null,
        public ?string $country = null,
        public ?string $address = null,
        public ?Carbon $joiningDate = null,
        public ?string $notes = null,
        public ?string $photoPath = null,
        public array $skills = [],
        public array $serviceIds = [],
        public ?string $referralCode = null,
    ) {}

    /**
     * Build from a validated Form Request payload. Absent keys stay absent rather than becoming null,
     * so a partial update cannot blank a field the form never showed.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $string = static function (mixed $value): ?string {
            if ($value === null) {
                return null;
            }

            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };

        return new self(
            name: (string) ($input['name'] ?? ''),
            collaborationType: $input['collaboration_type'] instanceof CollaborationType
                ? $input['collaboration_type']
                : CollaborationType::from((string) ($input['collaboration_type'] ?? CollaborationType::Freelancer->value)),
            companyName: $string($input['company_name'] ?? null),
            email: $string($input['email'] ?? null) !== null ? strtolower((string) $string($input['email'])) : null,
            phone: $string($input['phone'] ?? null),
            whatsapp: $string($input['whatsapp'] ?? null),
            country: $string($input['country'] ?? null),
            address: $string($input['address'] ?? null),
            joiningDate: isset($input['joining_date']) && $input['joining_date'] !== null && $input['joining_date'] !== ''
                ? Carbon::parse((string) $input['joining_date'])->startOfDay()
                : null,
            notes: $string($input['notes'] ?? null),
            photoPath: $string($input['photo_path'] ?? null),
            skills: array_values(array_filter(array_map(
                static fn (mixed $s): string => trim((string) $s),
                (array) ($input['skills'] ?? []),
            ), static fn (string $s): bool => $s !== '')),
            serviceIds: array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                (array) ($input['service_ids'] ?? []),
            ))),
            referralCode: $string($input['referral_code'] ?? null),
        );
    }

    /**
     * The `collaborators` columns this data writes — and only those.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'name' => $this->name,
            'company_name' => $this->companyName,
            'photo_path' => $this->photoPath,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'country' => $this->country,
            'address' => $this->address,
            'collaboration_type' => $this->collaborationType->value,
            'joining_date' => $this->joiningDate?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
