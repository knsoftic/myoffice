<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\FollowUpData;
use App\DataObjects\Crm\FollowUpFilters;
use App\DataObjects\Crm\OutcomeData;
use App\Enums\LeadActivityType;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Events\Crm\LeadFollowUpCompleted;
use App\Events\Crm\LeadFollowUpMissed;
use App\Events\Crm\LeadFollowUpScheduled;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadFollowUp;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Notifications\Crm\LeadFollowUpDueReminder;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Services\Crm\Exceptions\FollowUpAlreadyOpenException;
use App\Support\DateRange;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Follow-ups: one open follow-up per lead, a reminder, an outcome, a reschedule chain (phase-05 §2.3, §6.3).
 *
 * **One pending row per lead, enforced by the database.** `uq_lfu_open(lead_id, open_guard)` rejects a second
 * `pending` row; the 1062 surfaces as `FollowUpAlreadyOpenException` naming the existing row's date and owner —
 * never a 500 (test 26). Because the unique index is the guard, a reschedule releases the old row
 * (`rescheduled`) before inserting the new one inside one transaction.
 *
 * **`leads.follow_up_at` is a cache of the open row** ([D-P5-12]), rewritten in the same transaction by every
 * method here: the open row's `scheduled_at`, or null when none is open (test 27).
 *
 * **Reminders cannot double-send.** `sendDueReminders()` selects due rows `FOR UPDATE SKIP LOCKED` (plain
 * `FOR UPDATE` on a server without SKIP LOCKED — MariaDB before 10.6 — where the second run simply waits and then
 * re-reads the stamped rows), stamps `reminder_sent_at` inside the transaction and queues each notification
 * after commit (tests 28, 29).
 */
final class LeadFollowUpService
{
    use InteractsWithCrm;

    public function __construct(
        private readonly LeadTimeline $timeline,
    ) {}

    public function schedule(Lead $lead, FollowUpData $data): LeadFollowUp
    {
        $this->assertNotInPast($data->scheduledAt);

        $followUp = DB::transaction(function () use ($lead, $data): LeadFollowUp {
            $locked = $this->lockLead($lead);

            $followUp = $this->insertPending($locked, $data, null);

            $this->timeline->writeCaches($locked, ['follow_up_at' => $followUp->getAttribute('scheduled_at')]);
            $this->syncLead($lead, $locked, ['follow_up_at']);

            $this->timeline->record($locked, LeadActivityType::from('follow_up_scheduled'), [
                'subject' => $this->headline('Follow-up scheduled', $followUp),
                'body' => $data->notes,
                'lead_follow_up_id' => (int) $followUp->getKey(),
                'meta' => ['type' => $data->type->value, 'scheduled_at' => $data->scheduledAt->toIso8601String()],
            ]);

            event(new LeadFollowUpScheduled($followUp));

            return $followUp;
        });

        return $followUp;
    }

    /**
     * The optional follow-up of `LeadService::create()`: same guarantees as `schedule()` — the unique guard, the
     * cache — but no activity row of its own, because a lead's creation writes exactly one timeline row, which
     * mentions the follow-up. Must be called inside the creating transaction.
     */
    public function openForNewLead(Lead $lead, FollowUpData $data): LeadFollowUp
    {
        $this->assertNotInPast($data->scheduledAt);

        $followUp = $this->insertPending($lead, $data, null);

        $this->timeline->writeCaches($lead, ['follow_up_at' => $followUp->getAttribute('scheduled_at')]);

        event(new LeadFollowUpScheduled($followUp));

        return $followUp;
    }

