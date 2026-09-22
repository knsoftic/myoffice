<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Finance\CommissionPreview;
use App\DataObjects\Finance\PaymentResult;
use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CollaboratorStatus;
use App\Enums\CommissionBase;
use App\Enums\CommissionProcessingState;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\InstallmentStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReferralSubject;
use App\Enums\ReversalApprovalStatus;
use App\Enums\ReversalType;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Events\Finance\PaymentReversalApproved;
use App\Events\Finance\PaymentReversalRecorded;
use App\Events\Finance\PaymentReversalRejected;
use App\Events\Finance\ProjectPaymentRecorded;
use App\Events\Finance\StudentFeePaymentRecorded;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Models\Finance\PaymentReversal;
use App\Models\Finance\ProjectPayment;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeePayment;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
use App\Services\Collaborator\CommissionBaseResolver;
use App\Services\Collaborator\CommissionCalculator;
use App\Services\Collaborator\CommissionEntitlementService;
use App\Services\Collaborator\CommissionRuleService;
use App\Services\Collaborator\ReferralService;
use App\Services\Finance\Exceptions\PaymentRuleException;
use App\Services\Institute\StudentFeeService;
use App\Support\Collaborator\CommissionSettings;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * **The only writer of the three payment tables** ([D-IMP-2], spine §2.5-§2.7, phase-10-12 §6.3).
 *
 * A receipt is not one row. It is a number taken under a lock, an attribution snapshotted from the
 * **value date**, four caches on the charge recomputed, an installment line allocated without
 * overshooting its remainder, and a commission queued after commit and never inline. A row inserted
 * anywhere else has none of that and looks completely normal in the table, which is why the model
 * refuses the insert outright rather than trusting a convention.
 *
 * **No commission work happens here.** The engine is reached through an event dispatched after commit
 * (INV-20): a cashier's receipt must not wait on it, a rolled-back receipt must never earn anybody
 * anything, and a queue outage degrades to "commission pending" rather than to a failed receipt for
 * money that is already in the drawer.
 *
 * Phase 10 ships the student side. Phase 11 adds `recordProjectPayment()` to **this** class — the
 * refund, void and approval paths below already take either payment, because a reversal is the same
 * act whichever table the money came from.
 */
