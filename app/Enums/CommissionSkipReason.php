<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a receipt earned nobody anything (`*.commission_skip_reason`, finance spine §3, twenty cases).
 *
 * **This enum is the answer to the only question a partner ever really asks.** A commission engine that
 * silently produces nothing is indistinguishable from one that is broken, and the difference between
 * "your code was not on the admission" and "the rule had not started yet" is the difference between a
 * conversation and an argument. Every skip is recorded with one of these and appears on the skip report.
 *
 * Fourteen are **configuration**: the business decided this, and the answer is a settings change or
 * nothing at all. Six are **operational** and mean somebody should look — they are the `amber` ones.
 */
enum CommissionSkipReason: string
{
    use HasOptions;

    case ReferralSystemDisabled = 'referral_system_disabled';
    case AutomaticCommissionDisabled = 'automatic_commission_disabled';
    case PaymentNotCleared = 'payment_not_cleared';
    case NoReferral = 'no_referral';
    case ReferralNotCommissionEligible = 'referral_not_commission_eligible';
    case CollaboratorInactive = 'collaborator_inactive';
    case CollaboratorDeleted = 'collaborator_deleted';
    case CommissionDisabled = 'commission_disabled';
    case NoEffectiveRule = 'no_effective_rule';
    case FeeTypeNotCommissionable = 'fee_type_not_commissionable';
    case MilestoneNotCommissionable = 'milestone_not_commissionable';
    case BelowMinimumPayment = 'below_minimum_payment';
    case BaseZero = 'base_zero';
    case OverpaymentOnly = 'overpayment_only';
    case NoCollectibleDenominator = 'no_collectible_denominator';
    case EntitlementCapReached = 'entitlement_cap_reached';
    case RoundsToZero = 'rounds_to_zero';
    case BelowMinimumCommission = 'below_minimum_commission';
    case SourceCommissionMissing = 'source_commission_missing';
    case ReversalNotApproved = 'reversal_not_approved';

    public function label(): string
    {
        return match ($this) {
            self::ReferralSystemDisabled => 'Referral tracking is switched off',
            self::AutomaticCommissionDisabled => 'Automatic commission is switched off',
            self::PaymentNotCleared => 'The payment has not cleared',
            self::NoReferral => 'Nobody referred this',
            self::ReferralNotCommissionEligible => 'The referral does not earn commission',
            self::CollaboratorInactive => 'The collaborator is not active',
            self::CollaboratorDeleted => 'The collaborator has been removed',
            self::CommissionDisabled => 'This commission type is off for that collaborator',
            self::NoEffectiveRule => 'No rule was in force on that date',
            self::FeeTypeNotCommissionable => 'This fee type does not earn commission',
            self::MilestoneNotCommissionable => 'This milestone does not earn commission',
            self::BelowMinimumPayment => 'The payment is below the minimum',
            self::BaseZero => 'There was nothing to take a percentage of',
            self::OverpaymentOnly => 'The receipt was entirely an overpayment',
            self::NoCollectibleDenominator => 'Nothing was owed, so no share could be worked out',
            self::EntitlementCapReached => 'The whole promise has already been released',
            self::RoundsToZero => 'The commission rounds to zero',
            self::BelowMinimumCommission => 'The commission is below the minimum entry amount',
            self::SourceCommissionMissing => 'The commission being reversed does not exist',
            self::ReversalNotApproved => 'The reversal has not been approved',
        };
    }

    /**
     * `amber` for the six an operator should act on; `slate` for the rest, which are the business's own
     * settings behaving as configured.
     */
    public function color(): string
    {
        return $this->needsAttention() ? 'amber' : 'slate';
    }

    /**
     * Should somebody look at this?
     *
     * A partner who is inactive, a rule that was never set, a commission type switched off for one
     * collaborator, an admission with no referral on it, a reversal waiting for a signature and a
     * clawback whose original cannot be found — each of these is usually a mistake rather than a policy.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [
            self::CollaboratorInactive,
            self::NoEffectiveRule,
            self::CommissionDisabled,
            self::NoReferral,
            self::SourceCommissionMissing,
            self::ReversalNotApproved,
        ], true);
    }

    /**
     * Is this the business's configuration answering, rather than a fact about this receipt?
     *
     * The three global switches produce the same skip on every payment until they are changed, so the
     * skip report groups them rather than listing a thousand identical rows.
     */
    public function isGlobalSetting(): bool
    {
        return in_array($this, [
            self::ReferralSystemDisabled,
            self::AutomaticCommissionDisabled,
        ], true);
    }

    /**
     * Would re-running the engine on the same receipt reach a different answer?
     *
     * Only when something outside the receipt changes — a rule created, a partner reactivated, a
     * reversal approved. The arithmetic ones (`rounds_to_zero`, `base_zero`, `overpayment_only`) will
     * always reach the same result, so `commissions:evaluate` skips them rather than churning.
     */
    public function couldChangeOnRetry(): bool
    {
        return in_array($this, [
            self::ReferralSystemDisabled,
            self::AutomaticCommissionDisabled,
            self::PaymentNotCleared,
            self::NoReferral,
            self::ReferralNotCommissionEligible,
            self::CollaboratorInactive,
            self::CommissionDisabled,
            self::NoEffectiveRule,
            self::FeeTypeNotCommissionable,
            self::MilestoneNotCommissionable,
            self::SourceCommissionMissing,
            self::ReversalNotApproved,
        ], true);
    }
}
