<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Contracts\Referrals\ReferralRecorder;
use App\DataObjects\Crm\ActivityData;
use App\DataObjects\Crm\BulkResult;
use App\DataObjects\Crm\DuplicateReport;
use App\DataObjects\Crm\FollowUpData;
use App\DataObjects\Crm\LeadData;
use App\DataObjects\Crm\RecordedReferral;
use App\DataObjects\Crm\StatusChangeData;
use App\Enums\InquirySource;
use App\Enums\LeadActivityType;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadStatus;
use App\Events\Crm\LeadActivityLogged;
use App\Events\Crm\LeadAssigned;
use App\Events\Crm\LeadCreated;
use App\Events\Crm\LeadDuplicateDetected;
use App\Events\Crm\LeadDuplicateLinked;
use App\Events\Crm\LeadStatusChanged;
use App\Events\Crm\LeadUpdated;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadConversion;
use App\Models\Crm\LeadFollowUp;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Services\Crm\Exceptions\DuplicateLeadException;
use App\Services\Crm\Exceptions\IllegalLeadTransitionException;
use App\Services\Crm\Exceptions\StaleLeadStatusException;
use App\Services\Finance\DocumentNumberService;
use App\Support\ContactNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Everything that writes a lead (phase-05 §6.1). Every public write runs in one `DB::transaction()`; every event
 * implements `ShouldDispatchAfterCommit`.
 *
 *   · **Numbering** — `lead_no` from `DocumentNumberService` (`crm.lead_number_prefix` + counter, pad from
 *     `crm.number_padding`) inside the creating transaction, with the single retry on a 1062 of `uq_leads_no`.
 *     A 1062 of `uq_leads_inquiry` is **not** retried: it means the inquiry already has its lead (F-3.7).
 *   · **Normalisation** — `ContactNormalizer` fills the three `*_normalized` columns on every write.
 *   · **Status moves** — §2.11 exactly: row lock, compare-and-swap on `expectedFrom` (409), the transition table
 *     (422 with the allowed list), the mandatory reasons, the live-conversion guard, the follow-up rule, one
 *     `status_changed` timeline row and one `activity_log` entry with old, new and the reason.
 *   · **Bulk** — explicit ids only, capped at `crm.bulk_max_ids` before any row is locked, rows invisible to the
 *     actor under §9 reported `forbidden`, rows locked in ascending id order, each row validated individually.
 *
 * Status, owner, client link, conversion stamp and number each have their own method; `update()` never touches
 * them. No commission row is created anywhere here (INV-1, test 54).
 */
