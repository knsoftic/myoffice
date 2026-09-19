<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Contracts\Referrals\ReferralRecorder;
use App\DataObjects\Crm\RecordedReferral;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Attaches `?ref=` codes captured on leads and clients once a real `ReferralRecorder` is bound (phase-05 [D-P5-6],
 * §10.2 `RecordCapturedReferral`, §10.5 `crm:record-captured-referrals`, tests 50-52).
 *
 * **Idempotent by `referral_recorded_at`.** Only rows with a captured code and a null stamp are attempted; the stamp
 * is written in the same transaction as a successful `attach()`, so a second run — or the listener racing the
 * command — finds nothing left to do. **A no-op while the recorder is unavailable**: nothing is stamped, so every
 * code captured before Phase 9/10 is attached the day the binding lands. A code that resolves to no collaborator
 * stays unstamped and is simply retried on the next run.
 *
 * Writes no commission row and reads no referral table itself (INV-1, D37).
 */
final class CapturedReferralService
{
    public function __construct(
        private readonly ReferralRecorder $referrals,
        private readonly LeadService $leads,
    ) {}

    public function available(): bool
    {
        try {
            return $this->referrals->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    public function recordLead(Lead $lead): ?RecordedReferral
    {
        $code = trim((string) $lead->getAttribute('referral_code_captured'));

        if ($code === '' || $lead->getAttribute('referral_recorded_at') !== null || ! $this->available()) {
            return null;
        }

        return $this->leads->recordReferral($lead, $code, $lead->getAttribute('lead_import_id') === null
            ? ReferralRecorder::SOURCE_REFERRAL_LINK
            : ReferralRecorder::SOURCE_IMPORT);
    }

    public function recordClient(Client $client): ?RecordedReferral
    {
        $code = trim((string) $client->getAttribute('referral_code_captured'));

        if ($code === '' || $client->getAttribute('referral_recorded_at') !== null || ! $this->available()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($client, $code): ?RecordedReferral {
                /** @var Client|null $locked */
                $locked = Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->first();

                if (! $locked instanceof Client || $locked->getAttribute('referral_recorded_at') !== null) {
                    return null;
                }

                $created = $locked->getAttribute('created_at');

                $recorded = $this->referrals->attach(
                    $locked,
                    $code,
                    ReferralRecorder::SOURCE_REFERRAL_LINK,
                    $created === null ? null : CarbonImmutable::instance($created),
                );

                if ($recorded instanceof RecordedReferral) {
                    $now = CarbonImmutable::now();

                    Client::query()->withTrashed()->whereKey($locked->getKey())->toBase()->update(['referral_recorded_at' => $now->format('Y-m-d H:i:s')]);
                    $client->setAttribute('referral_recorded_at', $now);
                    $client->syncOriginalAttribute('referral_recorded_at');
                }

                return $recorded;
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Walk every unrecorded captured code, oldest first.
     *
     * @return array{available: bool, leads: int, clients: int, attempted: int}
     */
    public function backfill(int $limit = 500): array
    {
        if (! $this->available()) {
            return ['available' => false, 'leads' => 0, 'clients' => 0, 'attempted' => 0];
        }

        $limit = max(1, $limit);
        $attempted = 0;
        $leads = 0;
        $clients = 0;

        $leadRows = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->whereNotNull('referral_code_captured')
            ->where('referral_code_captured', '<>', '')
            ->whereNull('referral_recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($leadRows as $lead) {
            $attempted++;

            if ($this->recordLead($lead) instanceof RecordedReferral) {
                $leads++;
            }
        }

        $clientRows = Client::query()
            ->withTrashed()
            ->whereNotNull('referral_code_captured')
            ->where('referral_code_captured', '<>', '')
            ->whereNull('referral_recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($clientRows as $client) {
            $attempted++;

            if ($this->recordClient($client) instanceof RecordedReferral) {
                $clients++;
            }
        }

        return ['available' => true, 'leads' => $leads, 'clients' => $clients, 'attempted' => $attempted];
    }
}
