<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\CommissionOutcome;
use App\DataObjects\Collaborator\LedgerDelta;
use App\DataObjects\Collaborator\LedgerEntryDraft;
use App\Enums\CommissionApprovalMode;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionProcessingState;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Events\Collaborator\CommissionClawedBack;
use App\Events\Collaborator\CommissionReversed;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Finance\PaymentReversal;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Support\Collaborator\CommissionSettings;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Undoing a commission, and the one honest way to do it (spine §6.3, §6.6, phase-10-12 §6.3).
 *
 * **A reversal is a new negative row that references the original.** Nothing here ever touches a money
 * column on the entry it is undoing: the original stays byte-identical on every figure it was posted
 * with, for ever, because it is what the partner was shown at the time (`CLAUDE.md` rule 3, INV-4).
 *
 * **The target is cumulative, so partial refunds cannot drift.** Each pass computes how much of the
 * entry *should* be undone given everything refunded so far — `amount x refunded / paid` — and posts
 * only the difference. Three refunds of 3,333 / 3,333 / 3,334 against a 1,000.00 commission undo
 * 333.30 / 333.30 / 333.40, which is 1,000.00 exactly. Computing each pass independently would round
 * three times and land near it.
 *
 * **Money already paid out is not "reversed", it is clawed back**, and the two are different rows with
 * different meanings. A reversal takes back an entitlement the partner still holds; a clawback records
 * that the business is owed money it has already handed over. The wallet legitimately goes negative,
 * new payouts are refused while it is, and recovery is a `manual_adjustment` credit or a `write_off` —
 * both of which somebody has to decide (§6.6).
 */
