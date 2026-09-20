<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use Illuminate\Database\DatabaseManager;

/**
 * The seven collaborators a commission calculation needs, as one injected value
 * (phase-10-12 §6.1, §6.2).
 *
 * `StudentCommissionService` and `ProjectCommissionService` run the **same** sequence through
 * `RunsCommissionGuards`, and a trait cannot declare constructor dependencies. Listing all seven in
 * both services would mean two constructors to keep in step, and the day they drift is the day the two
 * sides of the business quietly stop resolving rules the same way. One value, named once.
 *
 * It is deliberately not a service locator: every field is a concrete class, resolved by the container
 * at construction, and nothing here can be asked for something that is not on this list.
 */
final readonly class CommissionEngineContext
{
    public function __construct(
        public DatabaseManager $db,
        public ReferralService $referrals,
        public CommissionRuleService $rules,
        public CommissionBaseResolver $bases,
        public CommissionEntitlementService $entitlements,
        public LedgerWriter $ledger,
        public CollaboratorActivityService $activity,
    ) {}
}
