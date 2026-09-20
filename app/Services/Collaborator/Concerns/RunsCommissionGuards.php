<?php

declare(strict_types=1);

namespace App\Services\Collaborator\Concerns;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Collaborator\CommissionOutcome;
use App\DataObjects\Collaborator\EntitlementContext;
use App\DataObjects\Collaborator\LedgerEntryDraft;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CollaboratorStatus;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\FixedCommissionRelease;
use App\Enums\LedgerEntryPurpose;
use App\Enums\LedgerEntryType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Project\Project;
use App\Services\Collaborator\CommissionEngineContext;
use App\Support\Collaborator\CommissionSettings;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The guard sequence and the calculation, written **once** (spine §6.1, phase-10-12 §6.2).
 *
 * `StudentCommissionService` and `ProjectCommissionService` run exactly these steps in exactly this
 * order. Only G10 differs by scope, and it is the one hook the two engines actually override with
 * different logic — everything else here is shared code rather than two parallel implementations that
 * agree today.
 *
 * **The first failing guard returns immediately, and writes one of two things.** A G0 replay writes
 * nothing at all. Everything else writes a skip reason and a human sentence on the payment row, plus
 * exactly one activity entry — and **never a ledger row, not even a zero one** (INV-2).
 *
 * **Every amount is `App\Support\Money`.** Not one `+`, `*` or `round()` appears below: the whole point
 * of the class is that a commission is the same number on every machine that computes it.
 */
trait RunsCommissionGuards
{
    /*
    |--------------------------------------------------------------------------
    | What each engine supplies
    |--------------------------------------------------------------------------
    */

    abstract protected function engine(): CommissionEngineContext;

    abstract protected function scope(): CommissionScope;

    abstract protected function purpose(): LedgerEntryPurpose;

    /** G4: the attribution effective on this payment's **value date**. */
    abstract protected function referralFor(Model $payment): ?CollaboratorReferral;

    /** The project a per-project rule override could come from. Null on the student side. */
    abstract protected function ruleProject(Model $payment): ?Project;

    /**
     * G10 — the one step that genuinely differs. Returns `[reason, detail]` or null.
     *
     * @return array{0: CommissionSkipReason, 1: string}|null
     */
    abstract protected function scopeGuard(Model $payment, RuleResolution $rule, CommissionSettings $settings): ?array;

    /** C2. */
    abstract protected function resolveDocument(Model $payment, CommissionBase $base, RuleResolution $rule): BaseResolution;

    abstract protected function sourceTypeFor(Model $payment): CommissionSourceType;

    /** @return array<string, int|null> */
    abstract protected function subjectColumnsFor(Model $payment): array;

    /** `student_fee_payment_id` or `project_payment_id` on the ledger row. */
    abstract protected function paymentColumn(): string;

    /*
    |--------------------------------------------------------------------------
    | The sequence
    |--------------------------------------------------------------------------
    */

    /**
     * Run G0-G11 and C1-C8 against one persisted payment, inside one transaction.
     *
     * Idempotent under unlimited replay: G0 catches a settled payment, and `uq_cle_dedupe` catches
     * anything that gets past it. `$force` bypasses **G2 only** — the audited manual re-evaluation of
     * §6.6 row 1, and nothing else ([D-IMP-4]).
     *
     * **`$force` does not bypass G0**, and that is deliberate. A skip is a decision, not a failure
     * (§6.2), so re-evaluating one means first undoing the decision: `commissions:evaluate` clears
     * `commission_state` back to `queued` under the permission that lets it, and only then calls this.
     * A `$force` that quietly reached past G0 would make the sweeper able to do the same thing by
     * accident, and a reinstated collaborator would be back-paid without anybody asking for it.
     *
     * The model passed in goes stale: the payment is re-read under a lock inside the transaction and
     * the stamp is written to *that* instance. A caller that needs the outcome on its own copy
     * refreshes it.
     */
    protected function process(Model $payment, bool $force = false): CommissionOutcome
    {
        $settings = CommissionSettings::capture();

        return $this->engine()->db->transaction(function () use ($payment, $force, $settings): CommissionOutcome {
            // The payment row is the first lock in the spine's fixed order
            // (payment -> document -> entitlement -> wallet -> ledger).
            $payment = $payment->newQuery()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            $outcome = $this->evaluate($payment, $settings, $force);

            $this->stamp($payment, $outcome);

            return $outcome;
        }, 3);
    }

