<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionScope;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferral;
use App\Support\Collaborator\CommissionSettings;

/**
 * Everything needed to open, or find, the promise a document carries for one collaborator
 * (spine §6.1.5, phase-10-12 §6.3).
 *
 * One value rather than eight parameters, because the eight are only ever passed together and an
 * entitlement opened from a different rule than the one that will release against it is the single
 * worst thing that can go wrong here: the promise would be computed at one rate and the slices at
 * another, and nothing would look wrong on either row.
 */
final readonly class EntitlementContext
{
    public function __construct(
        public Collaborator $collaborator,
        public CollaboratorReferral $referral,
        public RuleResolution $rule,
        public BaseResolution $document,
        public CommissionScope $scope,
        public CommissionSettings $settings,
    ) {}
}
