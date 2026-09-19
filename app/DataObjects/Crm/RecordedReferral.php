<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use Carbon\CarbonImmutable;

/**
 * A `collaborator_referrals` row as the CRM sees it through `ReferralRecorder` (phase-05 §6.4 step 7, D37).
 *
 * Evidence only: `id` becomes `lead_conversions.collaborator_referral_id`, `collaboratorId` and `referralCode`
 * travel to `ProjectCreator`, and `referralDate` is the lead's date that starts the client's eligibility
 * window. No CRM screen or scope grants access from it.
 */
final readonly class RecordedReferral
{
    public function __construct(
        public int $id,
        public int $collaboratorId,
        public string $referralCode,
        public CarbonImmutable $referralDate,
        public ?string $collaboratorName = null,
    ) {}
}
