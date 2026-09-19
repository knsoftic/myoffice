<?php

declare(strict_types=1);

namespace App\Console\Commands\Crm;

use App\Services\Crm\CapturedReferralService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `crm:record-captured-referrals` — every 15 minutes (phase-05 §10.5, test 52).
 *
 * Leads and clients with a `referral_code_captured` and no `referral_recorded_at` are attached through
 * `ReferralRecorder`. A no-op while the recorder is unavailable (before Phase 9/10), and a no-op on a second run,
 * because a successful attach stamps `referral_recorded_at` in the same transaction.
 */
#[AsCommand(name: 'crm:record-captured-referrals')]
final class RecordCapturedReferrals extends Command
{
    protected $signature = 'crm:record-captured-referrals {--limit=500 : Maximum leads and clients each attempted in this run}';

    protected $description = 'Attach referral codes captured on leads and clients once a referral recorder is bound';

    public function handle(CapturedReferralService $referrals): int
    {
        $result = $referrals->backfill(max(1, min(5000, (int) $this->option('limit'))));

        if (! $result['available']) {
            $this->info('No referral recorder is bound yet: captured codes are kept and will be attached later.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d lead referral(s) and %d client referral(s) recorded (%d attempted).',
            $result['leads'],
            $result['clients'],
            $result['attempted'],
        ));

        return self::SUCCESS;
    }
}
