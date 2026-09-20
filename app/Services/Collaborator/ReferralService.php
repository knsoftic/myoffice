<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\ReferralContext;
use App\Enums\CollaboratorActivityEvent;
use App\Enums\CommissionScope;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Enums\ReferralSubject;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferral;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Services\Collaborator\Exceptions\ReferralConflictException;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The only writer of `collaborator_referrals` (finance spine §2.8, phase-10-12 §6.3, F-4.4).
 *
 * **Attribution is versioned, never overwritten.** Every method here either opens a window or closes
 * one. Nothing re-points an existing row at a different collaborator, because a ledger entry six months
 * old quotes its referral row to explain itself, and a mutable `collaborator_id` would silently rewrite
 * that explanation for every entry at once (INV-18).
 *
 * **`effectiveOn()` is what the engine calls, not `current()`.** A receipt is resolved against its own
 * value date (INV-16). A payment back-dated into a previous partner's window credits that partner — the
 * alternative, asking who the *current* partner is, quietly re-attributes every back-dated receipt to
 * whoever happens to hold the slot today.
 *
 * **One active referral per subject is a database fact**, not a convention here: `uq_cr_*_current` over
 * the generated `current_guard` column. This class turns the resulting 1062 into a sentence naming the
 * collaborator who already holds the credit, because that is the only form of the answer anybody can
 * act on.
 *
 * `superseded_by_id` is written by two methods — {@see change()} and {@see recordLosingCandidate()} —
 * and carries a plain, **non-unique** index (ND-12). Several losing candidates may point at one winner.
 * No guarantee in this system rests on that column; the one that matters is `uq_cr_*_current`.
 */