final class PaymentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberService $numbers,
        private readonly ReferralService $referrals,
        private readonly CommissionRuleService $rules,
        private readonly CommissionBaseResolver $bases,
        private readonly CommissionEntitlementService $entitlements,
        private readonly CommissionCalculator $calculator,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Taking money
    |--------------------------------------------------------------------------
    */

    /**
     * Record one student receipt.
     *
     * Idempotent on `idempotency_key`: a double-clicked Save, a browser retry and a redelivered API
     * call all produce **one** receipt, and the replays get `created: false` with the row that exists.
     */
    public function recordStudentFeePayment(StudentFee $fee, RecordPaymentData $data): PaymentResult
    {
        $key = $data->key();
        $existing = StudentFeePayment::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return new PaymentResult($existing, false);
        }

        try {
            $payment = $this->insertStudentReceipt($fee, $data, $key);
        } catch (UniqueConstraintViolationException) {
            // Two submissions of the same modal reached the insert together. `uq_sfp_idem` let exactly
            // one through, which is the guard working — the loser returns the winner's receipt rather
            // than an error about a receipt that exists and is perfectly good.
            $winner = StudentFeePayment::query()->where('idempotency_key', $key)->first();

            if ($winner === null) {
                throw PaymentRuleException::refuse('amount',
                    'This receipt collided with another one on a unique column — a receipt number or a '
                    .'gateway transaction id that is already in use. Nothing was written.');
            }

            return new PaymentResult($winner, false);
        }

        StudentFeePaymentRecorded::dispatch($payment);

        return new PaymentResult($payment, true);
    }

    private function insertStudentReceipt(StudentFee $fee, RecordPaymentData $data, string $key): StudentFeePayment
    {
        return $this->db->transaction(function () use ($fee, $data, $key): StudentFeePayment {
            /** @var StudentFee $charge */
            $charge = StudentFee::query()->whereKey($fee->getKey())->lockForUpdate()->firstOrFail();

            $paidOn = $this->assertValueDate($data->paidOn, 'student_fee_payments.approve');
            $installment = $this->lockInstallment($charge, $data->installmentId);
            $amount = $data->amount();

            $this->assertChargeIsOpen($charge);
            $this->assertNotADuplicate($charge, $data, $amount, $paidOn);

            $referral = $this->referrals->effectiveOnSubject(
                ReferralSubject::Student,
                (int) $charge->student_id,
                $paidOn,
            );

            $payment = StudentFeePayment::allowDirectWrites(function () use (
                $charge, $data, $key, $amount, $paidOn, $installment, $referral
            ): StudentFeePayment {
                $row = new StudentFeePayment;

                $row->forceFill([
                    'receipt_no' => $this->numbers->next('institute.fee_receipt_prefix', 'institute.fee_receipt_next_number', '%06d'),
                    'idempotency_key' => $key,
                    'duplicate_fingerprint' => $this->fingerprint($charge, $data, $amount, $paidOn),
                    'student_fee_id' => $charge->getKey(),
                    'student_fee_installment_id' => $installment?->getKey(),
                    'student_id' => $charge->student_id,
                    'branch_id' => $data->branchId ?? $charge->branch_id,
                    'amount' => $amount,
                    'payment_method' => $data->method->value,
                    'payment_method_id' => $data->paymentMethodId,
                    'reference_no' => $data->referenceNo,
                    'gateway_txn_id' => $data->gatewayTxnId,
                    'paid_on' => $paidOn->toDateString(),
                    'recorded_at' => now(),
                    'status' => ReceivedPaymentStatus::Cleared->value,
                    // Snapshotted from the **value date**, so a back-dated receipt credits whoever held
                    // the attribution then. The engine resolves it again for itself; this is the record
                    // of what was true when the money was taken.
                    'collaborator_id' => $referral?->collaborator_id,
                    'collaborator_referral_id' => $referral?->getKey(),
                    'commission_state' => CommissionProcessingState::Queued->value,
                    'received_by' => $data->receivedBy ?? auth()->id(),
                    'received_by_name' => $data->receivedByName ?? auth()->user()?->name,
                    'notes' => $data->notes === null ? null : mb_substr($data->notes, 0, 255),
                ])->save();

                return $row->refresh();
            });

            $this->allocate($installment, $charge);
            $this->recomputeCharge($charge);

            return $payment;
        }, 3);
    }

    /**
     * Record one client payment against a project (phase-11).
     *
     * The same shape as the student side, and deliberately the same method on the same class: a
     * refund, a void and an approval are the same act whichever table the money came from, and
     * splitting the two sides across two services would mean two implementations of "recompute under
     * the row lock and snapshot the attribution" that agree only until somebody edits one.
     *
     * `is_advance` is derived, not asked for: a payment with no invoice behind it **is** an advance,
     * and a boolean somebody ticks is a boolean somebody eventually forgets to tick.
     */
    public function recordProjectPayment(Project $project, RecordPaymentData $data): PaymentResult
    {
        $key = $data->key();
        $existing = ProjectPayment::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return new PaymentResult($existing, false);
        }

        try {
            $payment = $this->insertProjectPayment($project, $data, $key);
        } catch (UniqueConstraintViolationException) {
            $winner = ProjectPayment::query()->where('idempotency_key', $key)->first();

            if ($winner === null) {
                throw PaymentRuleException::refuse('amount',
                    'This payment collided on a unique column - a payment number or a gateway '
                    .'transaction id already in use. Nothing was written.');
            }

            return new PaymentResult($winner, false);
        }

        ProjectPaymentRecorded::dispatch($payment);

        return new PaymentResult($payment, true);
    }

    private function insertProjectPayment(Project $project, RecordPaymentData $data, string $key): ProjectPayment
    {
        return $this->db->transaction(function () use ($project, $data, $key): ProjectPayment {
            /** @var Project $locked */
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            $paidOn = $this->assertValueDate($data->paidOn, 'project_payments.approve');
            $amount = $data->amount();
            $milestoneId = $this->assertMilestoneBelongsToProject($locked, $data->milestoneId);

            $referral = $this->referrals->effectiveOnSubject(
                ReferralSubject::Project,
                (int) $locked->getKey(),
                $paidOn,
            );

            return ProjectPayment::allowDirectWrites(function () use (
                $locked, $data, $key, $amount, $paidOn, $milestoneId, $referral
            ): ProjectPayment {
                $row = new ProjectPayment;

                $row->forceFill([
                    'payment_no' => $this->numbers->next('finance.project_payment_prefix', 'finance.project_payment_next_number', '%06d'),
                    'idempotency_key' => $key,
                    'duplicate_fingerprint' => sha1(implode('|', [
                        (string) $locked->getKey(),
                        (string) ($milestoneId ?? ''),
                        $amount,
                        $paidOn->toDateString(),
                        $data->method->value,
                        (string) ($data->referenceNo ?? ''),
                    ])),
                    'project_id' => $locked->getKey(),
                    // Denormalised from the project rather than taken from the form: a client id a
                    // caller supplies is a client id a caller can get wrong, and every panel query
                    // scopes on this column.
                    'client_id' => $locked->client_id,
                    'project_milestone_id' => $milestoneId,
                    'invoice_id' => $data->invoiceId,
                    'amount' => $amount,
                    'is_advance' => $data->invoiceId === null,
                    'payment_method' => $data->method->value,
                    'payment_method_id' => $data->paymentMethodId,
                    'reference_no' => $data->referenceNo,
                    'gateway_txn_id' => $data->gatewayTxnId,
                    'paid_on' => $paidOn->toDateString(),
                    'recorded_at' => now(),
                    'status' => ReceivedPaymentStatus::Cleared->value,
                    'collaborator_id' => $referral?->collaborator_id,
                    'collaborator_referral_id' => $referral?->getKey(),
                    'commission_state' => CommissionProcessingState::Queued->value,
                    'received_by' => $data->receivedBy ?? auth()->id(),
                    'received_by_name' => $data->receivedByName ?? auth()->user()?->name,
                    'notes' => $data->notes === null ? null : mb_substr($data->notes, 0, 255),
                ])->save();

                return $row->refresh();
            });
        }, 3);
    }

    /**
     * A milestone belongs to the project being paid, or the payment is refused.
     *
     * Allocating a payment to another project's milestone would make both projects wrong - one shows
     * money it never received, the other a milestone settled by somebody else's client.
     */
    private function assertMilestoneBelongsToProject(Project $project, ?int $milestoneId): ?int
    {
        if ($milestoneId === null) {
            return null;
        }

        $belongs = ProjectMilestone::query()
            ->whereKey($milestoneId)
            ->where('project_id', $project->getKey())
            ->exists();

        if (! $belongs) {
            throw PaymentRuleException::refuse('project_milestone_id',
                'That milestone belongs to a different project.');
        }

        return $milestoneId;
    }

    /*
    |--------------------------------------------------------------------------
    | Giving it back
    |--------------------------------------------------------------------------
    */

    /**
     * Return part or all of a receipt.
     *
     * The ceiling is **recomputed under the payment's row lock** (INV-9) rather than read from the
     * model the caller is holding: two refunds submitted in the same second would otherwise each see
     * the same remainder and together exceed the receipt. `chk_*_refund_ceiling` is the database's
     * backstop behind that, and the conditional UPDATE below is what means it never has to fire.
     */
    public function refund(StudentFeePayment|ProjectPayment $payment, RefundData $data): PaymentReversal
    {
        $key = $data->key();
        $existing = PaymentReversal::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $reversal = $this->insertReversal($payment, $data, $key);
        } catch (UniqueConstraintViolationException) {
            $winner = PaymentReversal::query()->where('idempotency_key', $key)->first();

            if ($winner === null) {
                throw PaymentRuleException::refuse('amount',
                    'This reversal collided on a unique column. Nothing was written.');
            }

            return $winner;
        }

        PaymentReversalRecorded::dispatch($reversal);

        return $reversal;
    }

    private function insertReversal(StudentFeePayment|ProjectPayment $payment, RefundData $data, string $key): PaymentReversal
    {
        return $this->db->transaction(function () use ($payment, $data, $key): PaymentReversal {
            $locked = $payment->newQuery()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $amount = $data->amount();
            $remaining = $locked->refundableRemaining();

            if (Money::compare($amount, $remaining) === 1) {
                throw PaymentRuleException::refuse('amount', sprintf(
                    'Only %s of receipt %s is still refundable — %s has already gone back. The figure '
                    .'was re-read under the row lock, so a refund somebody else submitted a moment ago '
                    .'is already counted.',
                    Money::format($remaining),
                    $this->numberOf($locked),
                    Money::format((string) $locked->refunded_amount),
                ));
            }

            if (! $locked->status->earnsCommission() && $locked->status !== ReceivedPaymentStatus::Cleared) {
                throw PaymentRuleException::refuse('payment', sprintf(
                    'Receipt %s is %s. There is nothing left to return.',
                    $this->numberOf($locked),
                    $locked->status->label(),
                ));
            }

            $isPartial = Money::compare($amount, $remaining) === -1;
            $approval = $this->approvalFor($amount);

            $reversal = PaymentReversal::allowDirectWrites(function () use ($locked, $data, $key, $amount, $approval): PaymentReversal {
                $row = new PaymentReversal;

                $row->forceFill([
                    'reversal_no' => $this->numbers->next('finance.payment_reversal_prefix', 'finance.payment_reversal_next_number', '%06d'),
                    'idempotency_key' => $key,
                    'student_fee_payment_id' => $locked instanceof StudentFeePayment ? $locked->getKey() : null,
                    'project_payment_id' => $locked instanceof ProjectPayment ? $locked->getKey() : null,
                    'type' => $data->type->value,
                    'amount' => $amount,
                    'reason' => $data->reason,
                    'refund_method' => $data->method,
                    'reference_no' => $data->referenceNo,
                    'occurred_on' => ($data->refundedOn === null
                        ? Carbon::now(Format::timezone())
                        : Carbon::instance($data->refundedOn->toDateTime()))->toDateString(),
                    'recorded_at' => now(),
                    'approval_status' => $approval->value,
                    'performed_by' => auth()->id(),
                    'performed_by_name' => auth()->user()?->name ?? 'System',
                    'commission_state' => CommissionProcessingState::Queued->value,
                    'notes' => $data->notes === null ? null : mb_substr($data->notes, 0, 255),
                ])->save();

                return $row->refresh();
            });

            $this->applyRefundToPayment($locked, $amount, $data->type, $isPartial);

            return $reversal;
        }, 3);
    }

    /**
     * Cancel a mis-keyed receipt in full.
     *
     * **The only "edit" a receipt has** (INV-8). The corrected receipt is entered fresh with a new
     * idempotency key, so the trail reads: this one was taken, this one was voided and why, this one
     * replaced it. Three rows, one reversed commission, and nothing rewritten.
     */
    public function void(StudentFeePayment|ProjectPayment $payment, string $reason): PaymentReversal
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PaymentRuleException::refuse('reason',
                'Voiding a receipt removes money the books say arrived. The reason is what the trail '
                .'shows in its place.');
        }

        return $this->refund($payment, new RefundData(
            amount: $payment->refundableRemaining(),
            reason: $reason,
            type: ReversalType::Void,
            idempotencyKey: 'void:'.$this->tableToken($payment).':'.$payment->getKey(),
        ));
    }

    /**
     * Approve a reversal that needed approval. **This is what releases the commission reversal.**
     */
    public function approveReversal(PaymentReversal $reversal, User $by): PaymentReversal
    {
        $approved = $this->db->transaction(function () use ($reversal, $by): PaymentReversal {
            /** @var PaymentReversal $locked */
            $locked = PaymentReversal::query()->whereKey($reversal->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== ReversalApprovalStatus::Pending) {
                throw PaymentRuleException::refuse('approval_status', sprintf(
                    'Reversal %s is %s, so there is nothing to approve.',
                    (string) $locked->reversal_no,
                    $locked->approval_status->label(),
                ));
            }

            PaymentReversal::allowDirectWrites(function () use ($locked, $by): void {
                $locked->forceFill([
                    'approval_status' => ReversalApprovalStatus::Approved->value,
                    'approved_by' => $by->getKey(),
                    'approved_at' => now(),
                ])->save();
            });

            return $locked->refresh();
        }, 3);

        PaymentReversalApproved::dispatch($approved);

        return $approved;
    }

    /**
     * Refuse a reversal, and put the money back where it was.
     *
     * The `refunded_amount` increment and the payment's status are rolled back **here**, in the same
     * transaction, under the payment's row lock. `PaymentReversalRejected` is the notification and
     * cache leg only (F-4.9): a listener that moved money would double the rollback.
     */
    public function rejectReversal(PaymentReversal $reversal, string $reason, User $by): PaymentReversal
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PaymentRuleException::refuse('reason', 'Refusing a refund needs a reason: somebody asked for it.');
        }

        $rejected = $this->db->transaction(function () use ($reversal, $reason, $by): PaymentReversal {
            /** @var PaymentReversal $locked */
            $locked = PaymentReversal::query()->whereKey($reversal->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== ReversalApprovalStatus::Pending) {
                throw PaymentRuleException::refuse('approval_status', sprintf(
                    'Reversal %s is %s, so there is nothing to refuse.',
                    (string) $locked->reversal_no,
                    $locked->approval_status->label(),
                ));
            }

            $payment = $locked->target();

            if ($payment !== null) {
                $this->undoRefundOnPayment($payment, (string) $locked->amount);
            }

            PaymentReversal::allowDirectWrites(function () use ($locked, $reason, $by): void {
                $locked->forceFill([
                    'approval_status' => ReversalApprovalStatus::Rejected->value,
                    'rejected_at' => now(),
                    'rejection_reason' => mb_substr($reason, 0, 255),
                    'approved_by' => $by->getKey(),
                    'commission_state' => CommissionProcessingState::NotApplicable->value,
                    'commission_skip_reason' => CommissionSkipReason::ReversalNotApproved->value,
                    'commission_skip_detail' => mb_substr('Refused: '.$reason, 0, 191),
                    'commission_processed_at' => now(),
                ])->save();
            });

            return $locked->refresh();
        }, 3);

        PaymentReversalRejected::dispatch($rejected);

        return $rejected;
    }

    /*
    |--------------------------------------------------------------------------
    | Looking before you leap
    |--------------------------------------------------------------------------
    */

    /**
     * What this payment **would** earn, computed without writing anything.
     *
     * Step 4 of the record-payment wizard. It runs the same guards the engine runs and the same pure
     * calculator, so the figure a cashier is shown and the figure on the resulting entry cannot
     * disagree. A cashier who sees "no rule covers 2026-03-10 for COL-1024" before taking the money
     * can have it fixed; one who learns it from a report next month cannot.
     */
    public function dryRun(StudentFee $document, RecordPaymentData $data): CommissionPreview
    {
        $settings = CommissionSettings::capture();
        $paidOn = $data->paidOn === null
            ? Carbon::now(Format::timezone())->startOfDay()
            : Carbon::instance($data->paidOn->toDateTime())->startOfDay();

        if (! $settings->referralSystemEnabled) {
            return CommissionPreview::skips(CommissionSkipReason::ReferralSystemDisabled,
                'The referral system is switched off.', 'G1');
        }

        $referral = $this->referrals->effectiveOnSubject(
            ReferralSubject::Student,
            (int) $document->student_id,
            $paidOn,
        );

        if ($referral === null || ! $referral->commission_eligible) {
            return CommissionPreview::skips(
                $referral === null ? CommissionSkipReason::NoReferral : CommissionSkipReason::ReferralNotCommissionEligible,
                $referral === null
                    ? sprintf('Nobody is credited for this student on %s.', $paidOn->toDateString())
                    : 'The attribution on this student is marked as earning no commission.',
                $referral === null ? 'G4' : 'G5',
            );
        }

        $collaborator = Collaborator::withTrashed()->whereKey($referral->collaborator_id)->first();

        if ($collaborator === null || $collaborator->trashed()) {
            return CommissionPreview::skips(CommissionSkipReason::CollaboratorDeleted,
                'The partner credited for this student has been deleted.', 'G6');
        }

        if ($collaborator->status !== CollaboratorStatus::Active) {
            return CommissionPreview::skips(CommissionSkipReason::CollaboratorInactive,
                sprintf('%s is %s.', (string) $collaborator->collaborator_code, strtolower($collaborator->status->label())),
                'G7', $collaborator);
        }

        $rule = $this->rules->resolve($collaborator, CommissionScope::Student, $paidOn);

        if ($rule === null || ! $rule->isUsable()) {
            return CommissionPreview::skips(CommissionSkipReason::NoEffectiveRule,
                sprintf('No student commission rule covers %s for %s.', $paidOn->toDateString(),
                    (string) $collaborator->collaborator_code),
                'G8', $collaborator);
        }

        if (! $rule->isEnabled) {
            return CommissionPreview::skips(CommissionSkipReason::CommissionDisabled,
                'Student commission is switched off for this partner.', 'G9', $collaborator, $rule);
        }

        if (! $this->feeTypeEarns($document->fee_type, $rule->appliesToFeeTypes, $settings)) {
            return CommissionPreview::skips(CommissionSkipReason::FeeTypeNotCommissionable,
                sprintf('%s does not earn commission.', $document->fee_type->label()),
                'G10', $collaborator, $rule);
        }

        if (! $rule->meetsMinimumPayment($data->amount())) {
            return CommissionPreview::skips(CommissionSkipReason::BelowMinimumPayment,
                sprintf('%s is below this rule\'s minimum.', Money::format($data->amount())),
                'G11', $collaborator, $rule);
        }

        if (! $rule->base->appliesTo(CommissionScope::Student)) {
            $rule = $rule->withBase(CommissionBase::Paid, true);
        }

        $base = $this->bases->forStudentPayment(
            $this->unsavedProbeReceipt($document, $data, $paidOn),
            $rule->base,
        );

        // The promise and the running totals come from the entitlement **if one exists**; otherwise the
        // promise is computed the way `openOrLoad()` would compute it. Nothing is opened: a preview
        // that created a row would make a cashier who changed their mind leave a promise behind.
        $entitlement = $this->currentEntitlement($base, (int) $collaborator->getKey());

        $promise = $entitlement !== null
            ? ($entitlement->entitlement_amount === null ? null : (string) $entitlement->entitlement_amount)
            : $this->entitlements->promiseFor(
                calculationType: $rule->calculationType,
                base: $base->base,
                release: $rule->release,
                rate: $rule->rate,
                fixedAmount: $rule->fixedAmount,
                documentBaseAmount: $base->documentBaseAmount,
                maxCommissionAmount: $rule->maxCommissionAmount,
            );

        $calculation = $this->calculator->calculate(
            rule: $rule,
            document: $base,
            settings: $settings,
            paymentAmount: $data->amount(),
            collectedBefore: (string) ($entitlement->collected_amount ?? Money::ZERO),
            releasedBefore: (string) ($entitlement->released_amount ?? Money::ZERO),
            promise: $promise,
        );

        return $calculation->skip !== null
            ? CommissionPreview::skips($calculation->skip, (string) $calculation->detail, (string) $calculation->step, $collaborator, $rule)
            : CommissionPreview::earns($collaborator, $rule, $calculation);
    }

    /*
    |--------------------------------------------------------------------------
    | The model guard's flag ([D-IMP-2])
    |--------------------------------------------------------------------------
    */

    public function isWriting(): bool
    {
        return StudentFeePayment::isWriting();
    }

    public function allowDirectWrites(Closure $callback): mixed
    {
        return StudentFeePayment::allowDirectWrites($callback);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A receipt is dated in the past or today, and not further back than the business allows unless
     * the actor is trusted with it.
     *
     * A future-dated receipt is refused outright, whatever anybody holds: it would sit in a window no
     * rule covers yet and earn nothing until the day arrived, which looks exactly like a bug.
     */
    private function assertValueDate(?CarbonInterface $paidOn, string $ability): Carbon
    {
        $today = Carbon::now(Format::timezone())->startOfDay();

        // **Both sides are built in the business timezone, from a date string.** A value date is a
        // calendar date somebody typed, not an instant: `Carbon::now()` is UTC midnight-relative while
        // `$today` is Karachi midnight-relative, and comparing them as instants makes "today" five
        // hours in the future east of Greenwich. That is D69's attendance bug in a different table,
        // and here it would refuse every receipt taken today.
        $date = $paidOn === null
            ? $today
            : Carbon::parse(Carbon::instance($paidOn->toDateTime())->toDateString(), Format::timezone())->startOfDay();

        if ($date->greaterThan($today)) {
            throw PaymentRuleException::refuse('paid_on',
                'A receipt cannot be dated in the future. Money that has not arrived is not a receipt.');
        }

        $limit = max(0, (int) setting('finance.backdate_limit_days', 30));
        $earliest = $today->copy()->subDays($limit);

        if ($date->greaterThanOrEqualTo($earliest) || auth()->user()?->can($ability) === true) {
            return $date;
        }

        throw PaymentRuleException::refuse('paid_on', sprintf(
            'Receipts may be back-dated %d days, to %s. Dating one earlier changes which commission '
            .'rule and which partner it falls under, so it needs somebody who may approve it.',
            $limit,
            $earliest->toDateString(),
        ));
    }

    private function assertChargeIsOpen(StudentFee $charge): void
    {
        if ($charge->status === StudentFeeStatus::Cancelled || $charge->trashed()) {
            throw PaymentRuleException::refuse('student_fee_id', sprintf(
                'Charge %s is cancelled. Take the money against a live charge, or reinstate this one.',
                (string) $charge->fee_number,
            ));
        }
    }

    /**
     * The installment line, locked, and only if it belongs to this charge.
     */
    private function lockInstallment(StudentFee $charge, ?int $installmentId): ?StudentFeeInstallment
    {
        if ($installmentId === null) {
            return null;
        }

        /** @var StudentFeeInstallment|null $line */
        $line = StudentFeeInstallment::query()
            ->whereKey($installmentId)
            ->where('student_fee_id', $charge->getKey())
            ->lockForUpdate()
            ->first();

        if ($line === null) {
            throw PaymentRuleException::refuse('student_fee_installment_id', sprintf(
                'That installment does not belong to charge %s. Allocating a receipt to another '
                .'charge\'s schedule would make both of them wrong.',
                (string) $charge->fee_number,
            ));
        }

        return $line;
    }

    /**
     * `sha1(fee|installment|amount|paid_on|method|reference)` — spine §2.5, and deliberately
     * **non-unique**. Two identical receipts on one day are usually a mistake and occasionally real,
     * so this warns a second cashier rather than refusing them.
     */
    private function fingerprint(StudentFee $charge, RecordPaymentData $data, string $amount, Carbon $paidOn): string
    {
        return sha1(implode('|', [
            (string) $charge->getKey(),
            (string) ($data->installmentId ?? ''),
            $amount,
            $paidOn->toDateString(),
            $data->method->value,
            (string) ($data->referenceNo ?? ''),
        ]));
    }

    private function assertNotADuplicate(StudentFee $charge, RecordPaymentData $data, string $amount, Carbon $paidOn): void
    {
        if ($data->confirmDuplicate) {
            return;
        }

        $twin = StudentFeePayment::query()
            ->where('duplicate_fingerprint', $this->fingerprint($charge, $data, $amount, $paidOn))
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->first(['id', 'receipt_no']);

        if ($twin === null) {
            return;
        }

        throw PaymentRuleException::refuse('amount', sprintf(
            'Receipt %s already records %s against this charge on %s by the same method. If this is a '
            .'genuine second payment, confirm it and both receipts will be kept with the override '
            .'logged.',
            (string) $twin->receipt_no,
            Money::format($amount),
            $paidOn->toDateString(),
        ));
    }

    /**
     * Allocate what the receipts on this line actually add up to, capped at the line's own amount.
     *
     * The cap is here rather than in a CHECK on purpose (spine §2.3): over-paying a line is legal and
     * lands on the charge as an advance. What must not happen is the *line* claiming more than it was
     * ever for, which would make the schedule read as though somebody had agreed to pay more.
     */
    private function allocate(?StudentFeeInstallment $line, StudentFee $charge): void
    {
        if ($line === null) {
            return;
        }

        $received = (string) (StudentFeePayment::query()
            ->where('student_fee_installment_id', $line->getKey())
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->sum('net_received_amount') ?: Money::ZERO);

        $allocated = Money::min(Money::of($received), Money::of((string) $line->amount));
        $outstanding = Money::sub(Money::of((string) $line->amount), (string) $line->waived_amount);

        $status = match (true) {
            Money::compare($allocated, $outstanding) >= 0 => InstallmentStatus::Paid,
            Money::isPositive($allocated) => InstallmentStatus::Partial,
            default => $line->status === InstallmentStatus::Overdue ? InstallmentStatus::Overdue : InstallmentStatus::Pending,
        };

        $line->forceFill([
            'paid_amount' => $allocated,
            'status' => $status->value,
            'paid_on' => $status === InstallmentStatus::Paid
                ? ($line->paid_on?->toDateString() ?? Carbon::now(Format::timezone())->toDateString())
                : null,
        ])->save();
    }

    /**
     * Recompute the charge's caches — **by asking Phase 18, which owns them** (phase-18 §2.3, §6.4.1).
     *
     * This method used to do the arithmetic itself and derive the status through a private
     * `chargeStatus()`. By the time Phase 18 shipped, the two definitions had drifted in three ways,
     * none of them a typo:
     *
     *   · a charge covered entirely by a scholarship (`net 0.00`, nothing received) never reached
     *     `paid` — it fell past every branch to the due-date one and read `pending` or `overdue`;
     *   · a part-paid charge past its due date always read `partial`, never `overdue`;
     *   · `refunded_amount` was summed from `payment_reversals` including reversals of **voided**
     *     receipts, counting money that never counted.
     *
     * That is what happens when one question has two answers in two files, and it is why
     * `StudentFeeService::deriveStatus()` is now the only one — called by the payment path, the
     * discount path and the nightly sweeper alike. It also owns `discount_amount`,
     * `scholarship_amount` and `net_amount`, which this method never wrote.
     *
     * `StudentFeeService` is resolved rather than injected because it depends on this service for
     * `transferPayment()`: two constructors that need each other cannot both be built. Resolving at
     * the point of use breaks the cycle without either side pretending it is independent.
     */
    private function recomputeCharge(StudentFee $charge): void
    {
        app(StudentFeeService::class)->recomputeCaches($charge);
    }

    /**
     * Raise the receipt's `refunded_amount` by a conditional UPDATE and move its status.
     *
     * The predicate is the invariant: the row is only updated while the new total still fits inside
     * the amount. A racing refund that would break it affects zero rows, and the transaction is
     * refused instead of writing a receipt that gave back more than it took.
     */
    private function applyRefundToPayment(
        StudentFeePayment|ProjectPayment $payment,
        string $amount,
        ReversalType $type,
        bool $isPartial,
    ): void {
        $affected = $this->db->table($payment->getTable())
            ->where('id', $payment->getKey())
            ->whereRaw('refunded_amount + ? <= amount', [$amount])
            ->update([
                'refunded_amount' => $this->db->raw('refunded_amount + '.$this->literal($amount)),
                'status' => $type->resultingPaymentStatus($isPartial)->value,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw PaymentRuleException::refuse('amount',
                'Another refund against this receipt committed a moment ago and this one would take the '
                .'total past what was received. Re-open the receipt to see what is left.');
        }

        $payment->refresh();

        if ($payment instanceof StudentFeePayment) {
            $charge = StudentFee::query()->whereKey($payment->student_fee_id)->lockForUpdate()->first();

            if ($charge !== null) {
                $this->allocate(
                    $payment->student_fee_installment_id === null
                        ? null
                        : StudentFeeInstallment::query()->whereKey($payment->student_fee_installment_id)->lockForUpdate()->first(),
                    $charge,
                );
                $this->recomputeCharge($charge);
            }
        }
    }

    /**
     * Put back what a rejected reversal had taken off.
     */
    private function undoRefundOnPayment(StudentFeePayment|ProjectPayment $payment, string $amount): void
    {
        $locked = $payment->newQuery()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        $this->db->table($locked->getTable())
            ->where('id', $locked->getKey())
            ->update([
                'refunded_amount' => $this->db->raw('GREATEST(0, refunded_amount - '.$this->literal($amount).')'),
                'updated_at' => now(),
            ]);

        $locked->refresh();

        // The status follows the figure rather than being remembered: whatever other reversals exist,
        // what is left decides whether the receipt is whole, partly returned or fully returned.
        $remaining = $locked->refundableRemaining();

        $status = match (true) {
            Money::compare($remaining, (string) $locked->amount) >= 0 => ReceivedPaymentStatus::Cleared,
            Money::isPositive($remaining) => ReceivedPaymentStatus::PartiallyRefunded,
            default => ReceivedPaymentStatus::Refunded,
        };

        $this->db->table($locked->getTable())
            ->where('id', $locked->getKey())
            ->update(['status' => $status->value, 'updated_at' => now()]);

        if ($locked instanceof StudentFeePayment) {
            $charge = StudentFee::query()->whereKey($locked->student_fee_id)->lockForUpdate()->first();

            if ($charge !== null) {
                $this->recomputeCharge($charge);
            }
        }
    }

    private function approvalFor(string $amount): ReversalApprovalStatus
    {
        if (! (bool) setting('finance.refund_approval_required', false)) {
            return ReversalApprovalStatus::NotRequired;
        }

        $threshold = Money::of((string) setting('finance.refund_approval_threshold', '0.00'));

        // A zero threshold means every refund needs approval; otherwise only the ones at or above it.
        return Money::isZero($threshold) || Money::compare($amount, $threshold) >= 0
            ? ReversalApprovalStatus::Pending
            : ReversalApprovalStatus::NotRequired;
    }

    /**
     * §6.1.2's fee-type question, shared with the engine's G10 through the same two settings.
     *
     * @param  list<string>|null  $ruleList
     */
    private function feeTypeEarns(StudentFeeType $type, ?array $ruleList, CommissionSettings $settings): bool
    {
        if ($type === StudentFeeType::AdmissionFee) {
            return $settings->commissionOnAdmissionFee;
        }

        if ($type === StudentFeeType::RegistrationFee) {
            return $settings->commissionOnRegistrationFee;
        }

        return in_array($type->value, $ruleList ?? $settings->commissionableFeeTypes, true);
    }

    /**
     * An unsaved receipt, purely so the base resolver can be asked the same question it will be asked
     * for real. It is never saved and never leaves this method's caller.
     */
    private function unsavedProbeReceipt(StudentFee $document, RecordPaymentData $data, Carbon $paidOn): StudentFeePayment
    {
        $probe = new StudentFeePayment;

        $probe->forceFill([
            'student_fee_id' => $document->getKey(),
            'student_id' => $document->student_id,
            'student_fee_installment_id' => $data->installmentId,
            'amount' => $data->amount(),
            'paid_on' => $paidOn->toDateString(),
        ]);

        $probe->setRelation('fee', $document);

        return $probe;
    }

    private function currentEntitlement(BaseResolution $base, int $collaboratorId): ?CollaboratorCommissionEntitlement
    {
        return CollaboratorCommissionEntitlement::query()
            ->where('document_type', $base->documentType->value)
            ->where($base->documentColumn(), $base->documentId)
            ->where('collaborator_id', $collaboratorId)
            ->whereNull('superseded_at')
            ->first();
    }

    private function numberOf(StudentFeePayment|ProjectPayment $payment): string
    {
        return (string) ($payment instanceof StudentFeePayment ? $payment->receipt_no : $payment->payment_no);
    }

    private function tableToken(StudentFeePayment|ProjectPayment $payment): string
    {
        return $payment instanceof StudentFeePayment ? 'student_fee_payment' : 'project_payment';
    }

    private function literal(string $amount): string
    {
        if (preg_match('/^-?\d{1,15}\.\d{2}$/', $amount) !== 1) {
            throw PaymentRuleException::refuse('amount', sprintf('[%s] is not a money value.', $amount));
        }

        return $amount;
    }
}
