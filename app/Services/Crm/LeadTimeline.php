<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\LeadActivityType;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Scopes\LeadVisibilityScope;
use App\Services\Crm\Concerns\InteractsWithCrm;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one writer of `lead_activities` rows and of the two timeline caches on `leads` (phase-05 §2.2, §6.1).
 *
 * Every service that changes a lead calls `record()` inside its own transaction, so the row and the
 * `last_activity_at` / `last_contacted_at` stamps commit or roll back with the act they describe — the caches
 * are "inside the service's transaction, not a second write" (§10.2).
 *
 * `is_system` is derived from the type (`LeadActivityType::isSystem()`), never taken from a caller: a status move
 * cannot be written as an editable note. The cache columns are written with the query builder, so a timeline
 * stamp neither moves `leads.updated_at` nor adds a generic "Lead updated" row to the audit log.
 */
final class LeadTimeline
{
    use InteractsWithCrm;

    /**
     * @param  array<string, mixed>  $attributes  subject, body, outcome, duration_minutes, from_status, to_status,
     *                                            from_user_id, to_user_id, lead_follow_up_id, related_lead_id,
     *                                            occurred_at, meta
     */
    public function record(Lead $lead, LeadActivityType $type, array $attributes = [], bool $contacted = false): LeadActivity
    {
        $occurredAt = $attributes['occurred_at'] ?? null;
        $occurredAt = $occurredAt instanceof CarbonInterface ? CarbonImmutable::instance($occurredAt) : CarbonImmutable::now();

        $activity = new LeadActivity;

        $activity->forceFill(array_merge([
            'subject' => null,
            'body' => null,
            'outcome' => null,
            'duration_minutes' => null,
            'from_status' => null,
            'to_status' => null,
            'from_user_id' => null,
            'to_user_id' => null,
            'lead_follow_up_id' => null,
            'related_lead_id' => null,
            'meta' => null,
        ], array_intersect_key($attributes, array_flip([
            'subject', 'body', 'outcome', 'duration_minutes', 'from_status', 'to_status', 'from_user_id',
            'to_user_id', 'lead_follow_up_id', 'related_lead_id', 'meta',
        ])), [
            'lead_id' => (int) $lead->getKey(),
            'type' => $type,
            'is_system' => $type->isSystem(),
            'occurred_at' => $occurredAt,
        ]));

        if (is_string($activity->getAttribute('subject'))) {
            $activity->setAttribute('subject', mb_substr((string) $activity->getAttribute('subject'), 0, 150));
        }

        $this->withoutModelLogging(static fn (): bool => $activity->save());

        $this->touch($lead, $occurredAt, $contacted);

        return $activity;
    }

    /**
     * Stamp `last_activity_at` (and `last_contacted_at`) forward — never backwards, so a back-dated call logged
     * after a newer note does not rewind the "stale" chip.
     */
    public function touch(Lead $lead, ?CarbonInterface $at = null, bool $contacted = false): void
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());
        $values = [];

        $lastActivity = $lead->getAttribute('last_activity_at');

        if (! $lastActivity instanceof CarbonInterface || $lastActivity->lessThan($at)) {
            $values['last_activity_at'] = $at;
        }

        if ($contacted) {
            $lastContacted = $lead->getAttribute('last_contacted_at');

            if (! $lastContacted instanceof CarbonInterface || $lastContacted->lessThan($at)) {
                $values['last_contacted_at'] = $at;
            }
        }

        $this->writeCaches($lead, $values);
    }

    /**
     * Write cache columns on `leads` without a model event, mirroring them onto the in-memory model.
     *
     * @param  array<string, mixed>  $values
     */
    public function writeCaches(Lead $lead, array $values): void
    {
        if ($values === []) {
            return;
        }

        Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->whereKey($lead->getKey())
            ->toBase()
            ->update(array_map(
                static fn (mixed $value): mixed => $value instanceof CarbonInterface ? $value->copy()->utc()->format('Y-m-d H:i:s') : $value,
                $values,
            ));

        foreach ($values as $column => $value) {
            $lead->setAttribute($column, $value);
            $lead->syncOriginalAttribute($column);
        }
    }
}