final class ReferralService
{
    /**
     * Model class to subject. Keyed by string rather than `::class` so the student entry is valid
     * before Phase 18 ships the model it names — the table's `student_id` column and its deferred
     * foreign key already exist.
     *
     * @var array<class-string|string, ReferralSubject>
     */
    private const SUBJECTS = [
        'App\\Models\\Institute\\Student' => ReferralSubject::Student,
        'App\\Models\\Project\\Project' => ReferralSubject::Project,
        'App\\Models\\Crm\\Client' => ReferralSubject::Client,
        'App\\Models\\Crm\\Lead' => ReferralSubject::Lead,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorCodeService $codes,
        private readonly CollaboratorActivityService $activity,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The row that credits somebody for a payment dated `$date` — zero or one, by the **value date**.
     */
    public function effectiveOn(Model $subject, CarbonInterface $date): ?CollaboratorReferral
    {
        return $this->effectiveOnSubject($this->subjectType($subject), $this->subjectId($subject), $date);
    }

    /**
     * The same question asked with a type and an id.
     *
     * The engine reaches a student through `student_fees.student_id`, and the student model belongs to a
     * later phase — so the primitive is public rather than the caller inventing a stand-in model. It is
     * also what a report asking about a deleted subject needs.
     */
    public function effectiveOnSubject(ReferralSubject $type, int $id, CarbonInterface $date): ?CollaboratorReferral
    {
        $on = Carbon::instance($date->toDateTime())->startOfDay();

        // **The newest decision about a day wins, and "nobody" is a decision.**
        //
        // Windows overlap by exactly one day at every boundary: a change closes the old row today and
        // opens the new one today, and a change made on the day an attribution started cannot do
        // otherwise. So the candidate set is every row covering the date — revoked and ineligible rows
        // included — and the winner is the one somebody decided latest.
        //
        // Only then is it asked whether it earns. Filtering the losers out of the query instead would
        // let a revoked row quietly fall through to the predecessor whose window also covers that day,
        // paying commission on a subject that was explicitly cut off.
        $winner = CollaboratorReferral::query()
            ->forSubject($type, $id)
            ->coveringDate($on)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $winner !== null && $winner->coversDate($on) ? $winner : null;
    }

    /**
     * The row occupying the subject's active slot right now. For a screen; never for the engine.
     */
    public function current(ReferralSubject $type, int $id): ?CollaboratorReferral
    {
        return CollaboratorReferral::query()->forSubject($type, $id)->current()->first();
    }

    /**
     * A referral code to a collaborator: trimmed, case-insensitive, **null rather than an exception**
     * for an unknown code — the public admission form asks this about whatever a visitor typed.
     */
    public function resolveCode(string $code): ?Collaborator
    {
        return $this->codes->resolveCode($code);
    }

    /**
     * Which side of the business a subject earns on. Published because the rule resolver and both
     * engines need the same answer, and two copies of a mapping is one copy too many.
     */
    public function scopeFor(ReferralSubject $type): CommissionScope
    {
        return $type->scope();
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Credit a subject to a collaborator from `$on` onwards.
     *
     * The code is **snapshotted**, not joined: a partner whose code is later reissued must not change
     * what this row says it was. The six evidence columns come from `$context`, which is how Phase 9's
     * click reaches the attribution row (F-4.3).
     */
    public function attach(
        Model $subject,
        Collaborator $collaborator,
        ReferralSource $source,
        ?string $code = null,
        ?CarbonInterface $on = null,
        ?ReferralContext $context = null,
    ): CollaboratorReferral {
        return $this->attachSubject(
            $this->subjectType($subject),
            $this->subjectId($subject),
            $collaborator,
            $source,
            $code,
            $on,
            $context,
        );
    }

    /**
     * The same act, addressed by type and id — the writing half of {@see effectiveOnSubject()}.
     *
     * A student is attributed before Phase 18's model exists, by the admission form that creates the
     * student in the first place. Without this the only way to credit a partner for a student would be
     * to invent a stand-in model, and a stand-in model is a second definition of what a student is.
     */
    public function attachSubject(
        ReferralSubject $type,
        int $id,
        Collaborator $collaborator,
        ReferralSource $source,
        ?string $code = null,
        ?CarbonInterface $on = null,
        ?ReferralContext $context = null,
    ): CollaboratorReferral {
        $context ??= ReferralContext::none();
        $date = $this->businessDate($context->referralDate ?? $on);

        return $this->db->transaction(function () use ($type, $id, $collaborator, $source, $code, $date, $context): CollaboratorReferral {
            $existing = $this->current($type, $id);

            if ($existing !== null) {
                throw ReferralConflictException::alreadyAttributed(
                    $type->label(),
                    $this->describe($existing->collaborator),
                    $this->describe($collaborator),
                );
            }

            try {
                $row = $this->insert($type, $id, $collaborator, $source, $code, $date, $context);
            } catch (UniqueConstraintViolationException) {
                // A racing writer took the slot between the read and the insert. Re-read rather than
                // retry: what the caller needs is who won, not another attempt at winning.
                $winner = $this->current($type, $id);

                throw ReferralConflictException::alreadyAttributed(
                    $type->label(),
                    $this->describe($winner?->collaborator),
                    $this->describe($collaborator),
                );
            }

            $this->activity->record(
                $this->attachmentEvent($type),
                $collaborator,
                $row,
                ['subject_type' => $type->value, 'subject_id' => $id, 'source' => $source->value],
            );

            return $row;
        });
    }

    /**
     * Record the candidate that lost a race, as evidence (F-4.4).
     *
     * The question this system will be asked is never "who won" — it is "why did my code not win", six
     * weeks later, by somebody whose commission depended on the answer. A loser is a real row with a
     * closed same-day window, `commission_eligible = false`, and a **mandatory** reason.
     *
     * Several losers may point at one winner, and this may run in the same transaction as
     * {@see change()}, which also writes `superseded_by_id` (ND-12) — the column carries a plain index
     * for exactly that reason.
     */
    public function recordLosingCandidate(
        CollaboratorReferral $winner,
        Collaborator $loser,
        ReferralContext $context,
        string $reason,
    ): CollaboratorReferral {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'A losing candidate is recorded so somebody can be told why their code did not win. '
                .'Without the reason the row answers nothing.');
        }

        $type = $winner->subject_type;
        $id = (int) $winner->subjectId();
        $date = $this->businessDate($context->referralDate);

        return $this->db->transaction(fn (): CollaboratorReferral => $this->insert(
            type: $type,
            id: $id,
            collaborator: $loser,
            source: $winner->referral_source,
            code: $loser->referral_code,
            date: $date,
            context: $context,
            overrides: [
                'status' => ReferralStatus::Superseded->value,
                'effective_to' => $date->toDateString(),
                'commission_eligible' => false,
                'superseded_by_id' => $winner->getKey(),
                'superseded_at' => now(),
                'change_reason' => mb_substr($reason, 0, 255),
                'changed_by' => auth()->id(),
            ],
        ));
    }

