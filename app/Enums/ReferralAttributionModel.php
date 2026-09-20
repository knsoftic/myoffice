<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which visit wins when one visitor arrived through several referral links (phase-08-09 §3.2, §5).
 *
 * The business chooses, in `collaborator.referral_attribution_model`. The default is **last touch**: the
 * partner whose link the person clicked immediately before applying is the one who actually persuaded
 * them, and it is also the answer a partner expects when they look at their own click report.
 *
 * First touch is offered because some businesses pay the partner who found the lead rather than the one
 * who closed it. Both are defensible; what is not defensible is deciding it per query.
 */
enum ReferralAttributionModel: string
{
    use HasOptions;

    case FirstTouch = 'first_touch';
    case LastTouch = 'last_touch';

    public function label(): string
    {
        return match ($this) {
            self::FirstTouch => 'First touch — the partner who found them',
            self::LastTouch => 'Last touch — the partner who closed them',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FirstTouch => 'sky',
            self::LastTouch => 'emerald',
        };
    }

    /**
     * How a visit query should be ordered so the winner is first.
     */
    public function orderDirection(): string
    {
        return $this === self::FirstTouch ? 'asc' : 'desc';
    }
}
