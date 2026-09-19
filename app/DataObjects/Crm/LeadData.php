<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\InquirySource;
use App\Enums\LeadStatus;

/**
 * The writable profile of a lead (phase-05 §2.1, §6.1 `create()` / `update()`).
 *
 * `status`, `assigned_to`, `client_id`, `lead_no` and `converted_at` are **never** written by `update()` — each
 * has its own method. `$status` here is read only by `create()`, for an import's default status.
 *
 * `$provided` names the profile fields the caller actually supplied: `update()` touches only those, which is
 * how `update_existing` imports change "only fields the CSV actually supplies" (§6.6). `fromArray()` fills it
 * from the payload's keys; the constructor default (null) means "every profile field".
 */
final readonly class LeadData
{
    use ReadsInput;

    /**
     * Profile columns `update()` may write, keyed by payload name.
     *
     * @var array<string, string>
     */
    public const PROFILE_FIELDS = [
        'name' => 'name',
        'company' => 'company',
        'email' => 'email',
        'phone' => 'phone',
        'whatsapp' => 'whatsapp',
        'country' => 'country',
        'country_code' => 'countryCode',
        'service_id' => 'serviceId',
        'interested_service' => 'interestedService',
        'budget_amount' => 'budgetAmount',
        'source' => 'source',
        'source_detail' => 'sourceDetail',
        'notes' => 'notes',
    ];

    /**
     * @param  list<string>|null  $provided  payload keys of PROFILE_FIELDS that were supplied; null = all
     * @param  array<string, mixed>  $meta  merged into the creation activity row (import row number, file name)
     * @param  int|null  $createdBy  the person a system write acts for (an import's importer); never from a request
     */
    public function __construct(
        public string $name,
        public ?string $company = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $whatsapp = null,
        public ?string $country = null,
        public ?string $countryCode = null,
        public ?int $serviceId = null,
        public ?string $interestedService = null,
        public ?string $budgetAmount = null,
        public ?InquirySource $source = null,
        public ?string $sourceDetail = null,
        public ?string $notes = null,
        public ?int $assignedTo = null,
        public ?LeadStatus $status = null,
        public ?string $referralCode = null,
        public ?int $referralVisitId = null,
        public ?int $contactInquiryId = null,
        public ?int $leadImportId = null,
        public ?FollowUpData $followUp = null,
        public bool $confirmDuplicate = false,
        public ?int $duplicateOfLeadId = null,
        public ?array $provided = null,
        public array $meta = [],
        public ?int $createdBy = null,
    ) {}

    /**
     * Build from a validated `StoreLeadRequest` / `UpdateLeadRequest` payload.
     *
     * Keys: the PROFILE_FIELDS keys plus `assigned_to`, `referral_code`, `confirm_duplicate` and a nested
     * `follow_up` array (`type`, `scheduled_at`, `remind_before_minutes`, `assigned_to`, `notes`).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $countryCode = self::str($data, 'country_code', 2);

        return new self(
            name: (string) self::str($data, 'name', 150),
            company: self::str($data, 'company', 150),
            email: self::str($data, 'email', 150),
            phone: self::str($data, 'phone', 32),
            whatsapp: self::str($data, 'whatsapp', 32),
            country: self::str($data, 'country', 64),
            countryCode: $countryCode === null ? null : strtoupper($countryCode),
            serviceId: self::int($data, 'service_id'),
            interestedService: self::str($data, 'interested_service', 150),
            budgetAmount: self::money($data, 'budget_amount'),
            source: self::enum($data, 'source', InquirySource::class),
            sourceDetail: self::str($data, 'source_detail', 255),
            notes: self::str($data, 'notes'),
            assignedTo: self::int($data, 'assigned_to'),
            status: self::enum($data, 'status', LeadStatus::class),
            referralCode: self::str($data, 'referral_code', 32),
            referralVisitId: self::int($data, 'referral_visit_id'),
            followUp: FollowUpData::fromNested($data, 'follow_up'),
            confirmDuplicate: self::bool($data, 'confirm_duplicate'),
            provided: self::provided($data, array_keys(self::PROFILE_FIELDS)),
        );
    }

    /**
     * Was this profile field (payload key) supplied?
     */
    public function supplies(string $field): bool
    {
        return $this->provided === null || in_array($field, $this->provided, true);
    }

    /**
     * The contact half, for duplicate detection.
     */
    public function candidate(): ContactCandidate
    {
        return new ContactCandidate(
            phone: $this->phone,
            whatsapp: $this->whatsapp,
            email: $this->email,
            countryCode: $this->countryCode,
        );
    }

    /**
     * A copy with some constructor arguments replaced.
     *
     * @param  array<string, mixed>  $changes  constructor argument name => value
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