    /**
     * Move a subject to a different collaborator. **Supersedes; never mutates.**
     *
     * The old window closes today and gains a forward pointer; the new one starts today. No existing
     * ledger row is re-pointed (INV-18) — what a partner earned while they held the credit stays theirs,
     * which is the whole reason attribution is versioned.
     */
    public function change(Model|CollaboratorReferral $subject, Collaborator $new, string $reason): CollaboratorReferral
    {
        [$type, $id] = $this->locate($subject);

        return $this->changeSubject($type, $id, $new, $reason);
    }

    /**
     * {@see change()}, addressed by type and id.
     */
    public function changeSubject(ReferralSubject $type, int $id, Collaborator $new, string $reason): CollaboratorReferral
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'Changing who is credited for a subject changes who earns on every future payment. '
                .'That decision is recorded with its reason or it is not made.');
        }

        return $this->db->transaction(function () use ($type, $id, $new, $reason): CollaboratorReferral {
            $today = $this->businessDate(null);
            $old = $this->current($type, $id);

            // Re-attributing a subject to the partner who already holds it is a no-op, not a new
            // version: superseding a row with an identical copy of itself would put a meaningless
            // boundary in the middle of a window and make a back-dated receipt pick a side.
            if ($old !== null && (int) $old->collaborator_id === (int) $new->getKey()) {
                return $old;
            }

            if ($old !== null) {
                $this->close($old, ReferralStatus::Superseded, $today, $reason);
            }

            $row = $this->insert(
                type: $type,
                id: $id,
                collaborator: $new,
                source: ReferralSource::ManualSelection,
                code: $new->referral_code,
                date: $today,
                context: ReferralContext::none(),
                overrides: [
                    'previous_referral_id' => $old?->getKey(),
                    'change_reason' => mb_substr($reason, 0, 255),
                    'changed_by' => auth()->id(),
                ],
            );

            if ($old !== null) {
                CollaboratorReferral::allowDirectWrites(function () use ($old, $row): void {
                    $old->forceFill(['superseded_by_id' => $row->getKey()])->save();
                });

                // Both names on one row, so the audit trail reads as a sentence years later rather than
                // as two ids somebody has to go and look up (§37, §107).
                $this->activity->record(
                    $this->attachmentEvent($type),
                    $new,
                    $row,
                    [
                        'subject_type' => $type->value,
                        'subject_id' => $id,
                        'from_collaborator' => $this->describe($old->collaborator),
                        'to_collaborator' => $this->describe($new),
                    ],
                    $reason,
                );
            }

            return $row;
        });
    }

    /**
     * Stop future commission on a subject without touching a single past entry.
     *
     * `commission_eligible = false` and a closed window: what the partner already earned stays earned,
     * and stays visible. This is what a partner who left on good terms looks like.
     */
    public function revoke(Model|CollaboratorReferral $subject, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'Revoking an attribution stops a partner earning. The reason is what they are shown '
                .'when they ask why.');
        }

        [$type, $id] = $this->locate($subject);

        $this->db->transaction(function () use ($type, $id, $reason): void {
            $row = $this->current($type, $id);

            if ($row === null) {
                return;
            }

            $this->close($row, ReferralStatus::Revoked, $this->businessDate(null), $reason);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insert(
        ReferralSubject $type,
        int $id,
        Collaborator $collaborator,
        ReferralSource $source,
        ?string $code,
        Carbon $date,
        ReferralContext $context,
        array $overrides = [],
    ): CollaboratorReferral {
        $code = $this->codes->normalizeReferralCode((string) ($code ?? $collaborator->referral_code));

        if ($code === '') {
            throw CollaboratorRuleException::refuse('referral_code', sprintf(
                '%s has no referral code, so an attribution to them could not record which code earned '
                .'it. Every row snapshots the code, because a code that is later reissued must not '
                .'change what a past attribution says it was.',
                $this->describe($collaborator),
            ));
        }

        return CollaboratorReferral::allowDirectWrites(function () use (
            $type, $id, $collaborator, $source, $code, $date, $context, $overrides
        ): CollaboratorReferral {
            $row = new CollaboratorReferral;

            $row->forceFill(array_merge([
                'collaborator_id' => $collaborator->getKey(),
                'subject_type' => $type->value,
                $type->column() => $id,
                'commission_for' => $type->scope()->value,
                'referral_code' => $code,
                'referral_source' => $source->value,
                'referral_date' => $date->toDateString(),
                'effective_from' => $date->toDateString(),
                'effective_to' => null,
                'commission_eligible' => true,
                'status' => ReferralStatus::Active->value,
            ], $context->columns(), $overrides));

            $row->save();

            return $row->refresh();
        });
    }

    /**
     * Close a window. The only columns that move are the ones `mutableColumns()` allows, which is the
     * model's way of saying that closing a row is the only thing that ever happens to it.
     *
     * **A superseded row keeps `commission_eligible = true`, and that is the whole point.** Closing the
     * window already stops future commission: no date after `effective_to` resolves to it. What the flag
     * additionally does is stop commission on dates **inside** the window — so clearing it on a
     * supersede would mean a receipt back-dated into the previous partner's own window silently earned
     * nothing, which is precisely the re-attribution INV-18 exists to prevent. Only a revoke and a
     * losing candidate clear it, because both are statements that this partner never earns on this
     * subject at all.
     */
    private function close(CollaboratorReferral $row, ReferralStatus $status, Carbon $on, string $reason): void
    {
        $superseded = $status === ReferralStatus::Superseded;

        CollaboratorReferral::allowDirectWrites(function () use ($row, $status, $on, $reason, $superseded): void {
            $row->forceFill([
                'status' => $status->value,
                'effective_to' => $on->toDateString(),
                'commission_eligible' => $superseded,
                // Nothing superseded a revoked row, so it gets no supersede timestamp. Its closing date
                // is `effective_to`, which is the date anybody asking would mean.
                'superseded_at' => $superseded ? now() : null,
                'change_reason' => mb_substr($reason, 0, 255),
                'changed_by' => auth()->id(),
            ])->save();
        });
    }

    /**
     * @return array{0: ReferralSubject, 1: int}
     */
    private function locate(Model|CollaboratorReferral $subject): array
    {
        if ($subject instanceof CollaboratorReferral) {
            return [$subject->subject_type, (int) $subject->subjectId()];
        }

        return [$this->subjectType($subject), $this->subjectId($subject)];
    }

    private function subjectType(Model $subject): ReferralSubject
    {
        foreach (self::SUBJECTS as $class => $type) {
            if ($subject instanceof $class) {
                return $type;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%s is not something a collaborator can be credited for. The four subjects are student, '
            .'project, client and lead — `chk_cr_one_subject` makes that a database fact, so a fifth '
            .'would have nowhere to store its id.',
            $subject::class,
        ));
    }

    private function subjectId(Model $subject): int
    {
        $key = $subject->getKey();

        if (! is_int($key) && ! (is_string($key) && ctype_digit($key))) {
            throw new InvalidArgumentException('An attribution needs a persisted subject.');
        }

        return (int) $key;
    }

    /**
     * §60's eleven have one event per side, and a client or a lead is the project side's beginning.
     */
    private function attachmentEvent(ReferralSubject $type): CollaboratorActivityEvent
    {
        return $type === ReferralSubject::Student
            ? CollaboratorActivityEvent::StudentReferral
            : CollaboratorActivityEvent::ProjectReferral;
    }

    /**
     * Business dates are wall-clock dates in the configured timezone (D61): a receipt entered at 1 a.m.
     * in Karachi belongs to that day, not to the previous UTC one.
     */
    private function businessDate(?CarbonInterface $date): Carbon
    {
        if ($date !== null) {
            return Carbon::instance($date->toDateTime())->startOfDay();
        }

        return Carbon::now(Format::timezone())->startOfDay();
    }

    private function describe(?Collaborator $collaborator): string
    {
        if ($collaborator === null) {
            return 'another collaborator';
        }

        return trim(sprintf('%s (%s)', (string) $collaborator->name, (string) $collaborator->collaborator_code));
    }
}
