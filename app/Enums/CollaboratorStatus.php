<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a collaborator stands with the business (phase-08-09 §3.1, requirement §34).
 *
 * Four states, exactly the four §34 names — not five, and not a boolean plus a reason.
 *
 * {@see earnsCommission()} is the one that matters: it is the **single place** the commission engine's
 * guard step 4 asks whether a partner is currently earning. Phase 8 deliberately keeps no
 * `commission_eligible` column, no ledger row and no wallet flag to express the same thing (INV-C4) — a
 * second expression of eligibility is a second thing to keep in step, and the one that drifts is always
 * the one money is paid from.
 *
 * `pending` is an application, not a partner: it can hold a profile and a referral code, and it earns
 * nothing.
 */
enum CollaboratorStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending approval',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'emerald',
            self::Inactive => 'slate',
            self::Suspended => 'rose',
        };
    }

    /**
     * May this collaborator sign in to their panel?
     */
    public function canLogin(): bool
    {
        return $this === self::Active;
    }

    /**
     * Is this collaborator currently earning commission?
     *
     * The commission engine asks this and nothing else (INV-C4).
     */
    public function earnsCommission(): bool
    {
        return $this === self::Active;
    }

    /**
     * May a **new** referral be attributed to this collaborator?
     *
     * `active` freely; `pending` and `inactive` only when a staff member confirms it, because somebody
     * genuinely does refer a student the week before their application is approved; `suspended` never —
     * a suspension exists precisely to stop new business flowing to somebody.
     */
    public function isSelectableForNewReferral(): bool
    {
        return $this === self::Active;
    }

    /**
     * Does moving **to** this status need a written reason?
     */
    public function requiresReason(): bool
    {
        return $this === self::Inactive || $this === self::Suspended;
    }

    /**
     * Is this a decision somebody took, rather than a state the record started in?
     */
    public function needsStaffConfirmationForReferral(): bool
    {
        return $this === self::Pending || $this === self::Inactive;
    }

    /**
     * §6.2.2's transition table, and nothing else is legal.
     *
     * Four states, closed on purpose: a status somebody can set freely is a status that eventually means
     * nothing, and this one decides whether money is earned (INV-C4).
     *
     * `pending` is a one-way door — it is where a record starts, and nothing ever returns to it. The two
     * ways out are approval and rejection, and rejection lands on `inactive` rather than inventing a
     * fifth state, because §34 names four.
     *
     * Reinstating from `inactive` or `suspended` **backfills no commission**: an admin with
     * `collaborator_commissions.approve` runs `commissions:evaluate` explicitly, and the spine's
     * `uq_cle_source` makes that safe exactly once.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Inactive],
            self::Active => [self::Inactive, self::Suspended],
            self::Inactive => [self::Active],
            self::Suspended => [self::Active, self::Inactive],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
