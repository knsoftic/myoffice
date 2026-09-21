<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\AllocationDelta;
use App\DataObjects\Collaborator\AllocationPlan;
use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\Enums\AllocationReleaseReason;
use App\Enums\CollaboratorActivityEvent;
use App\Enums\CollaboratorStatus;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorPayoutAllocation;
use App\Models\User;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Services\Finance\DocumentNumberService;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Paying a partner (spine §6.4, phase-10-12 §6.3).
 *
 * **[D-FS-11]: a payout consumes named ledger entries through allocation rows, never a running
 * balance.** A balance can answer "we paid him 20,000"; it cannot answer "we paid him exactly these
 * commissions", which the statement (§56), a clawback, and §120.9's arithmetic all need. Allocations
 * also keep the payout's `amount` **derived** rather than typed (INV-22) — the figure is what was
 * actually claimed from the ledger, not what somebody put in a box.
 *
 * **Double-spend is made impossible by a compare-and-swap, not by a lock held across the whole
 * allocation.** Each entry is claimed with
 * `UPDATE … SET allocated_amount = allocated_amount + :slice WHERE id = :id AND status = 'available'
 * AND allocated_amount + :slice + reversed_amount <= amount`, and a zero-row result means another
 * payout got there first — so this one skips that entry and carries on. Two payouts racing for one
 * 50,000 entry cannot both have it, and neither waits on the other.
 *
 * **A debit is never a candidate.** A clawback's effect is already inside `available`; allocating
 * against it would subtract it twice.
 */