    public function complete(LeadFollowUp $followUp, OutcomeData $data): LeadFollowUp
    {
        if ($data->next instanceof FollowUpData) {
            $this->assertNotInPast($data->next->scheduledAt);
        }

        return DB::transaction(function () use ($followUp, $data): LeadFollowUp {
            $locked = $this->lockFollowUp($followUp);
            $this->assertPending($locked);

            $lead = $this->lockLead($this->leadOf($locked));
            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => LeadFollowUpStatus::from('completed'),
                'completed_at' => $now,
                'completed_by' => $this->actorId(),
                'outcome' => $data->outcome,
                'outcome_note' => $this->cleanText($data->note),
            ]);
            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $next = null;

            if ($data->next instanceof FollowUpData) {
                $next = $this->insertPending($lead, $data->next, $locked);
            }

            $contacted = $data->outcome->countsAsContact();

            $this->timeline->writeCaches($lead, ['follow_up_at' => $next?->getAttribute('scheduled_at')]);

            $this->timeline->record($lead, LeadActivityType::from('follow_up_completed'), [
                'subject' => sprintf('Follow-up completed: %s', $data->outcome->label()),
                'body' => $this->cleanText($data->note),
                'outcome' => $data->outcome,
                'lead_follow_up_id' => (int) $locked->getKey(),
                'occurred_at' => $now,
            ], $contacted);

            if ($next instanceof LeadFollowUp) {
                $this->timeline->record($lead, LeadActivityType::from('follow_up_scheduled'), [
                    'subject' => $this->headline('Next follow-up scheduled', $next),
                    'body' => $data->next?->notes,
                    'lead_follow_up_id' => (int) $next->getKey(),
                    'meta' => ['previous_follow_up_id' => (int) $locked->getKey()],
                ]);

                event(new LeadFollowUpScheduled($next, $locked));
            }

            event(new LeadFollowUpCompleted($locked, $next));

            $this->syncFollowUp($followUp, $locked);

            return $locked;
        });
    }

    /**
     * Move a pending follow-up to a new time: the old row becomes `rescheduled` (releasing `open_guard`) and a new
     * pending row points back at it, both in one transaction. Returns the new row.
     */
    public function reschedule(LeadFollowUp $followUp, CarbonInterface $to, string $reason): LeadFollowUp
    {
        $reason = $this->cleanText($reason, 255);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired('reason', 'Say why the follow-up is being moved.');
        }

        $to = CarbonImmutable::instance($to)->utc();
        $this->assertNotInPast($to);

        return DB::transaction(function () use ($followUp, $to, $reason): LeadFollowUp {
            $locked = $this->lockFollowUp($followUp);
            $this->assertPending($locked);

            $lead = $this->lockLead($this->leadOf($locked));

            $locked->forceFill([
                'status' => LeadFollowUpStatus::from('rescheduled'),
                'rescheduled_at' => CarbonImmutable::now(),
            ]);
            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $type = $locked->getAttribute('type');

            $next = $this->insertPending($lead, new FollowUpData(
                type: $type instanceof LeadFollowUpType ? $type : LeadFollowUpType::from((string) $type),
                scheduledAt: $to,
                remindBeforeMinutes: (int) $locked->getAttribute('remind_before_minutes'),
                assignedTo: $locked->getAttribute('assigned_to') === null ? null : (int) $locked->getAttribute('assigned_to'),
                notes: $locked->getAttribute('notes'),
            ), $locked, keepAssignee: true);

            $this->timeline->writeCaches($lead, ['follow_up_at' => $next->getAttribute('scheduled_at')]);

            $this->timeline->record($lead, LeadActivityType::from('follow_up_scheduled'), [
                'subject' => $this->headline('Follow-up rescheduled', $next),
                'body' => $reason,
                'lead_follow_up_id' => (int) $next->getKey(),
                'meta' => ['previous_follow_up_id' => (int) $locked->getKey(), 'reason' => $reason],
            ]);

            event(new LeadFollowUpScheduled($next, $locked));

            $this->syncFollowUp($followUp, $locked);

            return $next;
        });
    }

    public function cancel(LeadFollowUp $followUp, string $reason): void
    {
        $reason = $this->cleanText($reason, 255);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired('cancel_reason', 'Say why the follow-up is being cancelled.');
        }

        DB::transaction(function () use ($followUp, $reason): void {
            $locked = $this->lockFollowUp($followUp);
            $this->assertPending($locked);

            $lead = $this->lockLead($this->leadOf($locked));

            $this->close($locked, $lead, $reason);

            $this->timeline->record($lead, LeadActivityType::from('system'), [
                'subject' => 'Follow-up cancelled',
                'body' => $reason,
                'lead_follow_up_id' => (int) $locked->getKey(),
            ]);

            $this->syncFollowUp($followUp, $locked);
        });
    }

    /**
     * Cancel whatever follow-up is open on a lead, inside the caller's transaction — `LeadService::delete()`.
     */
    public function cancelOpenFor(Lead $lead, string $reason): ?LeadFollowUp
    {
        $open = LeadFollowUp::query()
            ->where('lead_id', $lead->getKey())
            ->where('status', LeadFollowUpStatus::from('pending')->value)
            ->lockForUpdate()
            ->first();

        if (! $open instanceof LeadFollowUp) {
            return null;
        }

        $this->close($open, $lead, $reason);

        return $open;
    }

    /**
     * `pending` → `missed`. Called **only** by `crm:follow-ups-mark-missed`; writes one `follow_up_missed` row and
     * fires `LeadFollowUpMissed`, whose listener notifies the assignee once. A row that is no longer pending is
     * left alone and returns false.
     */
    public function markMissed(LeadFollowUp $followUp): bool
    {
        return DB::transaction(function () use ($followUp): bool {
            $locked = $this->lockFollowUp($followUp);

            if (! $this->isPending($locked)) {
                return false;
            }

            $lead = $this->lockLead($this->leadOf($locked));

            $locked->forceFill(['status' => LeadFollowUpStatus::from('missed')]);
            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->timeline->writeCaches($lead, ['follow_up_at' => null]);

            $this->timeline->record($lead, LeadActivityType::from('follow_up_missed'), [
                'subject' => $this->headline('Follow-up missed', $locked),
                'lead_follow_up_id' => (int) $locked->getKey(),
            ]);

            event(new LeadFollowUpMissed($locked));

            $this->syncFollowUp($followUp, $locked);

            return true;
        });
    }

    /**
     * Pending rows whose grace window has passed — what the hourly command walks.
     *
     * @return list<int>
     */
    public function overdueIds(CarbonInterface $at, int $limit = 500): array
    {
        $grace = $this->crmInt('follow_up_overdue_grace_minutes', 120, 0, 525600);

        return LeadFollowUp::query()
            ->where('status', LeadFollowUpStatus::from('pending')->value)
            ->where('scheduled_at', '<', CarbonImmutable::instance($at)->subMinutes($grace))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * `status = pending AND reminder_sent_at IS NULL AND reminder_due_at <= $at`, oldest due first, locked with
     * SKIP LOCKED where the server supports it. **Must run inside a transaction** — `sendDueReminders()` is the
     * caller that holds one.
     *
     * @return Collection<int, LeadFollowUp>
     */
    public function dueReminders(CarbonInterface $at, int $limit): Collection
    {
        $query = LeadFollowUp::query()
            ->where('status', LeadFollowUpStatus::from('pending')->value)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('reminder_due_at')
            ->where('reminder_due_at', '<=', CarbonImmutable::instance($at))
            ->orderBy('reminder_due_at')
            ->orderBy('id')
            ->limit(max(1, $limit));

        $query->lock($this->supportsSkipLocked(DB::connection()) ? 'for update skip locked' : true);

        return $query->get();
    }

    /**
     * Stamp and send every due reminder, at most `$limit`; returns how many were sent. A crash between the stamp
     * and the send loses a reminder rather than doubling it — the stamp commits first, the notification is queued
     * after commit.
     */
    public function sendDueReminders(CarbonInterface $at, int $limit = 200): int
    {
        $channels = $this->crmList('follow_up_reminder_channels', ['database']);

        return DB::transaction(function () use ($at, $limit, $channels): int {
            $due = $this->dueReminders($at, $limit);

            if ($due->isEmpty()) {
                return 0;
            }

            $now = CarbonImmutable::now();

            LeadFollowUp::query()
                ->whereKey($due->modelKeys())
                ->whereNull('reminder_sent_at')
                ->toBase()
                ->update(['reminder_sent_at' => $now->format('Y-m-d H:i:s')]);

            $sent = 0;

            foreach ($due as $followUp) {
                $recipientId = $followUp->getAttribute('assigned_to') ?? $this->leadOf($followUp)->getAttribute('assigned_to');

                if ($recipientId === null) {
                    continue;
                }

                $followUpId = (int) $followUp->getKey();
                $sent++;

                DB::afterCommit(static function () use ($recipientId, $followUpId, $channels): void {
                    $user = User::query()->active()->whereKey((int) $recipientId)->first();
                    $row = LeadFollowUp::query()->find($followUpId);

                    if (! $user instanceof User || ! $row instanceof LeadFollowUp) {
                        return;
                    }

                    try {
                        $user->notify(new LeadFollowUpDueReminder($row, $channels));
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $sent;
        });
    }

    /**
     * "My follow-ups", already passed through the lead visibility scope (§9): a follow-up is listed only when its
     * lead is one the user may see. "All" is honoured only for a `leads.view_any` holder.
     *
     * @return LengthAwarePaginator<int, LeadFollowUp>
     */
    public function worklist(User $user, DateRange $range, FollowUpFilters $filters): LengthAwarePaginator
    {
        $seesAll = $user->can('leads.view_any');

        $query = LeadFollowUp::query()
            ->with(['lead' => static fn ($relation) => $relation->select(['id', 'lead_no', 'name', 'company', 'status', 'assigned_to', 'created_by', 'phone', 'whatsapp', 'email', 'country_code'])])
            ->whereHas('lead', static function (Builder $leads) use ($user): void {
                // The global scope already applies for the signed-in user; applying the same predicate for the named
                // user makes the rule hold for any other caller too (a queued digest, a test acting as someone else).
                LeadVisibilityScope::forUser($leads->withoutGlobalScope(LeadVisibilityScope::class), $user);
            });

        if ($filters->assignee === FollowUpFilters::ASSIGNEE_ALL && $seesAll) {
            // every assignee
        } elseif (is_int($filters->assignee) && $seesAll) {
            $query->where('assigned_to', $filters->assignee);
        } else {
            $query->where('assigned_to', $user->getKey());
        }

        if ($filters->type !== null) {
            $query->where('type', $filters->type->value);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->overdueOnly) {
            $query->where('status', LeadFollowUpStatus::from('pending')->value)->where('scheduled_at', '<', CarbonImmutable::now());
        } else {
            $range->apply($query, 'lead_follow_ups.scheduled_at');
        }

        if ($filters->search !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters->search).'%';

            $query->where(static function (Builder $inner) use ($like): void {
                $inner->where('lead_follow_ups.notes', 'like', $like)
                    ->orWhereHas('lead', static function (Builder $leads) use ($like): void {
                        $leads->where(static function (Builder $match) use ($like): void {
                            $match->where('lead_no', 'like', $like)
                                ->orWhere('name', 'like', $like)
                                ->orWhere('company', 'like', $like);
                        });
                    });
            });
        }

        $direction = $filters->direction === 'desc' ? 'desc' : 'asc';

        match ($filters->sort) {
            'type' => $query->orderBy('lead_follow_ups.type', $direction)->orderBy('lead_follow_ups.scheduled_at'),
            'status' => $query->orderBy('lead_follow_ups.status', $direction)->orderBy('lead_follow_ups.scheduled_at'),
            'lead' => $query->orderBy(
                Lead::query()->withoutGlobalScopes()->select('name')->whereColumn('leads.id', 'lead_follow_ups.lead_id')->limit(1),
                $direction,
            ),
            default => $query->orderBy('lead_follow_ups.scheduled_at', $direction),
        };

        return $query->orderBy('lead_follow_ups.id', $direction)->paginate($filters->perPage)->withQueryString();
    }

    /**
     * Insert one pending row; the unique guard's 1062 becomes the domain exception. Runs in a savepoint so the
     * caller's transaction survives the refusal.
     */
    private function insertPending(Lead $lead, FollowUpData $data, ?LeadFollowUp $previous, bool $keepAssignee = false): LeadFollowUp
    {
        $remind = $data->remindBeforeMinutes ?? $this->crmInt('follow_up_reminder_minutes', 60, 0, 525600);
        $assignee = $data->assignedTo ?? ($keepAssignee ? null : $lead->getAttribute('assigned_to'));
        $scheduledAt = $data->scheduledAt->utc();

        $followUp = new LeadFollowUp;
        $followUp->forceFill([
            'lead_id' => (int) $lead->getKey(),
            'assigned_to' => $assignee === null ? null : (int) $assignee,
            'type' => $data->type,
            'scheduled_at' => $scheduledAt,
            'remind_before_minutes' => $remind,
            'reminder_due_at' => $scheduledAt->subMinutes($remind),
            'reminder_sent_at' => null,
            'status' => LeadFollowUpStatus::from('pending'),
            'previous_follow_up_id' => $previous?->getKey(),
            'notes' => $this->cleanText($data->notes),
        ]);

        try {
            DB::transaction(fn (): bool => $this->withoutModelLogging(static fn (): bool => $followUp->save()));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'uq_lfu_open')) {
                throw $exception;
            }

            $existing = LeadFollowUp::query()
                ->with('assignee:id,name')
                ->where('lead_id', $lead->getKey())
                ->where('status', LeadFollowUpStatus::from('pending')->value)
                ->first();

            $scheduled = $existing?->getAttribute('scheduled_at');

            throw FollowUpAlreadyOpenException::for(
                $existing?->getKey() === null ? null : (int) $existing->getKey(),
                $scheduled instanceof CarbonInterface ? $scheduled : null,
                $existing?->assignee?->name,
            );
        }

        return $followUp;
    }

    private function close(LeadFollowUp $followUp, Lead $lead, string $reason): void
    {
        $followUp->forceFill([
            'status' => LeadFollowUpStatus::from('cancelled'),
            'cancel_reason' => mb_substr($reason, 0, 255),
        ]);
        $this->withoutModelLogging(static fn (): bool => $followUp->save());

        $this->timeline->writeCaches($lead, ['follow_up_at' => null]);
    }

    private function assertNotInPast(CarbonInterface $scheduledAt): void
    {
        $startOfToday = CarbonImmutable::now(Format::displayTimezone())->startOfDay();

        if (CarbonImmutable::instance($scheduledAt)->lessThan($startOfToday)) {
            throw CrmRuleException::refuse('scheduled_at', 'A follow-up cannot be scheduled on a day that has already passed.');
        }
    }

    private function assertPending(LeadFollowUp $followUp): void
    {
        if (! $this->isPending($followUp)) {
            throw CrmRuleException::refuse('follow_up', 'This follow-up is already closed.');
        }
    }

    private function isPending(LeadFollowUp $followUp): bool
    {
        $status = $followUp->getAttribute('status');
        $status = $status instanceof LeadFollowUpStatus ? $status : LeadFollowUpStatus::tryFrom((string) $status);

        return $status instanceof LeadFollowUpStatus && $status->isOpen();
    }

    private function lockFollowUp(LeadFollowUp $followUp): LeadFollowUp
    {
        /** @var LeadFollowUp $locked */
        $locked = LeadFollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function lockLead(Lead $lead): Lead
    {
        /** @var Lead $locked */
        $locked = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->whereKey($lead->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function leadOf(LeadFollowUp $followUp): Lead
    {
        /** @var Lead $lead */
        $lead = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->whereKey($followUp->getAttribute('lead_id'))
            ->firstOrFail();

        return $lead;
    }

    private function headline(string $prefix, LeadFollowUp $followUp): string
    {
        $type = $followUp->getAttribute('type');
        $label = $type instanceof BackedEnum && method_exists($type, 'label') ? $type->label() : (string) $type;
        $at = $followUp->getAttribute('scheduled_at');

        return mb_substr(sprintf('%s: %s on %s', $prefix, $label, $at instanceof CarbonInterface ? Format::dateTime($at) : '-'), 0, 150);
    }

    /**
     * @param  list<string>  $columns
     */
    private function syncLead(Lead $target, Lead $source, array $columns): void
    {
        if ($target === $source) {
            return;
        }

        foreach ($columns as $column) {
            $target->setAttribute($column, $source->getAttribute($column));
            $target->syncOriginalAttribute($column);
        }
    }

    private function syncFollowUp(LeadFollowUp $target, LeadFollowUp $source): void
    {
        if ($target !== $source) {
            $target->setRawAttributes($source->getAttributes(), true);
        }
    }

    private function supportsSkipLocked(ConnectionInterface $connection): bool
    {
        static $cache = [];

        $name = method_exists($connection, 'getName') ? (string) $connection->getName() : 'default';

        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }

        try {
            $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return $cache[$name] = false;
        }

        if (stripos($version, 'mariadb') !== false) {
            preg_match('/(\d+)\.(\d+)/', (string) preg_replace('/^5\.5\.5-/', '', $version), $m);

            return $cache[$name] = isset($m[1]) && ((int) $m[1] > 10 || ((int) $m[1] === 10 && (int) $m[2] >= 6));
        }

        if (method_exists($connection, 'getDriverName') && $connection->getDriverName() === 'sqlite') {
            return $cache[$name] = false;
        }

        preg_match('/^(\d+)\.(\d+)/', $version, $m);

        return $cache[$name] = isset($m[1]) && (int) $m[1] >= 8;
    }
}
