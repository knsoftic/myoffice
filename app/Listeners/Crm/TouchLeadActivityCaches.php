<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Enums\LeadActivityType;
use App\Events\Crm\LeadActivityLogged;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Services\Crm\LeadTimeline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Keeps `leads.last_activity_at` / `last_contacted_at` truthful for a logged activity (phase-05 §10.2).
 *
 * The services stamp both caches **inside their own transaction** (`LeadTimeline::record()`), so for every row they
 * write this listener finds nothing to do and writes nothing — it is not a second write. It exists for a timeline
 * row that reaches `LeadActivityLogged` by another path, and it only ever moves a cache forward.
 * Synchronous on purpose: it is cheap and must not lag behind the board's "stale" chip.
 */
final class TouchLeadActivityCaches
{
    public function __construct(
        private readonly LeadTimeline $timeline,
    ) {}

    public function handle(LeadActivityLogged $event): void
    {
        $activity = $event->activity;

        $lead = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->find($activity->getAttribute('lead_id'), ['id', 'last_activity_at', 'last_contacted_at']);

        if (! $lead instanceof Lead) {
            return;
        }

        $occurred = $activity->getAttribute('occurred_at');
        $occurred = $occurred instanceof CarbonInterface ? CarbonImmutable::instance($occurred) : CarbonImmutable::now();

        $type = $activity->getAttribute('type');
        $outcome = $activity->getAttribute('outcome');
        $contacted = $type instanceof LeadActivityType
            && in_array($type->value, ['call', 'whatsapp', 'email', 'meeting'], true)
            && $outcome !== null
            && method_exists($outcome, 'countsAsContact')
            && $outcome->countsAsContact();

        $lastActivity = $lead->getAttribute('last_activity_at');
        $lastContacted = $lead->getAttribute('last_contacted_at');

        $behind = ! $lastActivity instanceof CarbonInterface || $lastActivity->lessThan($occurred)
            || ($contacted && (! $lastContacted instanceof CarbonInterface || $lastContacted->lessThan($occurred)));

        if ($behind) {
            $this->timeline->touch($lead, $occurred, $contacted);
        }
    }
}
