<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How an attribution was arrived at (`collaborator_referrals.referral_source`, finance spine §3).
 *
 * These are the outputs of phase-08-09 §6.3's precedence ladder, and they are kept because **how** a
 * partner came to be credited is what a dispute turns on: a receptionist naming them at the desk and a
 * cookie from three weeks ago are both legitimate, and they are not equally strong evidence.
 */
enum ReferralSource: string
{
    use HasOptions;

    case ReferralLink = 'referral_link';
    case ManualSelection = 'manual_selection';
    case AdmissionForm = 'admission_form';
    case Import = 'import';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::ReferralLink => 'Referral link',
            self::ManualSelection => 'Chosen by staff',
            self::AdmissionForm => 'Typed on the form',
            self::Import => 'Imported',
            self::Api => 'Through the API',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ReferralLink => 'sky',
            self::ManualSelection => 'violet',
            self::AdmissionForm => 'emerald',
            self::Import, self::Api => 'slate',
        };
    }

    /**
     * Did a person decide this, rather than a carried token?
     *
     * A staff selection outranks every captured candidate (INV-R3) precisely because somebody looked at
     * the situation and said so.
     */
    public function isHumanDecision(): bool
    {
        return in_array($this, [self::ManualSelection, self::AdmissionForm], true);
    }

    /**
     * Is there a visit row behind this one?
     */
    public function hasVisitEvidence(): bool
    {
        return $this === self::ReferralLink;
    }
}
