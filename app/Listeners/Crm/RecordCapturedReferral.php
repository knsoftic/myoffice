<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\ClientCreated;
use App\Events\Crm\LeadCreated;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Services\Crm\CapturedReferralService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Attaches a captured `?ref=` code once a lead or a client exists (phase-05 §10.2).
 *
 * Calls `ReferralRecorder::attach()` only when the recorder `isAvailable()` and a code was captured and not yet
 * recorded; a no-op otherwise — `crm:record-captured-referrals` picks up whatever this could not. Creates no
 * commission row (INV-1).
 */
final class RecordCapturedReferral implements ShouldQueue
{
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly CapturedReferralService $referrals,
    ) {}

    public function handle(LeadCreated|ClientCreated $event): void
    {
        if (! $this->referrals->available()) {
            return;
        }

        if ($event instanceof LeadCreated) {
            $lead = Lead::query()->withoutGlobalScope(LeadVisibilityScope::class)->withTrashed()->find($event->lead->getKey());

            if ($lead instanceof Lead) {
                $this->referrals->recordLead($lead);
            }

            return;
        }

        $client = Client::query()->withTrashed()->find($event->client->getKey());

        if ($client instanceof Client) {
            $this->referrals->recordClient($client);
        }
    }
}