    private function evaluate(Model $payment, CommissionSettings $settings, bool $force): CommissionOutcome
    {
        // ---- G0 ------------------------------------------------------------------------------
        if ($payment->commission_state->isSettled()) {
            return CommissionOutcome::alreadyDone($payment->commission_state);
        }

        // ---- G1 / G2 -------------------------------------------------------------------------
        if (! $settings->referralSystemEnabled) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::ReferralSystemDisabled,
                'The referral system is switched off, so no payment earns commission while it stays off.',
                'G1',
            );
        }

        if (! $settings->automaticCommissionEnabled && ! $force) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::AutomaticCommissionDisabled,
                'Automatic commission is switched off. This receipt can still be evaluated by hand from '
                .'the commissions screen, which records who asked for it.',
                'G2',
            );
        }

        // ---- G3 ------------------------------------------------------------------------------
        // A fully refunded receipt still earns; its reversal posts the offset. That is what makes the
        // order of the two jobs irrelevant, and it is why `refunded` is on the allowed list.
        if (! $payment->status->earnsCommission()) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::PaymentNotCleared,
                sprintf('The receipt is %s, so no money was received against it.', $payment->status->label()),
                'G3',
            );
        }

        // ---- G4 / G5 -------------------------------------------------------------------------
        $referral = $this->referralFor($payment);

        if ($referral === null) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::NoReferral,
                sprintf('Nobody was credited for this on %s, so there is no partner to pay.',
                    $this->valueDate($payment)->toDateString()),
                'G4',
            );
        }

        if (! $referral->commission_eligible) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::ReferralNotCommissionEligible,
                sprintf('The attribution to %s is marked as earning no commission%s.',
                    $this->describe($referral->collaborator),
                    $referral->change_reason === null ? '' : ': '.$referral->change_reason),
                'G5',
            );
        }

        // ---- G6 / G7 -------------------------------------------------------------------------
        // `withTrashed()`: a soft-deleted partner has to be **named** in the skip reason, and a plain
        // read would return null and produce "no referral", which is a different and misleading answer.
        $collaborator = Collaborator::withTrashed()->whereKey($referral->collaborator_id)->first();

        if ($collaborator === null || $collaborator->trashed()) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::CollaboratorDeleted,
                sprintf('%s has been deleted.', $this->describe($collaborator)),
                'G6',
            );
        }

        if ($collaborator->status !== CollaboratorStatus::Active) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::CollaboratorInactive,
                sprintf('%s is %s%s.',
                    $this->describe($collaborator),
                    strtolower($collaborator->status->label()),
                    $collaborator->status_changed_at === null
                        ? ''
                        : ' since '.$collaborator->status_changed_at->toDateString()),
                'G7',
            );
        }

        // ---- G8 / G9 -------------------------------------------------------------------------
        $rule = $this->engine()->rules->resolve(
            $collaborator,
            $this->scope(),
            $this->valueDate($payment),
            $this->ruleProject($payment),
        );

        if ($rule === null || ! $rule->isUsable()) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::NoEffectiveRule,
                sprintf('No %s commission rule covers %s for %s. There is deliberately no global '
                    .'fallback rate: a partner nobody configured is not paid by accident.',
                    $this->scope()->value,
                    $this->valueDate($payment)->toDateString(),
                    $this->describe($collaborator)),
                'G8',
            );
        }

        if (! $rule->isEnabled) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::CommissionDisabled,
                sprintf('%s commission is switched off for %s by their own rule.',
                    ucfirst($this->scope()->value), $this->describe($collaborator)),
                'G9',
            );
        }

        // ---- G10 -----------------------------------------------------------------------------
        $scopeFailure = $this->scopeGuard($payment, $rule, $settings);

        if ($scopeFailure !== null) {
            return CommissionOutcome::skipped($scopeFailure[0], $scopeFailure[1], 'G10');
        }

        // ---- G11 -----------------------------------------------------------------------------
        if (! $rule->meetsMinimumPayment((string) $payment->amount)) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::BelowMinimumPayment,
                sprintf('%s is below the %s minimum this rule sets for a payment to earn.',
                    Money::format((string) $payment->amount),
                    Money::format((string) $rule->minPaymentAmount)),
                'G11',
            );
        }

        return $this->compute($payment, $settings, $referral, $collaborator, $rule);
    }

    /*
    |--------------------------------------------------------------------------
    | The calculation (spine §6.1.3 - §6.1.10)
    |--------------------------------------------------------------------------
    */

    private function compute(
        Model $payment,
        CommissionSettings $settings,
        CollaboratorReferral $referral,
        Collaborator $collaborator,
        RuleResolution $rule,
    ): CommissionOutcome {
        // ---- C1: the base, and the fallback that never blocks money -------------------------
        $configured = $rule->base;

        if (! $configured->appliesTo($this->scope())) {
            // A misconfiguration — a project base saved against a student rule, say. The requirement's
            // default is used, the fact is recorded in the snapshot, and a warning is logged. Refusing
            // to pay would punish the partner for somebody else's dropdown.
            $rule = $rule->withBase(CommissionBase::Paid, true);
            $this->warnAboutBase($payment, $collaborator, $configured);
        }

        // ---- C2: the document and its two figures --------------------------------------------
        $document = $this->resolveDocument($payment, $rule->base, $rule);

        // ---- C3: the promise, locked ----------------------------------------------------------
        $entitlement = $this->engine()->entitlements->openOrLoad(new EntitlementContext(
            collaborator: $collaborator,
            referral: $referral,
            rule: $rule,
            document: $document,
            scope: $this->scope(),
            settings: $settings,
        ));

        // ---- C4: what of this payment is commissionable ---------------------------------------
        $paymentAmount = Money::of((string) $payment->amount);
        $remaining = $document->remainingCollectible((string) $entitlement->collected_amount);

        $baseAmount = $settings->commissionOnOverpayment
            ? $paymentAmount
            : Money::min($paymentAmount, $remaining);

        if (Money::isZero($baseAmount)) {
            return Money::isPositive($paymentAmount)
                ? CommissionOutcome::skipped(
                    CommissionSkipReason::OverpaymentOnly,
                    sprintf('The %s on this document was already collected in full, so this %s is an '
                        .'overpayment and earns nothing.',
                        Money::format($document->collectibleAmount), Money::format($paymentAmount)),
                    'C4',
                )
                : CommissionOutcome::skipped(
                    CommissionSkipReason::BaseZero,
                    'The receipt is for nothing, so there is nothing to earn on.',
                    'C4',
                );
        }

        // ---- C5: the release, one of four branches --------------------------------------------
        $releasedBefore = (string) $entitlement->released_amount;
        $promise = $entitlement->entitlement_amount === null ? null : (string) $entitlement->entitlement_amount;

        $branch = $this->branchFor($entitlement, $rule);
        $trace = [];

        if ($branch === 'A' || $branch === 'B') {
            // Per-receipt methods. Each commissionable receipt earns on its own; a cap, if there is
            // one, is applied by the clamp below rather than by changing the arithmetic.
            $release = $branch === 'B'
                ? Money::of((string) $rule->fixedAmount)
                : Money::percentage($baseAmount, (string) $rule->rate);
        } elseif ($branch === 'D') {
            // D: the whole promise on the first payment that reaches here.
            $release = Money::sub((string) $promise, $releasedBefore);
        } else {
            // C: the cumulative target. The only method that sums to the promise **exactly**, with the
            // final receipt absorbing the rounding residual, and the only one that cannot be inflated
            // by splitting a fee into twelve installments ([D-FS-10]).
            if (Money::isZero($document->collectibleAmount)) {
                return CommissionOutcome::skipped(
                    CommissionSkipReason::NoCollectibleDenominator,
                    'The document has nothing collectible against it, so a proportional release has '
                    .'nothing to be a proportion of.',
                    'C5',
                );
            }

            $collectedAfter = Money::add((string) $entitlement->collected_amount, $baseAmount);

            // `prorate()` rather than `mul()` then `div()`: the two-step form quantises the product to
            // the paisa before dividing, and a share computed in two roundings drifts from one computed
            // in one. This is the method whose whole promise is that the slices sum to the promise
            // exactly, so it uses the single-rounding multiply-then-divide the money class publishes.
            $target = Money::prorate((string) $promise, $collectedAfter, $document->collectibleAmount);
            $release = Money::sub($target, $releasedBefore);

            $trace['collected_after'] = $collectedAfter;
            $trace['target'] = $target;
        }

        // Always: clamp to what is left of the promise, and never below zero.
        if ($promise !== null) {
            $release = Money::min($release, Money::sub($promise, $releasedBefore));
        }

        $release = Money::max(Money::ZERO, $release);

        if (Money::isZero($release) && $promise !== null
            && Money::compare($releasedBefore, $promise) >= 0) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::EntitlementCapReached,
                sprintf('The %s promised on this document has already been released in full.',
                    Money::format($promise)),
                'C5',
            );
        }

        // ---- C6 / C7 ---------------------------------------------------------------------------
        if (Money::isZero($release)) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::RoundsToZero,
                sprintf('%s of %s rounds to nothing at the paisa.',
                    $this->rateSentence($rule), Money::format($baseAmount)),
                'C6',
            );
        }

        if (Money::compare($release, $settings->minEntryAmount) < 0) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::BelowMinimumCommission,
                sprintf('%s is below the %s minimum the business sets for a ledger entry.',
                    Money::format($release), Money::format($settings->minEntryAmount)),
                'C7',
            );
        }

        // ---- C8 ---------------------------------------------------------------------------------
        return $this->post(
            $payment, $settings, $referral, $collaborator, $rule, $document, $entitlement,
            $baseAmount, $release, $releasedBefore, $promise,
            array_merge($trace, [
                'branch' => $branch,
                'document_base_amount' => $document->documentBaseAmount,
                'collectible_amount' => $document->collectibleAmount,
                'collected_before' => (string) $entitlement->collected_amount,
                'base_amount' => $baseAmount,
                'entitlement_amount' => $promise,
                'released_before' => $releasedBefore,
                'release' => $release,
                'base_fallback' => $rule->baseFallback,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $trace
     */
    private function post(
        Model $payment,
        CommissionSettings $settings,
        CollaboratorReferral $referral,
        Collaborator $collaborator,
        RuleResolution $rule,
        BaseResolution $document,
        CollaboratorCommissionEntitlement $entitlement,
        string $baseAmount,
        string $release,
        string $releasedBefore,
        ?string $promise,
        array $trace,
    ): CommissionOutcome {
        $transactionDate = $this->valueDate($payment);
        [$status, $holdUntil] = $this->statusAtPosting($entitlement, $transactionDate);

        $draft = new LedgerEntryDraft(
            collaborator: $collaborator,
            purpose: $this->purpose(),
            entryType: LedgerEntryType::Credit,
            sourceType: $this->sourceTypeFor($payment),
            sourceId: (int) $payment->getKey(),
            amount: $release,
            baseAmount: $baseAmount,
            grossAmount: (string) $payment->amount,
            base: $document->base,
            calculationType: $rule->calculationType,
            status: $status,
            approvalMode: $entitlement->approval_mode,
            transactionDate: $transactionDate,
            ruleSnapshot: [
                'rule' => $rule->snapshot(),
                'document' => $document->snapshot(),
                'settings' => $settings->snapshot(),
                'calculation' => $trace,
                'entitlement_id' => (int) $entitlement->getKey(),
            ],
            entitlement: $entitlement,
            referral: $referral,
            commissionSettingId: $rule->ruleId(),
            ruleSource: $rule->source,
            commissionRate: $rule->rate,
            fixedAmount: $rule->fixedAmount,
            entitlementTotal: $promise,
            releasedBefore: $releasedBefore,
            studentFeePaymentId: $this->paymentColumn() === 'student_fee_payment_id' ? (int) $payment->getKey() : null,
            projectPaymentId: $this->paymentColumn() === 'project_payment_id' ? (int) $payment->getKey() : null,
            subjectColumns: $this->subjectColumnsFor($payment),
            holdUntil: $holdUntil,
        );

        $result = $this->engine()->ledger->post($draft);

        // A replay that found the row already there must not advance the promise a second time. The
        // entitlement's running totals were written in the transaction that created the entry, and
        // `uq_cle_dedupe` is what tells us which transaction that was.
        if ($result->created) {
            $this->engine()->entitlements->release($entitlement, $release, $baseAmount);
        }

        return CommissionOutcome::posted($result->entry, $result->created);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Which of spine §6.1.7's four branches applies. Named rather than inferred at each use, because
     * the branch is written into the trace and a screen shows it.
     */
    private function branchFor(CollaboratorCommissionEntitlement $entitlement, RuleResolution $rule): string
    {
        // **The method decides the branch, not whether a cap exists.** A `paid`-base percentage with a
        // `max_commission_amount` has an `entitlement_amount`, but that figure is a ceiling rather than
        // a promise: the rule still says "10 % of each receipt", and the cap is applied by the clamp
        // after the branch has done its work. Branching on `entitlement_amount === null` alone would
        // turn every capped partner into a prorated one and change what they earn on every receipt but
        // the last.
        if ($this->engine()->entitlements->isPerPaymentMethod($rule->calculationType, $rule->base, $rule->release)) {
            return $rule->calculationType === CommissionCalculationType::Fixed ? 'B' : 'A';
        }

        return $rule->release === FixedCommissionRelease::OnFirstPayment ? 'D' : 'C';
    }

    /**
     * Spine §6.1.8, from the **snapshotted** mode and hold days — never from today's settings, which is
     * what stops a business changing its approval policy and retroactively un-approving last month.
     *
     * @return array{0: CommissionStatus, 1: Carbon|null}
     */
    private function statusAtPosting(CollaboratorCommissionEntitlement $entitlement, Carbon $transactionDate): array
    {
        if ($entitlement->approval_mode->initialStatus() === CommissionStatus::Pending) {
            return [CommissionStatus::Pending, null];
        }

        $holdDays = (int) $entitlement->hold_days;

        return $holdDays > 0
            ? [CommissionStatus::Approved, $transactionDate->copy()->addDays($holdDays)]
            : [CommissionStatus::Available, null];
    }

    /**
     * Write the outcome onto the payment row — and write **nothing** for a G0 replay.
     */
    private function stamp(Model $payment, CommissionOutcome $outcome): void
    {
        if ($outcome->silent) {
            return;
        }

        $attributes = [
            'commission_state' => $outcome->state->value,
            'commission_skip_reason' => $outcome->reason?->value,
            'commission_skip_detail' => $outcome->detail,
            'commission_attempts' => (int) $payment->commission_attempts + 1,
            'commission_processed_at' => now(),
        ];

        // The partner is stamped whenever resolution got far enough to name one, so a skip is still
        // traceable to somebody — "COL-1024 was suspended" is only useful if the receipt says COL-1024.
        if ($outcome->entry !== null) {
            $attributes['collaborator_id'] = $outcome->entry->collaborator_id;
            $attributes['collaborator_referral_id'] = $outcome->entry->collaborator_referral_id;
        }

        $payment::allowDirectWrites(function () use ($payment, $attributes): void {
            $payment->forceFill($attributes)->save();
        });

        if ($outcome->isSkipped()) {
            $this->logSkip($payment, $outcome);
        }
    }

    /**
     * Exactly one `commission.skipped` row per skip, on the payment (spine §6.2).
     */
    private function logSkip(Model $payment, CommissionOutcome $outcome): void
    {
        activity()
            ->performedOn($payment)
            ->withProperties([
                'step' => $outcome->step,
                'reason' => $outcome->reason?->value,
                'detail' => $outcome->detail,
                'collaborator_id' => $payment->collaborator_id,
            ])
            ->event('commission.skipped')
            ->log($outcome->sentence() ?? 'No commission was earned.')
            ->forceFill(['module' => 'collaborator_commissions'])
            ->save();
    }

    /**
     * C1's warning. A configuration mistake that money routed around is still a mistake somebody has to
     * be able to find afterwards.
     */
    private function warnAboutBase(Model $payment, Collaborator $collaborator, CommissionBase $configured): void
    {
        activity()
            ->performedOn($payment)
            ->withProperties([
                'configured_base' => $configured->value,
                'used_base' => CommissionBase::Paid->value,
                'scope' => $this->scope()->value,
                'collaborator_id' => $collaborator->getKey(),
            ])
            ->event('commission.base_fallback')
            ->log(sprintf(
                'The %s base does not apply to %s commission, so this entry used `paid`. The rule or '
                .'the setting needs correcting; the money was not held up for it.',
                $configured->label(),
                $this->scope()->value,
            ))
            ->forceFill(['module' => 'collaborator_commissions'])
            ->save();
    }

    /**
     * The **value date** — INV-16. Never `now()`: a receipt back-dated into a previous partner's window
     * credits that partner, at the rate that was in force then.
     */
    private function valueDate(Model $payment): Carbon
    {
        return Carbon::parse($payment->paid_on->toDateString(), Format::timezone())->startOfDay();
    }

    private function rateSentence(RuleResolution $rule): string
    {
        return $rule->calculationType === CommissionCalculationType::Fixed
            ? Money::format((string) $rule->fixedAmount)
            : rtrim(rtrim((string) $rule->rate, '0'), '.').'%';
    }

    private function describe(?Collaborator $collaborator): string
    {
        if ($collaborator === null) {
            return 'The collaborator';
        }

        return trim(sprintf('%s (%s)', (string) $collaborator->name, (string) $collaborator->collaborator_code));
    }
}
