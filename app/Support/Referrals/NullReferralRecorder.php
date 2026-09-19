<?php

declare(strict_types=1);

namespace App\Support\Referrals;

use App\Contracts\Referrals\ReferralRecorder;
use App\DataObjects\Crm\RecordedReferral;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The referral recorder before Phase 9/10 exists (phase-05 [D-P5-6], E13, F8).
 *
 * Unavailable, records nothing and resolves nothing. The captured `?ref=` code stays on the lead or client row
 * with `referral_recorded_at` null, and `crm:record-captured-referrals` attaches it the day a real recorder is
 * bound — so nothing is lost between Phase 5 and Phase 9/10 (tests 50, 52).
 */
final class NullReferralRecorder implements ReferralRecorder
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function attach(
        Model $subject,
        string $code,
        string $source,
        ?CarbonInterface $referralDate = null,
        ?int $referralVisitId = null,
        ?string $notes = null,
    ): ?RecordedReferral {
        return null;
    }

    public function activeReferralFor(Model $subject): ?RecordedReferral
    {
        return null;
    }
}
