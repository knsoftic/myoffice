<?php

declare(strict_types=1);

namespace App\Contracts\Referrals;

use App\DataObjects\Crm\RecordedReferral;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The CRM's only door into referral attribution (phase-05 [D-P5-1], [D-P5-6], D28, D37).
 *
 * Phase 5 captures a `?ref=` code on a lead or a client (`referral_code_captured`) and never reads or writes
 * `collaborator_referrals` itself. Until Phase 9/10 rebinds this contract over the spine's
 * `ReferralService::attach()`, the container resolves `App\Support\Referrals\NullReferralRecorder`, whose
 * `isAvailable()` is false — so nothing is recorded, nothing is lost (the captured code stays on the row) and
 * `crm:record-captured-referrals` backfills every code once a real recorder is bound.
 *
 * An implementation never creates a commission row: a referred lead or client earns nothing by itself (INV-1,
 * phase-05 test 54). It writes exactly one `collaborator_referrals` row per `attach()` through the spine's
 * published methods and returns what the CRM needs as evidence.
 */
interface ReferralRecorder
{
    /** A `?ref=` click captured on the public site (spine `ReferralSource::ReferralLink`). */
    public const SOURCE_REFERRAL_LINK = 'referral_link';

    /** A staff pick, or attribution carried forward by a lead conversion (spine `ReferralSource::ManualSelection`). */
    public const SOURCE_MANUAL_SELECTION = 'manual_selection';

    /** A code arriving on a CSV import row (spine `ReferralSource::Import`). */
    public const SOURCE_IMPORT = 'import';

    /**
     * True only when a real recorder is bound and the referral tables exist.
     */
    public function isAvailable(): bool;

    /**
     * Resolve `$code` and record the attribution of `$subject` (a `Lead` or a `Client`).
     *
     * Returns null when the code resolves to no eligible collaborator — the caller then leaves
     * `referral_recorded_at` null. Returns the recorded (or already existing, for the same subject and
     * collaborator) referral otherwise.
     */
    public function attach(
        Model $subject,
        string $code,
        string $source,
        ?CarbonInterface $referralDate = null,
        ?int $referralVisitId = null,
        ?string $notes = null,
    ): ?RecordedReferral;

    /**
     * The live attribution of a subject, or null when it has none.
     */
    public function activeReferralFor(Model $subject): ?RecordedReferral;
}