final class PayoutService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberService $numbers,
        private readonly CollaboratorWalletService $wallets,
        private readonly CollaboratorActivityService $activity,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Creating one
    |--------------------------------------------------------------------------
    */

    /**
     * The partner asks to be paid (§6.4.2).
     *
     * Auto-approved when the amount is at or below `collaborator.payout_auto_approve_below` — a
     * business that pays small amounts without ceremony says so once, in a setting, rather than by
     * somebody remembering to click.
     */
    public function request(Collaborator $collaborator, PayoutRequestData $data, ?User $actor = null): CollaboratorPayout
    {
        if (! (bool) setting('collaborator.payout_request_enabled', true)) {
            throw CollaboratorRuleException::refuse('amount',
                'Partners cannot request payouts at the moment. Staff can still create one.');
        }

        $payout = $this->create($collaborator, $data, PayoutStatus::Requested, $actor);

        $threshold = Money::of((string) setting('collaborator.payout_auto_approve_below', '0.00'));

        if (Money::isPositive($threshold) && Money::compare((string) $payout->amount, $threshold) <= 0) {
            return $this->approve($payout, $actor ?? $this->systemActor(), 'Under the auto-approval threshold.');
        }

        return $payout;
    }

    /**
     * Staff create one on a partner's behalf. Starts `pending` rather than `requested`: nobody asked,
     * so there is no request to approve — there is a proposal to approve.
     */
    public function createFor(Collaborator $collaborator, PayoutRequestData $data, User $by): CollaboratorPayout
    {
        return $this->create($collaborator, $data, PayoutStatus::Pending, $by);
    }

    /**
     * The read-only FIFO preview for wizard step 2. **Writes nothing.**
     */
    public function plan(Collaborator $collaborator, string $amount): AllocationPlan
    {
        $requested = Money::of($amount);
        $remaining = $requested;
        $slices = [];

        foreach ($this->candidates($collaborator, locked: false) as $entry) {
            if (! Money::isPositive($remaining)) {
                break;
            }

            $claimable = $entry->payableRemaining();

            if (! Money::isPositive($claimable)) {
                continue;
            }

            $slice = Money::min($remaining, $claimable);

            $slices[] = [
                'entry_id' => (int) $entry->getKey(),
                'reference' => (string) $entry->reference,
                'transaction_date' => $entry->transaction_date->toDateString(),
                'available' => $claimable,
                'slice' => $slice,
            ];

            $remaining = Money::sub($remaining, $slice);
        }

        return new AllocationPlan(
            slices: $slices,
            requested: $requested,
            allocatable: Money::sub($requested, $remaining),
            shortfall: $remaining,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Moving it along
    |--------------------------------------------------------------------------
    */

    public function approve(CollaboratorPayout $payout, User $by, ?string $note = null): CollaboratorPayout
    {
        return $this->db->transaction(function () use ($payout, $by, $note): CollaboratorPayout {
            $locked = $this->lock($payout);

            if ($locked->status === PayoutStatus::Approved) {
                return $locked;
            }

            if (! in_array($locked->status, [PayoutStatus::Requested, PayoutStatus::Pending], true)) {
                throw $this->refuse($locked, 'approved');
            }

            $this->write($locked, [
                'status' => PayoutStatus::Approved->value,
                'approved_by' => $by->getKey(),
                'approved_at' => now(),
            ]);

            $this->log($locked, CollaboratorActivityEvent::PayoutRequest, 'approved', $note);

            return $locked->refresh();
        }, 3);
    }

    /**
     * The bank has sent it (§6.4.4).
     *
     * **Allocations stay live** — that is how the `paid` bucket is derived. What moves is each entry
     * the payout claimed *in full*: a partially claimed entry stays `available` with its
     * `allocated_amount` set (INV-23), because "paid commission" always comes from allocations and
     * never from a status somebody flipped.
     */
    public function markPaid(CollaboratorPayout $payout, MarkPaidData $data, User $by): CollaboratorPayout
    {
        $reference = trim((string) $data->transactionId);

        if ((bool) setting('finance.payout_reference_required', true) && $reference === '') {
            throw CollaboratorRuleException::refuse('transaction_id',
                'Record the bank reference. It is what proves this transfer happened, and it is what '
                .'stops the same transfer being entered twice.');
        }

        $paid = $this->db->transaction(function () use ($payout, $data, $by, $reference): CollaboratorPayout {
            $locked = $this->lock($payout);

            if ($locked->status === PayoutStatus::Paid) {
                return $locked;
            }

            if ($locked->status !== PayoutStatus::Approved) {
                throw $this->refuse($locked, 'marked paid');
            }

            $wallet = $this->wallets->lockFor($locked->collaborator);

            $this->write($locked, [
                'status' => PayoutStatus::Paid->value,
                'transaction_id' => $reference === '' ? null : $reference,
                'paid_by' => $by->getKey(),
                'paid_at' => now(),
                'paid_on' => ($data->paidOn === null
                    ? Carbon::now(Format::timezone())
                    : Carbon::instance($data->paidOn->toDateTime()))->toDateString(),
                'receipt_path' => $data->receiptPath,
            ]);

            foreach ($this->liveAllocations($locked) as $allocation) {
                $entry = CollaboratorCommissionLedgerEntry::query()
                    ->whereKey($allocation->ledger_entry_id)
                    ->lockForUpdate()
                    ->first();

                if ($entry === null || $entry->status !== CommissionStatus::Available) {
                    continue;
                }

                // Only an entry this payout claimed *entirely* becomes `paid`. One claimed in part is
                // still spendable for the rest, and calling it paid would hide that remainder.
                if (Money::compare((string) $entry->allocated_amount, (string) $entry->amount) !== 0) {
                    continue;
                }

                CollaboratorCommissionLedgerEntry::allowDirectWrites(static function () use ($entry): void {
                    $entry->forceFill(['status' => CommissionStatus::Paid->value, 'paid_at' => now()])->save();
                });
            }

            // `available` and `paid` both sit inside `payable_total`, so the status changes above move
            // nothing on the ledger side of the identity. What moves is the allocation side: the whole
            // payout goes from reserved to paid, whether or not every entry it claimed was claimed in
            // full.
            $this->wallets->applyAllocationDelta($wallet, AllocationDelta::settled((string) $locked->amount));

            $this->log($locked, CollaboratorActivityEvent::PayoutPaid, 'paid', $data->notes);

            return $locked->refresh();
        }, 3);

        return $paid;
    }

    public function reject(CollaboratorPayout $payout, string $reason, User $by): CollaboratorPayout
    {
        return $this->close($payout, PayoutStatus::Rejected, AllocationReleaseReason::PayoutRejected, $reason, $by, [
            PayoutStatus::Requested, PayoutStatus::Pending, PayoutStatus::Approved,
        ]);
    }

    public function cancel(CollaboratorPayout $payout, string $reason, ?User $by = null): CollaboratorPayout
    {
        return $this->close($payout, PayoutStatus::Cancelled, AllocationReleaseReason::PayoutCancelled, $reason, $by, [
            PayoutStatus::Requested, PayoutStatus::Pending, PayoutStatus::Approved,
        ]);
    }

    /**
     * The bank returned the money (§6.4.5, spine R-7).
     *
     * **The only backward money transition in the whole design**, and the most dangerous door in it:
     * settled money re-entering a spendable bucket. So it is permission-gated twice at the route,
     * reason-mandatory here, audited, and every entry it touches walks `paid -> available` with its own
     * audit row rather than being adjusted quietly.
     */
    public function cancelAfterPayment(CollaboratorPayout $payout, string $reason, User $by): CollaboratorPayout
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'Returning a settled payout puts money back into a spendable balance. That is the one '
                .'backward step in this system, and it is recorded with its reason or it is not taken.');
        }

        return $this->db->transaction(function () use ($payout, $reason, $by): CollaboratorPayout {
            $locked = $this->lock($payout);

            if ($locked->status !== PayoutStatus::Paid) {
                throw $this->refuse($locked, 'returned');
            }

            $wallet = $this->wallets->lockFor($locked->collaborator);

            foreach ($this->liveAllocations($locked) as $allocation) {
                $entry = CollaboratorCommissionLedgerEntry::query()
                    ->whereKey($allocation->ledger_entry_id)
                    ->lockForUpdate()
                    ->first();

                if ($entry !== null && $entry->status === CommissionStatus::Paid) {
                    CollaboratorCommissionLedgerEntry::allowDirectWrites(static function () use ($entry): void {
                        $entry->forceFill(['status' => CommissionStatus::Available->value, 'paid_at' => null])->save();
                    });
                }

                $this->release($allocation, AllocationReleaseReason::PayoutReturned, $by);
            }

            // Settled money re-entering a spendable bucket - the one backward step in the design.
            $this->wallets->applyAllocationDelta($wallet, AllocationDelta::returned((string) $locked->amount));

            $this->write($locked, [
                'status' => PayoutStatus::Cancelled->value,
                'cancelled_by' => $by->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
            ]);

            $this->log($locked, CollaboratorActivityEvent::PayoutPaid, 'returned', $reason);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Free what a reversal needs, so a refund is never blocked by a pending withdrawal (§6.6).
     *
     * Called only by `CommissionReversalService`. A payout left with no live allocations is cancelled:
     * an empty payout is not a payout, and leaving it in flight would reserve nothing while looking
     * like it reserved something.
     */
    public function releaseForReversal(CollaboratorCommissionLedgerEntry $entry, string $amount, ?User $by = null): void
    {
        $remaining = Money::of($amount);

        $allocations = CollaboratorPayoutAllocation::query()
            ->where('ledger_entry_id', $entry->getKey())
            ->where('is_released', 0)
            ->whereHas('payout', fn ($q) => $q->whereIn('status', [
                PayoutStatus::Requested->value, PayoutStatus::Pending->value, PayoutStatus::Approved->value,
            ]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $released = Money::ZERO;

        foreach ($allocations as $allocation) {
            if (! Money::isPositive($remaining)) {
                break;
            }

            $this->release($allocation, AllocationReleaseReason::ReleasedForReversal, $by);

            // An allocation is released whole: there is no half-claim, so the reversal frees the entire
            // claim even when it needs less than the claim holds. `$released` therefore tracks what was
            // actually freed, which is what the cache must move by - not the amount that was asked for.
            $released = Money::add($released, (string) $allocation->amount);
            $remaining = Money::max(Money::ZERO, Money::sub($remaining, (string) $allocation->amount));

            $payout = $allocation->payout;

            if ($payout !== null && $this->liveAllocations($payout)->isEmpty()) {
                $this->write($payout, [
                    'status' => PayoutStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Every entry it claimed was reversed before it was paid.',
                ]);
            }
        }

        if (Money::isPositive($released)) {
            // The claim is gone from the entry, so the cache has to follow it back out of `reserved`.
            // Whether the freed amount then survives in `available` is the reversal's business, not
            // this method's: its debit lands next and takes back whatever it is owed.
            $this->wallets->applyAllocationDelta(
                $this->wallets->lockFor($entry->collaborator),
                AllocationDelta::released($released),
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function create(
        Collaborator $collaborator,
        PayoutRequestData $data,
        PayoutStatus $status,
        ?User $actor,
    ): CollaboratorPayout {
        $key = $data->key();
        $existing = CollaboratorPayout::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->db->transaction(function () use ($collaborator, $data, $status, $actor, $key): CollaboratorPayout {
            // Step 1: the wallet row is the serialisation point, taken before anything is read.
            $wallet = $this->wallets->lockFor($collaborator);

            // Step 2: guards, against the **derivation** and never the cached row. A stale cache must
            // not be able to authorise money.
            $snapshot = $this->wallets->derive($collaborator);
            $requested = $data->amount();

            $this->assertPayable($collaborator, $wallet->is_frozen, $snapshot->availableBalance, $requested);

            $payout = CollaboratorPayout::allowDirectWrites(function () use (
                $collaborator, $data, $status, $actor, $key, $requested, $snapshot
            ): CollaboratorPayout {
                $row = new CollaboratorPayout;

                $row->forceFill([
                    'payout_no' => $this->numbers->next('finance.collaborator_payout_prefix', 'finance.collaborator_payout_next_number', '%06d'),
                    'idempotency_key' => $key,
                    'collaborator_id' => $collaborator->getKey(),
                    'requested_amount' => $requested,
                    // Set from the allocations below. It is never user input (INV-22).
                    'amount' => Money::ZERO,
                    'entry_count' => 0,
                    'method' => $data->method->value,
                    'payout_account_id' => $data->payoutAccountId,
                    'status' => $status->value,
                    'minimum_payout_snapshot' => Money::of((string) setting('collaborator.minimum_payout', '0.00')),
                    'available_at_request' => $snapshot->availableBalance,
                    'requested_by' => $actor?->getKey(),
                    'requested_at' => now(),
                    'statement_from' => $data->statementFrom?->toDateString(),
                    'statement_to' => $data->statementTo?->toDateString(),
                    'notes' => $data->notes === null ? null : mb_substr($data->notes, 0, 255),
                ])->save();

                return $row->refresh();
            });

            [$allocated, $count] = $this->allocate($collaborator, $payout, $requested);

            if (Money::compare($allocated, $requested) !== 0) {
                // Step 5: the whole transaction is abandoned rather than paying less than was asked
                // for. A payout that silently shrank is the kind of thing nobody notices but the
                // partner.
                throw CollaboratorRuleException::refuse('amount', sprintf(
                    'Only %s could be claimed from the ledger against a request for %s — %s short. The '
                    .'balance moved while this form was open. Nothing was written.',
                    Money::format($allocated),
                    Money::format($requested),
                    Money::format(Money::sub($requested, $allocated)),
                ));
            }

            $this->write($payout, ['amount' => $allocated, 'entry_count' => $count]);
            $this->log($payout, CollaboratorActivityEvent::PayoutRequest, 'requested', $data->notes);

            return $payout->refresh();
        }, 3);
    }

    /**
     * Steps 3 and 4: FIFO by value date, claimed with a compare-and-swap.
     *
     * @return array{0: string, 1: int}
     */
    private function allocate(Collaborator $collaborator, CollaboratorPayout $payout, string $requested): array
    {
        $remaining = $requested;
        $count = 0;

        foreach ($this->candidates($collaborator, locked: true) as $entry) {
            if (! Money::isPositive($remaining)) {
                break;
            }

            $slice = Money::min($remaining, $entry->payableRemaining());

            if (! Money::isPositive($slice)) {
                continue;
            }

            // The compare-and-swap. A zero-row result means a racing payout claimed it first, so this
            // one skips the entry and carries on — no waiting, and no way for both to have it.
            $claimed = $this->db->table($entry->getTable())
                ->where('id', $entry->getKey())
                ->where('status', CommissionStatus::Available->value)
                ->where('entry_type', LedgerEntryType::Credit->value)
                ->whereRaw('allocated_amount + ? + reversed_amount <= amount', [$slice])
                ->update([
                    'allocated_amount' => $this->db->raw('allocated_amount + '.$this->literal($slice)),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                continue;
            }

            CollaboratorPayoutAllocation::allowDirectWrites(function () use ($payout, $entry, $slice, $collaborator): void {
                (new CollaboratorPayoutAllocation)->forceFill([
                    'payout_id' => $payout->getKey(),
                    'ledger_entry_id' => $entry->getKey(),
                    'collaborator_id' => $collaborator->getKey(),
                    'amount' => $slice,
                    // Snapshotted so the statement can say what the entry was when it was claimed,
                    // even after the entry moves on.
                    'entry_transaction_date' => $entry->transaction_date->toDateString(),
                    'entry_status_at_allocation' => $entry->status->value,
                ])->save();
            });

            $remaining = Money::sub($remaining, $slice);
            $count++;
        }

        $allocated = Money::sub($requested, $remaining);

        if ($count > 0) {
            $wallet = $this->wallets->lockFor($collaborator);
            $this->wallets->applyAllocationDelta($wallet, AllocationDelta::reserved($allocated));
        }

        return [$allocated, $count];
    }

    /**
     * The candidate set of §6.4.2 step 3. **The WHERE clause is the guarantee, not a UI filter**:
     * pending and approved entries are structurally unreachable here, and a debit never appears
     * because its effect is already inside `available`.
     *
     * @return Collection<int, CollaboratorCommissionLedgerEntry>
     */
    private function candidates(Collaborator $collaborator, bool $locked)
    {
        $query = CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->payable(Carbon::now(Format::timezone()))
            // FIFO by value date: the oldest earnings are paid first, which is both the fairest order
            // and the one that leaves the clawback window on the newest money.
            ->orderBy('transaction_date')
            ->orderBy('id');

        if ($locked) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function assertPayable(Collaborator $collaborator, bool $frozen, string $available, string $requested): void
    {
        if ($collaborator->trashed() || $collaborator->status !== CollaboratorStatus::Active) {
            throw CollaboratorRuleException::refuse('collaborator_id', sprintf(
                '%s is %s, so nothing can be paid out.',
                (string) $collaborator->collaborator_code,
                $collaborator->trashed() ? 'deleted' : strtolower($collaborator->status->label()),
            ));
        }

        if ($frozen) {
            throw CollaboratorRuleException::refuse('amount',
                'This wallet is frozen. Earning continues; paying out does not, until somebody unfreezes it.');
        }

        if (! Money::isPositive($available)) {
            throw CollaboratorRuleException::refuse('amount', sprintf(
                'There is %s available. %s',
                Money::format($available),
                Money::isNegative($available)
                    ? 'The balance is negative after a clawback, so a payout would pay out money that is owed back.'
                    : 'Nothing has become available yet.',
            ));
        }

        if (Money::compare($requested, $available) === 1) {
            throw CollaboratorRuleException::refuse('amount', sprintf(
                '%s was requested and %s is available.',
                Money::format($requested), Money::format($available),
            ));
        }

        $minimum = Money::of((string) setting('collaborator.minimum_payout', '0.00'));

        if (Money::isPositive($minimum) && Money::compare($requested, $minimum) === -1) {
            throw CollaboratorRuleException::refuse('amount', sprintf(
                'The minimum payout is %s.', Money::format($minimum),
            ));
        }

        if ((bool) setting('collaborator.payout_single_inflight', true) && $this->hasInFlight($collaborator)) {
            throw CollaboratorRuleException::refuse('amount',
                'There is already a payout in flight for this partner. Settle or withdraw it first — two '
                .'open payouts against one balance is how the same commission gets promised twice.');
        }
    }

    private function hasInFlight(Collaborator $collaborator): bool
    {
        return CollaboratorPayout::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->whereIn('status', [
                PayoutStatus::Requested->value, PayoutStatus::Pending->value, PayoutStatus::Approved->value,
            ])
            ->exists();
    }

    /**
     * @param  list<PayoutStatus>  $from
     */
    private function close(
        CollaboratorPayout $payout,
        PayoutStatus $to,
        AllocationReleaseReason $reason,
        string $why,
        ?User $by,
        array $from,
    ): CollaboratorPayout {
        $why = trim($why);

        if ($why === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'Withdrawing a payout needs a reason: somebody was expecting this money.');
        }

        return $this->db->transaction(function () use ($payout, $to, $reason, $why, $by, $from): CollaboratorPayout {
            $locked = $this->lock($payout);

            if ($locked->status === $to) {
                return $locked;
            }

            if (! in_array($locked->status, $from, true)) {
                throw $this->refuse($locked, $to === PayoutStatus::Rejected ? 'rejected' : 'cancelled');
            }

            $released = Money::ZERO;

            foreach ($this->liveAllocations($locked) as $allocation) {
                $this->release($allocation, $reason, $by);
                $released = Money::add($released, (string) $allocation->amount);
            }

            $this->write($locked, array_merge(
                ['status' => $to->value],
                $to === PayoutStatus::Rejected
                    ? ['rejected_by' => $by?->getKey(), 'rejected_at' => now(), 'rejection_reason' => mb_substr($why, 0, 255)]
                    : ['cancelled_by' => $by?->getKey(), 'cancelled_at' => now(), 'cancellation_reason' => mb_substr($why, 0, 255)],
            ));

            if (Money::isPositive($released)) {
                $wallet = $this->wallets->lockFor($locked->collaborator);
                $this->wallets->applyAllocationDelta($wallet, AllocationDelta::released($released));
            }

            $this->log($locked, CollaboratorActivityEvent::PayoutRequest, $to->value, $why);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Give an entry its slice back.
     *
     * The mirror compare-and-swap of the claim: `allocated_amount - :x WHERE allocated_amount >= :x`,
     * so a release can never drive the column negative however many times it is retried. The
     * allocation row is **never deleted** — `is_released` is what makes it stop counting, and the row
     * is the evidence that it once did.
     */
    private function release(CollaboratorPayoutAllocation $allocation, AllocationReleaseReason $reason, ?User $by): void
    {
        if ((bool) $allocation->is_released) {
            return;
        }

        $amount = Money::of((string) $allocation->amount);

        $this->db->table('collaborator_commission_ledger_entries')
            ->where('id', $allocation->ledger_entry_id)
            ->whereRaw('allocated_amount >= ?', [$amount])
            ->update([
                'allocated_amount' => $this->db->raw('allocated_amount - '.$this->literal($amount)),
                'updated_at' => now(),
            ]);

        CollaboratorPayoutAllocation::allowDirectWrites(static function () use ($allocation, $reason, $by): void {
            $allocation->forceFill([
                'is_released' => true,
                'released_at' => now(),
                'release_reason' => $reason->value,
                'released_by' => $by?->getKey(),
            ])->save();
        });
    }

    /**
     * @return Collection<int, CollaboratorPayoutAllocation>
     */
    private function liveAllocations(CollaboratorPayout $payout)
    {
        return CollaboratorPayoutAllocation::query()
            ->where('payout_id', $payout->getKey())
            ->where('is_released', 0)
            ->orderBy('id')
            ->get();
    }

    private function lock(CollaboratorPayout $payout): CollaboratorPayout
    {
        return CollaboratorPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(CollaboratorPayout $payout, array $attributes): void
    {
        CollaboratorPayout::allowDirectWrites(static function () use ($payout, $attributes): void {
            $payout->forceFill($attributes)->save();
        });
    }

    private function refuse(CollaboratorPayout $payout, string $act): CollaboratorRuleException
    {
        return CollaboratorRuleException::refuse('status', sprintf(
            'Payout %s is %s and cannot be %s.',
            (string) $payout->payout_no, $payout->status->label(), $act,
        ));
    }

    private function log(CollaboratorPayout $payout, CollaboratorActivityEvent $event, string $what, ?string $reason): void
    {
        $collaborator = $payout->collaborator;

        if ($collaborator === null) {
            return;
        }

        $this->activity->record($event, $collaborator, $payout, [
            'payout_no' => (string) $payout->payout_no,
            'transition' => $what,
            'amount' => (string) $payout->amount,
            'status' => $payout->status->value,
        ], $reason);
    }

    private function systemActor(): User
    {
        $actor = auth()->user();

        if ($actor instanceof User) {
            return $actor;
        }

        throw new LogicException(
            'Auto-approval needs an actor to record. Pass one explicitly when calling request() outside '
            .'an authenticated context.'
        );
    }

    private function literal(string $amount): string
    {
        if (preg_match('/^-?\d{1,15}\.\d{2}$/', $amount) !== 1) {
            throw new LogicException(sprintf('[%s] is not a money value this class produced.', $amount));
        }

        return $amount;
    }
}
