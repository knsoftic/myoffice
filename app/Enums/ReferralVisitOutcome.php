<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What happened to a click on a referral URL (phase-08-09 §3.2, requirement §38).
 *
 * The same discipline the commission spine applies to a skipped payment: every reason a click did **not**
 * attribute is a named case rather than a missing row, so the funnel report can say "forty-one people
 * used a code that no longer exists" instead of showing a gap.
 *
 * {@see attributable()} is true for `captured` only. Everything else is evidence that somebody tried.
 */
enum ReferralVisitOutcome: string
{
    use HasOptions;

    case Captured = 'captured';
    case InvalidCode = 'invalid_code';
    case CollaboratorNotEligible = 'collaborator_not_eligible';
    case SelfReferral = 'self_referral';
    case BotFiltered = 'bot_filtered';
    case Expired = 'expired';
    case Converted = 'converted';
    case Overridden = 'overridden';

    public function label(): string
    {
        return match ($this) {
            self::Captured => 'Captured',
            self::InvalidCode => 'Code not recognised',
            self::CollaboratorNotEligible => 'Collaborator not eligible',
            self::SelfReferral => 'Self-referral',
            self::BotFiltered => 'Filtered as a crawler',
            self::Expired => 'Expired',
            self::Converted => 'Converted',
            self::Overridden => 'Overridden by staff',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Captured => 'sky',
            self::Converted => 'emerald',
            self::InvalidCode, self::CollaboratorNotEligible, self::SelfReferral => 'rose',
            self::BotFiltered, self::Expired => 'slate',
            self::Overridden => 'amber',
        };
    }

    /**
     * Can a visit in this state still win an attribution?
     *
     * A converted visit has already spent its attribution, and the five refusals never had one.
     */
    public function attributable(): bool
    {
        return in_array($this, self::attributableCases(), true);
    }

    /**
     * The same answer as a list, so a query and a loaded row can never disagree about it.
     *
     * @return list<self>
     */
    public static function attributableCases(): array
    {
        return [self::Captured];
    }

    /**
     * Is this a state the visit reached on its own, rather than one a person caused?
     */
    public function isRefusal(): bool
    {
        return in_array($this, [
            self::InvalidCode,
            self::CollaboratorNotEligible,
            self::SelfReferral,
            self::BotFiltered,
            self::Expired,
        ], true);
    }
}