final class LeadService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    private const MODULE = 'leads';

    /** The five types a person may log by hand. */
    private const MANUAL_TYPES = ['note', 'call', 'whatsapp', 'email', 'meeting'];

    /** Statuses a lead may enter only with a follow-up in place, under `crm.require_follow_up_on_contacted`. */
    private const FOLLOW_UP_STATUSES = ['contacted', 'interested', 'negotiation', 'proposal_sent'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly LeadDuplicateDetector $duplicates,
        private readonly LeadFollowUpService $followUps,
        private readonly LeadAutoAssigner $autoAssigner,
        private readonly LeadTimeline $timeline,
        private readonly ReferralRecorder $referrals,
    ) {}

    public function create(LeadData $data): Lead
    {
        $candidate = $data->candidate();
        $report = $candidate->isEmpty()
            ? DuplicateReport::none()
            : $this->duplicates->check($candidate, null, null, asSystem: $this->actor() === null);

        if (! $data->confirmDuplicate
            && $this->crmBool('duplicate_block_on_exact', false)
            && $report->hasExactMatch()) {
            throw DuplicateLeadException::forReport($report);
        }

        // A system write (an import row processed by a queue worker) acts for the person who started it, so the
        // §9 "created by me" rule still shows that person the leads they imported.
        $actorId = $this->actorId() ?? $data->createdBy;

        return DB::transaction(function () use ($data, $report, $actorId): Lead {
            $now = CarbonImmutable::now();
            $lead = new Lead;

            $lead->forceFill($this->profileAttributes($data, null));

            if ($this->actorId() === null && $data->createdBy !== null) {
                $lead->forceFill(['created_by' => $data->createdBy, 'updated_by' => $data->createdBy]);
            }

            $status = $data->status ?? LeadStatus::from('new');
            $assignee = $data->assignedTo ?? $this->autoAssigner->pick();
            $referralCode = $this->referralCode($data->referralCode);

            $lead->forceFill([
                'source' => $data->source ?? $this->defaultSource(),
                'status' => $status,
                'status_changed_at' => $now,
                'won_at' => $status === LeadStatus::from('won') ? $now : null,
                'assigned_to' => $assignee,
                'assigned_at' => $assignee === null ? null : $now,
                'assigned_by' => $assignee === null ? null : $actorId,
                'last_activity_at' => $now,
                'referral_code_captured' => $referralCode,
                'referral_visit_id' => $data->referralVisitId,
                'contact_inquiry_id' => $data->contactInquiryId,
                'lead_import_id' => $data->leadImportId,
                'duplicate_of_lead_id' => $data->duplicateOfLeadId,
                'duplicate_flagged_at' => $data->duplicateOfLeadId === null ? null : $now,
            ]);

            $this->numbers->assign(
                'crm.lead_number_prefix',
                'crm.lead_number_next_number',
                $this->crmPad(),
                static function (string $number) use ($lead): bool {
                    $lead->setAttribute('lead_no', $number);

                    return $lead->save();
                },
                'uq_leads_no',
            );

            $followUp = $data->followUp instanceof FollowUpData
                ? $this->followUps->openForNewLead($lead, $data->followUp)
                : null;

            if ($referralCode !== null) {
                $this->recordReferral($lead, $referralCode, $data->leadImportId === null
                    ? ReferralRecorder::SOURCE_REFERRAL_LINK
                    : ReferralRecorder::SOURCE_IMPORT);
            }

            $imported = $data->leadImportId !== null;

            $this->timeline->record($lead, LeadActivityType::from($imported ? 'imported' : 'system'), [
                'subject' => $imported ? 'Imported from CSV' : ($data->contactInquiryId !== null ? 'Lead created from a website inquiry' : 'Lead created'),
                'body' => $followUp instanceof LeadFollowUp ? 'A first follow-up was scheduled.' : null,
                'lead_follow_up_id' => $followUp?->getKey(),
                'related_lead_id' => $data->duplicateOfLeadId,
                'meta' => array_filter([
                    ...$data->meta,
                    'source' => ($data->source ?? $this->defaultSource())->value,
                    'contact_inquiry_id' => $data->contactInquiryId,
                    'lead_import_id' => $data->leadImportId,
                    'duplicate_matches' => $report->count() > 0 ? $report->count() : null,
                ], static fn (mixed $value): bool => $value !== null),
                'occurred_at' => $now,
            ]);

            event(new LeadCreated($lead, $actorId));

            if (! $report->isEmpty()) {
                event(new LeadDuplicateDetected($lead, $report->count(), $report->hasExactMatch()));
            }

            // Website leads announce themselves through NewLeadFromWebsite; a lead handed to someone else by hand
            // tells its new owner.
            if ($assignee !== null && $assignee !== $actorId && $data->contactInquiryId === null && ! $imported) {
                event(new LeadAssigned($lead, null, $assignee, $actorId));
            }

            return $lead;
        });
    }

    public function update(Lead $lead, LeadData $data): Lead
    {
        return DB::transaction(function () use ($lead, $data): Lead {
            $locked = $this->lock($lead);

            $attributes = $this->profileAttributes($data, $locked);
            $before = $this->snapshot($locked, array_keys($attributes));

            $locked->forceFill($attributes);

            if (! $locked->isDirty()) {
                return $this->refreshInto($lead, $locked);
            }

            $changes = $this->changes($before, $this->snapshot($locked, array_keys($attributes)));
            $changes = $this->withoutNormalised($changes);

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            if ($changes['attributes'] !== []) {
                $this->audit($locked, 'Lead updated', $changes, self::MODULE);
                event(new LeadUpdated($locked, $changes));
            }

            return $this->refreshInto($lead, $locked);
        });
    }

    public function changeStatus(Lead $lead, LeadStatus $to, StatusChangeData $d): Lead
    {
        $actorId = $this->actorId();

        return DB::transaction(function () use ($lead, $to, $d, $actorId): Lead {
            $locked = $this->lock($lead);
            $from = $this->statusOf($locked);

            if ($d->expectedFrom instanceof LeadStatus && $d->expectedFrom !== $from) {
                throw new StaleLeadStatusException($from, $d->expectedFrom);
            }

            $allowed = $from->allowedTransitions();

            if (! in_array($to, $allowed, true)) {
                throw IllegalLeadTransitionException::between($from, $to, $allowed);
            }

            $reason = $this->cleanText($d->reason, 500);
            $lostReason = $this->cleanText($d->lostReason, 255);
            $reopening = $from->isTerminal();

            if ($to->requiresReason() && $lostReason === null) {
                throw CrmRuleException::lostReasonRequired();
            }

            if ($reopening && $reason === null) {
                throw CrmRuleException::reasonRequired('reason', 'Say why this lead is being reopened.');
            }

            if ($from === LeadStatus::from('won') && $this->hasLiveConversion($locked)) {
                throw CrmRuleException::liveConversion();
            }

            $needsFollowUp = in_array($to->value, self::FOLLOW_UP_STATUSES, true)
                && $this->crmBool('require_follow_up_on_contacted', true)
                && ! $d->followUp instanceof FollowUpData
                && ! $this->hasOpenFollowUp($locked);

            if ($needsFollowUp) {
                throw CrmRuleException::followUpRequired();
            }

            $now = CarbonImmutable::now();
            $tracked = ['status', 'lost_reason', 'lost_at', 'won_at'];
            $before = $this->snapshot($locked, $tracked);

            $locked->forceFill(['status' => $to, 'status_changed_at' => $now]);

            if ($to === LeadStatus::from('won')) {
                $locked->forceFill(['won_at' => $now, 'lost_at' => null, 'lost_reason' => null]);
            } elseif ($to === LeadStatus::from('lost')) {
                $locked->forceFill(['lost_at' => $now, 'lost_reason' => $lostReason, 'won_at' => null]);
            } elseif ($reopening) {
                $locked->forceFill(['won_at' => null, 'lost_at' => null, 'lost_reason' => null]);
            }

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $changes = $this->changes($before, $this->snapshot($locked, $tracked));
            $auditReason = $to === LeadStatus::from('lost') ? $lostReason : $reason;

            $this->audit($locked, sprintf('Lead status changed from %s to %s', $from->label(), $to->label()), $changes, self::MODULE, $auditReason);

            if ($d->followUp instanceof FollowUpData) {
                $this->followUps->schedule($locked, $d->followUp);
            }

            $this->timeline->record($locked, LeadActivityType::from('status_changed'), [
                'subject' => sprintf('Status changed from %s to %s', $from->label(), $to->label()),
                'body' => $to === LeadStatus::from('lost') ? $lostReason : $reason,
                'from_status' => $from,
                'to_status' => $to,
                'occurred_at' => $now,
                'meta' => array_filter(['reason' => $reason, 'lost_reason' => $lostReason]),
            ]);

            event(new LeadStatusChanged($locked, $from, $to, $auditReason, $actorId));

            return $this->refreshInto($lead, $locked);
        });
    }

    public function assign(Lead $lead, ?User $to, ?string $reason): Lead
    {
        $actorId = $this->actorId();

        return DB::transaction(function () use ($lead, $to, $reason, $actorId): Lead {
            $locked = $this->lock($lead);

            $this->assignLocked($locked, $to, $this->cleanText($reason, 500), $actorId, audit: true);

            return $this->refreshInto($lead, $locked);
        });
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function bulkAssign(array $ids, ?User $to, ?string $reason): BulkResult
    {
        $ids = $this->normaliseIds($ids);
        $reason = $this->cleanText($reason, 500);
        $actorId = $this->actorId();
        $result = new BulkResult('assign');

        DB::transaction(function () use ($ids, $to, $reason, $actorId, $result): void {
            foreach ($this->lockVisible($ids, $result) as $lead) {
                if ($this->ownerId($lead) === ($to === null ? null : (int) $to->getKey())) {
                    $result->skipped((int) $lead->getKey(), 'Already assigned to this person.');

                    continue;
                }

                $this->assignLocked($lead, $to, $reason, $actorId, audit: false);
                $result->done((int) $lead->getKey());
            }

            $this->auditBulk('Leads bulk assigned', $result, [
                'to_user_id' => $to?->getKey(),
            ], $reason);
        });

        return $result;
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function bulkChangeStatus(array $ids, LeadStatus $to, ?string $reason): BulkResult
    {
        $ids = $this->normaliseIds($ids);
        $reason = $this->cleanText($reason, 500);
        $result = new BulkResult('change_status');

        DB::transaction(function () use ($ids, $to, $reason, $result): void {
            foreach ($this->lockVisible($ids, $result) as $lead) {
                $id = (int) $lead->getKey();

                try {
                    // Each row runs in its own savepoint: a refused row leaves no partial write behind.
                    DB::transaction(fn (): Lead => $this->changeStatus($lead, $to, new StatusChangeData(
                        reason: $reason,
                        lostReason: $to->requiresReason() ? $reason : null,
                    )));

                    $result->done($id);
                } catch (ValidationException $exception) {
                    $result->skipped($id, $this->firstMessage($exception));
                } catch (StaleLeadStatusException $exception) {
                    $result->skipped($id, $exception->getMessage());
                }
            }

            $this->auditBulk('Leads bulk status change', $result, ['to_status' => $to->value], $reason);
        });

        return $result;
    }

    /**
     * Soft-delete several leads, each through `delete()` so every guard holds per row.
     *
     * @param  array<int, int|string>  $ids
     */
    public function bulkDelete(array $ids, string $reason): BulkResult
    {
        $ids = $this->normaliseIds($ids);
        $result = new BulkResult('delete');

        DB::transaction(function () use ($ids, $reason, $result): void {
            foreach ($this->lockVisible($ids, $result) as $lead) {
                try {
                    DB::transaction(fn () => $this->delete($lead, $reason));
                    $result->done((int) $lead->getKey());
                } catch (ValidationException $exception) {
                    $result->skipped((int) $lead->getKey(), $this->firstMessage($exception));
                }
            }

            $this->auditBulk('Leads bulk deleted', $result, [], $reason);
        });

        return $result;
    }

    public function recordActivity(Lead $lead, ActivityData $data): LeadActivity
    {
        if (! in_array($data->type->value, self::MANUAL_TYPES, true)) {
            throw CrmRuleException::manualActivityTypeOnly();
        }

        if ($data->occurredAt instanceof CarbonInterface && $data->occurredAt->isFuture()) {
            throw CrmRuleException::refuse('occurred_at', 'An activity cannot be logged in the future.');
        }

        return DB::transaction(function () use ($lead, $data): LeadActivity {
            $locked = $this->lock($lead);

            $contacted = in_array($data->type->value, ['call', 'whatsapp', 'email', 'meeting'], true)
                && $data->outcome !== null
                && $data->outcome->countsAsContact();

            $activity = $this->timeline->record($locked, $data->type, [
                'subject' => $data->subject,
                'body' => $data->body,
                'outcome' => $data->outcome,
                'duration_minutes' => $data->durationMinutes,
                'occurred_at' => $data->occurredAt,
                'meta' => $data->meta === [] ? null : $data->meta,
            ], $contacted);

            event(new LeadActivityLogged($activity));

            $this->refreshInto($lead, $locked);

            return $activity;
        });
    }

    public function updateActivity(LeadActivity $activity, ActivityData $data): LeadActivity
    {
        return DB::transaction(function () use ($activity, $data): LeadActivity {
            /** @var LeadActivity $locked */
            $locked = LeadActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEditableActivity($locked);

            $actor = $this->actor();
            $authorId = $locked->getAttribute('created_by') === null ? null : (int) $locked->getAttribute('created_by');
            $isAuthor = $actor instanceof User && $authorId === (int) $actor->getKey();
            $window = $this->crmInt('activity_edit_window_minutes', 1440, 0);
            $createdAt = $locked->getAttribute('created_at');
            $windowOpen = $createdAt instanceof CarbonInterface && $createdAt->copy()->addMinutes($window)->isFuture();

            // An author edits their own note inside the window; after it, or on someone else's note, only
            // `leads.edit` may. A system actor (no user) is never an author and needs no window.
            if ($actor instanceof User && ! ($isAuthor && $windowOpen) && ! $actor->can('leads.edit')) {
                throw CrmRuleException::editWindowClosed($window);
            }

            if (! in_array($data->type->value, self::MANUAL_TYPES, true)) {
                throw CrmRuleException::manualActivityTypeOnly();
            }

            $tracked = ['type', 'subject', 'body', 'outcome', 'duration_minutes', 'occurred_at'];
            $before = $this->snapshot($locked, $tracked);

            $locked->forceFill([
                'type' => $data->type,
                'subject' => $data->subject === null ? null : mb_substr($data->subject, 0, 150),
                'body' => $data->body,
                'outcome' => $data->outcome,
                'duration_minutes' => $data->durationMinutes,
                'occurred_at' => $data->occurredAt ?? $locked->getAttribute('occurred_at'),
            ]);

            $changes = $this->changes($before, $this->snapshot($locked, $tracked));

            if ($changes['attributes'] === []) {
                return $locked;
            }

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit($locked, 'Lead activity edited', $changes, self::MODULE);

            $activity->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function deleteActivity(LeadActivity $activity): void
    {
        DB::transaction(function () use ($activity): void {
            /** @var LeadActivity $locked */
            $locked = LeadActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEditableActivity($locked);

            $this->audit($locked, 'Lead activity deleted', [
                'old' => $this->snapshot($locked, ['lead_id', 'type', 'subject', 'body', 'outcome', 'occurred_at']),
                'attributes' => [],
            ], self::MODULE);

            $this->withoutModelLogging(static fn (): ?bool => $locked->delete());
        });
    }

    public function linkDuplicate(Lead $duplicate, Lead $original, string $note): Lead
    {
        $note = $this->cleanText($note, 255);
        $actorId = $this->actorId();

        if ((int) $duplicate->getKey() === (int) $original->getKey()) {
            throw CrmRuleException::refuse('original_lead_id', 'A lead cannot be a duplicate of itself.');
        }

        return DB::transaction(function () use ($duplicate, $original, $note, $actorId): Lead {
            // Lock both rows in ascending id order, like every multi-row write here.
            $ids = [(int) $duplicate->getKey(), (int) $original->getKey()];
            sort($ids);

            $locked = Lead::query()
                ->withoutGlobalScope(LeadVisibilityScope::class)
                ->withTrashed()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (Lead $lead): int => (int) $lead->getKey());

            $dup = $locked->get((int) $duplicate->getKey());
            $orig = $locked->get((int) $original->getKey());

            if (! $dup instanceof Lead || ! $orig instanceof Lead) {
                throw CrmRuleException::refuse('original_lead_id', 'That lead no longer exists.');
            }

            $this->assertNoCycle($dup, $orig);

            $before = $this->snapshot($dup, ['duplicate_of_lead_id', 'duplicate_note']);
            $now = CarbonImmutable::now();

            $dup->forceFill([
                'duplicate_of_lead_id' => (int) $orig->getKey(),
                'duplicate_flagged_at' => $now,
                'duplicate_note' => $note,
            ]);

            $this->withoutModelLogging(static fn (): bool => $dup->save());

            $this->audit($dup, 'Lead linked as a duplicate', $this->changes($before, $this->snapshot($dup, ['duplicate_of_lead_id', 'duplicate_note'])), self::MODULE, $note);

            $this->timeline->record($dup, LeadActivityType::from('duplicate_linked'), [
                'subject' => sprintf('Linked as a duplicate of %s', (string) $orig->getAttribute('lead_no')),
                'body' => $note,
                'related_lead_id' => (int) $orig->getKey(),
                'occurred_at' => $now,
            ]);

            $this->timeline->record($orig, LeadActivityType::from('duplicate_linked'), [
                'subject' => sprintf('%s was linked as a duplicate of this lead', (string) $dup->getAttribute('lead_no')),
                'body' => $note,
                'related_lead_id' => (int) $dup->getKey(),
                'occurred_at' => $now,
            ]);

            event(new LeadDuplicateLinked($dup, $orig, $actorId));

            return $this->refreshInto($duplicate, $dup);
        });
    }

    public function delete(Lead $lead, string $reason): void
    {
        $reason = $this->cleanText($reason, 500);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired();
        }

        DB::transaction(function () use ($lead, $reason): void {
            $locked = $this->lock($lead);

            if ($this->hasLiveConversion($locked)) {
                throw CrmRuleException::refuse('lead', 'A converted lead cannot be deleted. Supersede its conversion instead.');
            }

            $this->followUps->cancelOpenFor($locked, 'Lead deleted: '.$reason);

            $this->timeline->record($locked, LeadActivityType::from('system'), [
                'subject' => 'Lead deleted',
                'body' => $reason,
            ]);

            $locked->withReason($reason)->delete();

            $this->refreshInto($lead, $locked);
        });
    }

    /**
     * Restore a soft-deleted lead. A follow-up cancelled by the delete stays cancelled.
     */
    public function restore(Lead $lead): Lead
    {
        return DB::transaction(function () use ($lead): Lead {
            $locked = $this->lock($lead);

            if ($locked->getAttribute('deleted_at') === null) {
                return $this->refreshInto($lead, $locked);
            }

            $locked->restore();

            $this->timeline->record($locked, LeadActivityType::from('system'), ['subject' => 'Lead restored']);

            return $this->refreshInto($lead, $locked);
        });
    }

    /**
     * Attach a captured code through the recorder when one is bound, stamping `referral_recorded_at` on success.
     * Runs in a savepoint and never fails the caller: a code that does not record now is backfilled by
     * `crm:record-captured-referrals`. Returns the recorded referral, or null.
     */
    public function recordReferral(Lead $lead, string $code, string $source = ReferralRecorder::SOURCE_REFERRAL_LINK): ?RecordedReferral
    {
        if (! $this->referrals->isAvailable() || $lead->getAttribute('referral_recorded_at') !== null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($lead, $code, $source): ?RecordedReferral {
                // Re-read under a lock: the listener and the backfill command may reach the same lead together.
                $stamp = Lead::query()
                    ->withoutGlobalScope(LeadVisibilityScope::class)
                    ->withTrashed()
                    ->whereKey($lead->getKey())
                    ->lockForUpdate()
                    ->value('referral_recorded_at');

                if ($stamp !== null) {
                    return null;
                }

                $recorded = $this->referrals->attach(
                    $lead,
                    $code,
                    $source,
                    CarbonImmutable::instance($lead->getAttribute('created_at') ?? CarbonImmutable::now()),
                    $lead->getAttribute('referral_visit_id') === null ? null : (int) $lead->getAttribute('referral_visit_id'),
                );

                if ($recorded instanceof RecordedReferral) {
                    $this->timeline->writeCaches($lead, ['referral_recorded_at' => CarbonImmutable::now()]);
                }

                return $recorded;
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profileAttributes(LeadData $data, ?Lead $existing): array
    {
        $attributes = [];

        foreach (LeadData::PROFILE_FIELDS as $column => $property) {
            if ($existing !== null && ! $data->supplies($column)) {
                continue;
            }

            $value = $data->{$property};

            // `source` is never blanked by an update; a create defaults it separately.
            if ($column === 'source' && $value === null) {
                continue;
            }

            $attributes[$column] = $value;
        }

        if ($existing === null && ! array_key_exists('name', $attributes)) {
            $attributes['name'] = $data->name;
        }

        $country = array_key_exists('country_code', $attributes) ? $attributes['country_code'] : $existing?->getAttribute('country_code');
        $country = is_string($country) ? $country : null;

        foreach (['phone', 'whatsapp'] as $column) {
            if (array_key_exists($column, $attributes) || ($existing !== null && array_key_exists('country_code', $attributes))) {
                $raw = array_key_exists($column, $attributes) ? $attributes[$column] : $existing?->getAttribute($column);
                $attributes[$column.'_normalized'] = ContactNormalizer::phone(is_string($raw) ? $raw : null, $country);
            }
        }

        if (array_key_exists('email', $attributes)) {
            $attributes['email_normalized'] = ContactNormalizer::email(is_string($attributes['email']) ? $attributes['email'] : null);
        }

        return $attributes;
    }

    private function assignLocked(Lead $locked, ?User $to, ?string $reason, ?int $actorId, bool $audit): void
    {
        $fromId = $this->ownerId($locked);
        $toId = $to === null ? null : (int) $to->getKey();

        if ($fromId === $toId) {
            return;
        }

        $now = CarbonImmutable::now();
        $before = $this->snapshot($locked, ['assigned_to']);

        $locked->forceFill([
            'assigned_to' => $toId,
            'assigned_at' => $toId === null ? null : $now,
            'assigned_by' => $actorId,
        ]);

        $this->withoutModelLogging(static fn (): bool => $locked->save());

        if ($audit) {
            $this->audit($locked, $toId === null ? 'Lead unassigned' : 'Lead assigned', $this->changes($before, $this->snapshot($locked, ['assigned_to'])), self::MODULE, $reason);
        }

        // The open follow-up follows the lead, unless it had been handed to someone else on purpose.
        LeadFollowUp::query()
            ->where('lead_id', $locked->getKey())
            ->where('status', LeadFollowUpStatus::from('pending')->value)
            ->where(static function ($query) use ($fromId): void {
                $fromId === null ? $query->whereNull('assigned_to') : $query->where('assigned_to', $fromId)->orWhereNull('assigned_to');
            })
            ->toBase()
            ->update(['assigned_to' => $toId]);

        $this->timeline->record($locked, LeadActivityType::from('assigned'), [
            'subject' => $toId === null ? 'Lead unassigned' : sprintf('Assigned to %s', (string) $to?->getAttribute('name')),
            'body' => $reason,
            'from_user_id' => $fromId,
            'to_user_id' => $toId,
            'occurred_at' => $now,
        ]);

        event(new LeadAssigned($locked, $fromId, $toId, $actorId, $reason));
    }

    /**
     * Lock the ids the actor may see, ascending; record the rest as `forbidden` or `missing`.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Lead>
     */
    private function lockVisible(array $ids, BulkResult $result): Collection
    {
        // Visible under §9 for the signed-in actor: the global scope decides, exactly as on the index.
        $visible = Lead::query()->whereKey($ids)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $existing = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->whereKey($ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($ids as $id) {
            if (! in_array($id, $existing, true)) {
                $result->missing($id);
            } elseif (! in_array($id, $visible, true)) {
                $result->forbidden($id);
            }
        }

        if ($visible === []) {
            return collect();
        }

        return Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->whereKey($visible)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->values();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function normaliseIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        $clean = array_values($clean);
        sort($clean);

        $max = $this->crmInt('bulk_max_ids', 200, 1);

        if (count($clean) > $max) {
            throw CrmRuleException::tooManyIds($max);
        }

        if ($clean === []) {
            throw CrmRuleException::refuse('ids', 'Select at least one lead.');
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditBulk(string $description, BulkResult $result, array $extra, ?string $reason): void
    {
        $actor = $this->actor();

        if (! $actor instanceof User) {
            return;
        }

        $this->audit($actor, $description, [
            'attributes' => [
                ...$extra,
                'count' => $result->count(BulkResult::DONE),
                'skipped' => $result->count(BulkResult::SKIPPED),
                'forbidden' => $result->count(BulkResult::FORBIDDEN),
                'missing' => $result->count(BulkResult::MISSING),
                'lead_ids' => $result->ids(BulkResult::DONE),
            ],
        ], self::MODULE, $reason);
    }

    private function assertEditableActivity(LeadActivity $activity): void
    {
        $type = $activity->getAttribute('type');
        $type = $type instanceof LeadActivityType ? $type : LeadActivityType::tryFrom((string) $type);

        if ((bool) $activity->getAttribute('is_system') || ! $type instanceof LeadActivityType || $type->isSystem()) {
            throw CrmRuleException::systemActivity();
        }
    }

    private function assertNoCycle(Lead $duplicate, Lead $original): void
    {
        $cursor = $original;
        $seen = [(int) $duplicate->getKey()];

        for ($depth = 0; $depth < 10; $depth++) {
            $next = $cursor->getAttribute('duplicate_of_lead_id');

            if ($next === null) {
                return;
            }

            if (in_array((int) $next, $seen, true)) {
                throw CrmRuleException::refuse('original_lead_id', 'Linking these leads would create a duplicate cycle.');
            }

            $seen[] = (int) $cursor->getKey();

            $cursor = Lead::query()
                ->withoutGlobalScope(LeadVisibilityScope::class)
                ->withTrashed()
                ->whereKey((int) $next)
                ->first(['id', 'duplicate_of_lead_id']);

            if (! $cursor instanceof Lead) {
                return;
            }
        }

        throw CrmRuleException::refuse('original_lead_id', 'This duplicate chain is too long to link safely.');
    }

    private function hasLiveConversion(Lead $lead): bool
    {
        return LeadConversion::query()
            ->where('lead_id', $lead->getKey())
            ->whereNull('superseded_at')
            ->exists();
    }

    private function hasOpenFollowUp(Lead $lead): bool
    {
        return LeadFollowUp::query()
            ->where('lead_id', $lead->getKey())
            ->where('status', LeadFollowUpStatus::from('pending')->value)
            ->exists();
    }

    private function lock(Lead $lead): Lead
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

    private function refreshInto(Lead $target, Lead $source): Lead
    {
        if ($target !== $source) {
            $target->setRawAttributes($source->getAttributes(), true);
        }

        return $target;
    }

    private function statusOf(Lead $lead): LeadStatus
    {
        $status = $lead->getAttribute('status');

        return $status instanceof LeadStatus ? $status : LeadStatus::from((string) $status);
    }

    private function ownerId(Lead $lead): ?int
    {
        $id = $lead->getAttribute('assigned_to');

        return $id === null ? null : (int) $id;
    }

    private function defaultSource(): InquirySource
    {
        return InquirySource::tryFrom((string) $this->crmSetting('default_lead_source', 'website')) ?? InquirySource::Website;
    }

    private function referralCode(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : mb_substr($code, 0, 32);
    }

    /**
     * @param  array{old: array<string, mixed>, attributes: array<string, mixed>}  $changes
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    private function withoutNormalised(array $changes): array
    {
        foreach (['phone_normalized', 'whatsapp_normalized', 'email_normalized'] as $column) {
            unset($changes['old'][$column], $changes['attributes'][$column]);
        }

        return $changes;
    }

    private function firstMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return $exception->getMessage();
    }
}
