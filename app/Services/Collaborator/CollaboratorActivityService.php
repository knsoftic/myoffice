<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Enums\CollaboratorActivityEvent;
use App\Models\Activity;
use App\Models\Collaborator\Collaborator;
use App\Support\DateRange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * §60's per-collaborator activity log (phase-08-09 §6.6).
 *
 * **One audit store** (D13). §60 asks for "the collaborator's activity log", and the answer is a
 * filtered view over `activity_log` rather than a second table. The filter is the indexed
 * `collaborator_id` column and **not** the causer, because the rows a partner most wants to see —
 * commission created, commission approved, payout paid — are written by the engine or the scheduler and
 * have a **null causer**. Scoping by causer would silently drop exactly those.
 *
 * **The feed is an allowlist, not a judgement** (F-12.7). It renders only the `properties` keys that
 * {@see CollaboratorActivityEvent::visibleProperties()} names for that event, and **never `reason`**.
 * That makes a staff-written reason about the collaborator — "suspended after repeated disputes" —
 * structurally unreachable rather than filtered case by case, which is the difference between a rule
 * and a habit: a property added by a later phase is invisible by default instead of leaking until
 * somebody notices.
 */
final class CollaboratorActivityService
{
    /**
     * Write one activity row against a collaborator.
     *
     * `$properties` is filtered through the event's own allowlist **before** it is stored, not only
     * before it is rendered. A payout account's encrypted details therefore never reach the table at
     * all (INV-C6) — there is nothing in the row for a later export, mail or exception payload to leak.
     */
    public function record(
        CollaboratorActivityEvent $event,
        Collaborator $collaborator,
        ?Model $subject = null,
        array $properties = [],
        ?string $reason = null,
    ): void {
        /** @var Activity $row */
        $row = activity()
            ->performedOn($subject instanceof Model ? $subject : $collaborator)
            ->withProperties($event->filterProperties($properties))
            ->event($event->activityEvent())
            ->log($event->label());

        // `collaborator_id`, `module` and `reason` are this system's columns, not spatie's, so they are
        // stamped onto the row the logger just returned — never by re-finding "the newest row", which
        // under two concurrent writers would stamp somebody else's. The alternative, a second audit
        // table, is what D13 forbids.
        //
        // The reason goes in its own **column**, deliberately not into `properties`: the audit trail
        // keeps it (§107) while `feed()` reads only allowlisted property keys, so a staff-written
        // sentence about a collaborator cannot reach that collaborator's own screen by accident.
        $row->forceFill(array_filter([
            'collaborator_id' => $collaborator->getKey(),
            'module' => $event->module(),
            'reason' => $reason === null || trim($reason) === '' ? null : $reason,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    /**
     * **The admin side**: every row about this collaborator, whatever the event.
     *
     * Deliberately not limited to §60's eleven. This is the audit trail behind `collaborators.view_logs`
     * — a profile edit, a status change with its reason, a referral code moved — and an auditor asking
     * "what happened to this partner" must not be shown a filtered subset without being told.
     * {@see feed()} is the partner's own view, and that one *is* the eleven.
     */
    public function auditTrail(Collaborator $collaborator, ?DateRange $range = null, int $perPage = 30): LengthAwarePaginator
    {
        return Activity::query()
            ->with('causer:id,name')
            ->where('collaborator_id', $collaborator->getKey())
            ->when($range !== null, static fn (Builder $query): Builder => $query
                ->whereBetween('created_at', [$range->start(), $range->end()]))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * **The partner's own side**: §60's eleven events for one collaborator, newest first.
     *
     * Driven by `INDEX (collaborator_id, created_at)`: the feed is always "this partner, newest first",
     * and without the composite index it would be a filesort over the whole audit table.
     */
    public function feed(
        Collaborator $collaborator,
        ?DateRange $range = null,
        ?CollaboratorActivityEvent $only = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->query($collaborator, $range, $only)
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The same feed reduced to what the collaborator's own screen may render: the event, when, and the
     * allowlisted properties. Nothing else on the row travels.
     *
     * @return array<int, array{event: CollaboratorActivityEvent|null, description: string, at: mixed, properties: array<string, mixed>}>
     */
    public function visibleFeed(
        Collaborator $collaborator,
        ?DateRange $range = null,
        ?CollaboratorActivityEvent $only = null,
        int $limit = 20,
    ): array {
        return $this->query($collaborator, $range, $only)
            ->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(static function (Activity $row): array {
                $event = CollaboratorActivityEvent::tryFrom((string) $row->event);
                $properties = $row->properties instanceof Collection
                    ? $row->properties->all()
                    : (array) $row->properties;

                return [
                    'event' => $event,
                    'description' => $event?->label() ?? (string) $row->description,
                    'at' => $row->created_at,
                    // Filtered again on the way out. Once at write time and once at read time is
                    // deliberate: rows written before an allowlist tightened must not start leaking
                    // because the write-time filter was the only one.
                    'properties' => $event?->filterProperties($properties) ?? [],
                ];
            })
            ->all();
    }

    /**
     * @param  CollaboratorActivityEvent|null  $only  one of §60's eleven, or every one of them
     * @return Builder<Activity>
     */
    private function query(Collaborator $collaborator, ?DateRange $range, ?CollaboratorActivityEvent $only): Builder
    {
        $events = $only !== null
            ? [$only->value]
            : array_column(CollaboratorActivityEvent::cases(), 'value');

        return Activity::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->whereIn('event', $events)
            ->when($range !== null, static fn (Builder $query): Builder => $query
                ->whereBetween('created_at', [$range->start(), $range->end()]));
    }
}