final class CommissionReversalService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LedgerWriter $ledger,
        private readonly CommissionEntitlementService $entitlements,
        private readonly CollaboratorWalletService $wallets,
    ) {}

    /**
     * Undo whatever this reversal now requires, and no more.
     *
     * Idempotent under unlimited replay: the cumulative target means a second run computes a delta of
     * zero and writes nothing, and `uq_cle_dedupe` is the guarantee behind that rather than a
     * convention.
     */
    public function handleReversal(PaymentReversal $reversal): CommissionOutcome
    {
        $settings = CommissionSettings::capture();

        return $this->db->transaction(function () use ($reversal, $settings): CommissionOutcome {
            /** @var PaymentReversal $locked */
            $locked = PaymentReversal::query()->whereKey($reversal->getKey())->lockForUpdate()->firstOrFail();

            $outcome = $this->evaluate($locked, $settings);

            $this->stamp($locked, $outcome);

            return $outcome;
        }, 3);
    }

    /**
     * Post a manual adjustment or a write-off against a partner's balance.
     *
     * The only way a human puts a figure into the ledger by hand, and it is still an **append**: a
     * positive amount credits, a negative one debits, and neither edits anything. A write-off says the
     * business has given up recovering a clawback, which is a decision with a cost — so it needs the
     * approval permission on top and raises a notification.
     */
    public function adjust(
        Collaborator $collaborator,
        string $signedAmount,
        string $reason,
        ?Model $context = null,
        bool $writeOff = false,
    ): CollaboratorCommissionLedgerEntry {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'A manual adjustment is a figure somebody put into the ledger by hand. The reason is '
                .'the whole of its explanation, and there is no second place to look it up.');
        }

        $signed = Money::of($signedAmount);

        if (Money::isZero($signed)) {
            throw CollaboratorRuleException::refuse('amount',
                'An adjustment of nothing is not an adjustment. `chk_cle_amount` refuses a zero row, '
                .'and a zero row would be a number somebody later sums.');
        }

        $ability = $writeOff ? 'collaborator_commissions.approve' : 'collaborator_commissions.create';

        if (auth()->check() && auth()->user()?->can($ability) !== true) {
            throw CollaboratorRuleException::refuse('amount', sprintf(
                'Posting %s to a partner\'s ledger by hand needs `%s`.',
                $writeOff ? 'a write-off' : 'an adjustment',
                $ability,
            ));
        }

        return $this->db->transaction(function () use ($collaborator, $signed, $reason, $context, $writeOff): CollaboratorCommissionLedgerEntry {
            $today = Carbon::now(Format::timezone())->startOfDay();

            $draft = new LedgerEntryDraft(
                collaborator: $collaborator,
                purpose: $writeOff ? LedgerEntryPurpose::WriteOff : LedgerEntryPurpose::ManualAdjustment,
                // The magnitude is the amount; the direction is the type. A write-off cancels a debt the
                // partner owes, so it credits them — `chk_cle_sign` pins that down.
                entryType: $writeOff || Money::isPositive($signed) ? LedgerEntryType::Credit : LedgerEntryType::Debit,
                sourceType: CommissionSourceType::ManualAdjustment,
                sourceId: (int) ($context?->getKey() ?? $collaborator->getKey()),
                amount: Money::abs($signed),
                baseAmount: Money::ZERO,
                grossAmount: Money::ZERO,
                base: CommissionBase::Paid,
                calculationType: CommissionCalculationType::Manual,
                // An adjustment somebody posted deliberately is available at once: sending it back
                // through an approval queue would mean approving a decision that was just made.
                status: CommissionStatus::Available,
                approvalMode: CommissionApprovalMode::Manual,
                transactionDate: $today,
                ruleSnapshot: [
                    'manual' => true,
                    'write_off' => $writeOff,
                    'reason' => $reason,
                    'actor_id' => auth()->id(),
                    'context' => $context === null ? null : [
                        'type' => $context::class,
                        'id' => $context->getKey(),
                    ],
                    'posted_at' => now()->toIso8601String(),
                ],
                notes: $reason,
            );

            return $this->ledger->post($draft)->entry;
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The algorithm (§6.3, §6.6)
    |--------------------------------------------------------------------------
    */

    private function evaluate(PaymentReversal $reversal, CommissionSettings $settings): CommissionOutcome
    {
        if ($reversal->commission_state->isSettled()) {
            return CommissionOutcome::alreadyDone($reversal->commission_state);
        }

        if (! $settings->reversalOnRefund) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::ReferralSystemDisabled,
                'Commission reversal on refund is switched off, so this refund leaves the commission '
                .'standing. Nothing will undo it later either — the setting is read when the refund is '
                .'processed, not afterwards.',
                'R1',
            );
        }

        if (! $reversal->mayReverseCommission()) {
            return CommissionOutcome::skipped(
                CommissionSkipReason::ReversalNotApproved,
                sprintf('Reversal %s is %s. Nothing is undone until somebody approves it.',
                    (string) $reversal->reversal_no, $reversal->approval_status->label()),
                'R2',
            );
        }

        $payment = $reversal->target();

        if ($payment === null) {
            throw new LogicException(sprintf(
                'Reversal %s has no receipt behind it, which `chk_pr_one_target` makes impossible.',
                (string) $reversal->reversal_no,
            ));
        }

        // The receipt is locked before its entries: payment -> entitlement -> wallet -> ledger rows
        // ascending by id, the spine's fixed order, so two reversals of one receipt serialise here.
        $payment = $payment->newQuery()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        if ($payment->commission_state === CommissionProcessingState::Skipped) {
            return CommissionOutcome::notApplicable(
                sprintf('The receipt earned nothing (%s), so there is nothing to undo.',
                    (string) $payment->commissionSkipSentence()),
                'R3',
            );
        }

        $entries = CollaboratorCommissionLedgerEntry::query()
            ->where($this->paymentColumn($payment), $payment->getKey())
            ->earnings()
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($entries->isEmpty()) {
            // The earning has not been evaluated yet. **Stay `queued`** so the sweeper retries: the
            // reversal job overtaking its own earning is normal on a busy queue, and treating it as a
            // skip would make the order of two jobs decide whether a partner keeps refunded money.
            return $payment->commission_state === CommissionProcessingState::Queued
                ? CommissionOutcome::alreadyDone(CommissionProcessingState::Queued)
                : CommissionOutcome::skipped(
                    CommissionSkipReason::SourceCommissionMissing,
                    'The receipt has been processed and produced no commission entry to reverse.',
                    'R4',
                );
        }

        $posted = 0;

        foreach ($entries as $entry) {
            $posted += $this->undo($entry, $payment, $reversal, $settings);
        }

        return $posted === 0
            ? CommissionOutcome::notApplicable(
                'Everything this refund requires had already been undone.',
                'R5',
            )
            : CommissionOutcome::posted($entries->first(), true);
    }

    /**
     * Undo one entry as far as the cumulative target now requires. Returns how many rows it wrote.
     */
    private function undo(
        CollaboratorCommissionLedgerEntry $entry,
        Model $payment,
        PaymentReversal $reversal,
        CommissionSettings $settings,
    ): int {
        // **Cumulative, never incremental.** How much of this entry should be undone given everything
        // refunded so far — then post only the difference.
        $target = Money::prorate(
            (string) $entry->amount,
            (string) $payment->refunded_amount,
            (string) $payment->amount,
        );

        $undone = Money::add((string) $entry->reversed_amount, (string) $entry->clawed_back_amount);
        $delta = Money::sub($target, $undone);

        if (! Money::isPositive($delta)) {
            return 0;
        }

        $paidPortion = $this->paidPortionOf($entry);
        $this->assertNoInflightAllocations($entry);

        $reversible = Money::max(Money::ZERO, Money::sub(Money::sub((string) $entry->amount, $undone), $paidPortion));
        $reverseNow = Money::min($delta, $reversible);
        $clawbackNow = Money::sub($delta, $reverseNow);

        $written = 0;

        if (Money::isPositive($reverseNow)) {
            $written += $this->postDebit($entry, $reversal, $payment, LedgerEntryPurpose::Reversal, $reverseNow, $entry->status);
        }

        if (Money::isPositive($clawbackNow)) {
            // Money that has already left the company. `offset_future` leaves the debit `available`, so
            // it nets against the partner's next earnings; `write_off` cancels it, which is the
            // business deciding not to chase it.
            $status = $settings->clawbackOnPaidCommission === 'write_off'
                ? CommissionStatus::Cancelled
                : CommissionStatus::Available;

            $written += $this->postDebit($entry, $reversal, $payment, LedgerEntryPurpose::Clawback, $clawbackNow, $status);
        }

        if ($written === 0) {
            return 0;
        }

        $this->recordOnEntry($entry, $reverseNow, $clawbackNow);
        $this->unreleaseEntitlement($entry, $payment, $reverseNow, $clawbackNow);
        $this->settleIfFullyUndone($entry);

        return $written;
    }

    /**
     * One debit, written through the only insert path there is.
     */
    private function postDebit(
        CollaboratorCommissionLedgerEntry $entry,
        PaymentReversal $reversal,
        Model $payment,
        LedgerEntryPurpose $purpose,
        string $amount,
        CommissionStatus $status,
    ): int {
        $draft = new LedgerEntryDraft(
            collaborator: $entry->collaborator,
            purpose: $purpose,
            entryType: LedgerEntryType::Debit,
            sourceType: CommissionSourceType::PaymentReversal,
            sourceId: (int) $reversal->getKey(),
            amount: $amount,
            baseAmount: (string) $entry->base_amount,
            grossAmount: (string) $entry->gross_amount,
            base: $entry->commission_base,
            calculationType: $entry->calculation_type,
            status: $status,
            approvalMode: $entry->approval_mode,
            // The **reversal's** business date, not the earning's: the money went back on the day it
            // went back, and a statement that filed it under the original month would balance neither.
            transactionDate: $reversal->occurred_on ?? Carbon::now(Format::timezone())->startOfDay(),
            ruleSnapshot: [
                'reverses' => [
                    'entry_id' => (int) $entry->getKey(),
                    'entry_amount' => (string) $entry->amount,
                    'entry_status' => $entry->status->value,
                ],
                'reversal' => [
                    'id' => (int) $reversal->getKey(),
                    'reversal_no' => (string) $reversal->reversal_no,
                    'type' => $reversal->type->value,
                    'reason' => (string) $reversal->reason,
                ],
                'payment' => [
                    'amount' => (string) $payment->amount,
                    'refunded_amount' => (string) $payment->refunded_amount,
                ],
                // The rule columns are copied from the original rather than re-resolved: a reversal
                // quotes the rule that produced what it is undoing, not whatever is in force today.
                'rule' => $entry->rule_snapshot['rule'] ?? null,
                'purpose' => $purpose->value,
            ],
            entitlement: $entry->entitlement,
            referral: $entry->referral,
            commissionSettingId: $entry->commission_setting_id,
            ruleSource: $entry->rule_source,
            commissionRate: $entry->commission_rate === null ? null : (string) $entry->commission_rate,
            fixedAmount: $entry->fixed_amount === null ? null : (string) $entry->fixed_amount,
            studentFeePaymentId: $entry->student_fee_payment_id,
            projectPaymentId: $entry->project_payment_id,
            paymentReversalId: (int) $reversal->getKey(),
            reversesEntryId: (int) $entry->getKey(),
            subjectColumns: [
                'student_id' => $entry->student_id,
                'student_fee_id' => $entry->student_fee_id,
                'project_id' => $entry->project_id,
                'project_milestone_id' => $entry->project_milestone_id,
            ],
            notes: sprintf('%s of %s against %s',
                $purpose === LedgerEntryPurpose::Clawback ? 'Clawback' : 'Reversal',
                (string) $entry->reference,
                (string) $reversal->reversal_no),
        );

        $result = $this->ledger->post($draft);

        if (! $result->created) {
            return 0;
        }

        $result->entry->purpose === LedgerEntryPurpose::Clawback
            ? CommissionClawedBack::dispatch($result->entry, $entry)
            : CommissionReversed::dispatch($result->entry, $entry);

        return 1;
    }

    /**
     * Record on the original how much of it has now been undone.
     *
     * These two columns are on the entry's `mutableColumns()` whitelist precisely because they are
     * bookkeeping *about* the row rather than part of what it says: `amount`, the rate, the base and
     * the date never move.
     */
    private function recordOnEntry(CollaboratorCommissionLedgerEntry $entry, string $reversed, string $clawedBack): void
    {
        CollaboratorCommissionLedgerEntry::allowDirectWrites(function () use ($entry, $reversed, $clawedBack): void {
            $entry->forceFill([
                'reversed_amount' => Money::add((string) $entry->reversed_amount, $reversed),
                'clawed_back_amount' => Money::add((string) $entry->clawed_back_amount, $clawedBack),
                'reversed_at' => now(),
            ])->save();
        });

        $entry->refresh();
    }

    /**
     * Give the promise its room back (spine §6.3.7), so a document that is paid again can earn again.
     */
    private function unreleaseEntitlement(
        CollaboratorCommissionLedgerEntry $entry,
        Model $payment,
        string $reversed,
        string $clawedBack,
    ): void {
        $entitlement = $entry->entitlement;

        if ($entitlement === null) {
            return;
        }

        $undone = Money::add($reversed, $clawedBack);

        // The share of the collected base this undoing represents. Without it `collected_amount` would
        // keep counting money that went back, and branch C's proration would then release against a
        // denominator that includes a refund.
        $baseShare = Money::isZero((string) $entry->amount)
            ? Money::ZERO
            : Money::prorate((string) $entry->base_amount, $undone, (string) $entry->amount);

        $this->entitlements->unrelease($entitlement, $undone, $baseShare);
    }

    /**
     * INV-15: an entry that is fully undone and claimed by nobody becomes `reversed` — **and so do all
     * of its reversal rows**, in the same transaction.
     *
     * Leaving the debits `available` while the credit is `reversed` would put a negative balance in the
     * partner's spendable bucket for a transaction that has been closed out on both sides.
     */
    private function settleIfFullyUndone(CollaboratorCommissionLedgerEntry $entry): void
    {
        if (! $entry->isFullyUndone() || Money::isPositive((string) $entry->allocated_amount)) {
            return;
        }

        if ($entry->status === CommissionStatus::Paid) {
            // Paid stays paid, for ever. The money really left; what undoes it is the clawback debit,
            // which is a separate row with its own balance effect (§6.3.5).
            return;
        }

        foreach ([$entry, ...$this->reversalRowsOf($entry)] as $row) {
            if ($row->status === CommissionStatus::Reversed || $row->status === CommissionStatus::Cancelled) {
                continue;
            }

            $from = $row->status;

            CollaboratorCommissionLedgerEntry::allowDirectWrites(function () use ($row): void {
                $row->forceFill(['status' => CommissionStatus::Reversed->value])->save();
            });

            $wallet = $this->wallets->lockFor($row->collaborator);
            $this->wallets->applyDelta($wallet, LedgerDelta::forStatusChange($row, $from));
        }
    }

    /**
     * @return Collection<int, CollaboratorCommissionLedgerEntry>
     */
    private function reversalRowsOf(CollaboratorCommissionLedgerEntry $entry)
    {
        return CollaboratorCommissionLedgerEntry::query()
            ->where('reverses_entry_id', $entry->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * How much of this entry sits on a payout that has actually been paid.
     *
     * Derived from live allocations on `paid` payouts, never from the entry's status alone (INV-23):
     * an entry can be `available` with part of it already gone out on an earlier payout.
     */
    private function paidPortionOf(CollaboratorCommissionLedgerEntry $entry): string
    {
        $total = $this->db->table('collaborator_payout_allocations as a')
            ->join('collaborator_payouts as p', 'p.id', '=', 'a.payout_id')
            ->where('a.ledger_entry_id', $entry->getKey())
            ->where('a.is_released', 0)
            ->where('p.status', PayoutStatus::Paid->value)
            ->sum('a.amount');

        return Money::of((string) ($total ?: Money::ZERO));
    }

    /**
     * §6.6: "release live allocations on in-flight payouts first, so a refund is never blocked by a
     * pending withdrawal". That release belongs to `PayoutService::releaseForReversal()`, which Phase
     * 12 ships along with the only code that can create an allocation in the first place.
     *
     * Until then no allocation can exist, so this is unreachable — and it throws rather than shrugging
     * precisely because the day it becomes reachable is the day Phase 12 has wired the payout side
     * without wiring this. A silent pass would under-reverse: the entry would look fully undone while
     * a live allocation still counted it as spendable.
     */
    private function assertNoInflightAllocations(CollaboratorCommissionLedgerEntry $entry): void
    {
        $inflight = $this->db->table('collaborator_payout_allocations as a')
            ->join('collaborator_payouts as p', 'p.id', '=', 'a.payout_id')
            ->where('a.ledger_entry_id', $entry->getKey())
            ->where('a.is_released', 0)
            ->whereIn('p.status', [
                PayoutStatus::Requested->value,
                PayoutStatus::Pending->value,
                PayoutStatus::Approved->value,
            ])
            ->exists();

        if (! $inflight) {
            return;
        }

        throw new LogicException(sprintf(
            '%s is claimed by a payout that has not been paid, and releasing that allocation is '
            .'`PayoutService::releaseForReversal()` — Phase 12. Wire it into '
            .'CommissionReversalService::undo() before payouts can be created, or a refund will '
            .'under-reverse while the allocation still counts the money as spendable.',
            (string) $entry->reference,
        ));
    }

    private function paymentColumn(Model $payment): string
    {
        return $payment instanceof StudentFeePayment
            ? 'student_fee_payment_id'
            : 'project_payment_id';
    }

    private function stamp(PaymentReversal $reversal, CommissionOutcome $outcome): void
    {
        if ($outcome->silent) {
            return;
        }

        PaymentReversal::allowDirectWrites(function () use ($reversal, $outcome): void {
            $reversal->forceFill([
                'commission_state' => $outcome->state->value,
                'commission_skip_reason' => $outcome->reason?->value,
                'commission_skip_detail' => $outcome->detail,
                'commission_attempts' => (int) $reversal->commission_attempts + 1,
                'commission_processed_at' => now(),
            ])->save();
        });

        if (! $outcome->isSkipped()) {
            return;
        }

        activity()
            ->performedOn($reversal)
            ->withProperties([
                'step' => $outcome->step,
                'reason' => $outcome->reason?->value,
                'detail' => $outcome->detail,
            ])
            ->event('commission.reversal_skipped')
            ->log($outcome->sentence() ?? 'Nothing was undone.')
            ->forceFill(['module' => 'collaborator_commissions'])
            ->save();
    }
}
