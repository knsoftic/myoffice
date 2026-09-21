<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\BulkResult;
use App\DataObjects\Collaborator\LedgerDelta;
use App\Enums\CollaboratorActivityEvent;
use App\Enums\CommissionStatus;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\User;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * The §2.18.7 transitions of a ledger entry, and nothing else (phase-10-12 §6.3).
 *
 * **Forward-only and idempotent.** Approving an already-approved entry is a no-op, so a double-clicked
 * bulk action cannot double-count and a retried request cannot move a row twice. There is no path back
 * to `pending`, none from `available` to `approved`, and no un-paying of a commission whose payout
 * really settled — that case posts an offsetting `clawback` debit instead (§6.3.5).
 *
 * **A rejected entry is cancelled, not deleted.** The amount leaves every bucket and the row stays,
 * because a partner who asks why they were not paid for a particular student deserves a row with a
 * reason on it rather than an absence.
 *
 * Every status move goes through `CollaboratorWalletService::applyDelta()` in the same transaction, so
 * the cache follows the ledger by construction: the bucket arithmetic is the canonical SQL's own
 * predicates applied to one row, not a second opinion about where an `approved` entry sits.
 */
final class CommissionApprovalService
{
    /** [D-IMP-6]: a bulk action names its rows, and there are never more than this many. */
    private const BULK_LIMIT = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorWalletService $wallets,
        private readonly CollaboratorActivityService $activity,
        private readonly CommissionEntitlementService $entitlements,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | One entry
    |--------------------------------------------------------------------------
    */

    /**
     * `pending` → `approved`, and straight on to `available` when nothing is holding it.
     *
     * The hold is read from the entry's own `hold_until`, which was stamped from the **snapshotted**
     * hold days at posting time. A business that shortens its hold policy today does not retroactively
     * release last month's entries, and one that lengthens it does not retroactively re-hold them.
     */
    public function approve(CollaboratorCommissionLedgerEntry $entry, User $by): CollaboratorCommissionLedgerEntry
    {
        return $this->db->transaction(function () use ($entry, $by): CollaboratorCommissionLedgerEntry {
            $locked = $this->lock($entry);

            // Idempotent: already past this point is success, not an error.
            if ($locked->status === CommissionStatus::Approved || $locked->status === CommissionStatus::Available) {
                return $locked;
            }

            if ($locked->status !== CommissionStatus::Pending) {
                throw $this->refuse($locked, 'approved');
            }

            $this->move($locked, CommissionStatus::Approved, [
                'approved_by' => $by->getKey(),
                'approved_at' => now(),
            ]);

            $this->log($locked, CollaboratorActivityEvent::CommissionApproved, 'approved', [
                'approved_by' => $by->getKey(),
            ]);

            if ($this->holdHasPassed($locked)) {
                // Two activity rows, deliberately: "approved" and "released" are different facts about
                // different moments, and a partner reading one row that says both cannot tell whether
                // their money was ever held.
                $this->move($locked, CommissionStatus::Available, ['available_at' => now()]);
                $this->log($locked, CollaboratorActivityEvent::CommissionApproved, 'released', []);
            }

            return $locked->refresh();
        }, 3);
    }

    /**
     * `pending` / `approved` → `cancelled`. The reason is mandatory and the source receipt is untouched.
     */
    public function reject(CollaboratorCommissionLedgerEntry $entry, string $reason, User $by): CollaboratorCommissionLedgerEntry
    {
        return $this->cancelInternal($entry, $reason, $by, [CommissionStatus::Pending, CommissionStatus::Approved], 'rejected');
    }

    /**
     * `available` → `cancelled`, refused while a payout has claimed any of it.
     *
     * Cancelling an entry a payout is counting on would leave that payout funded by money the ledger
     * says no longer exists. The payout is withdrawn first; then the entry can be cancelled.
     */
    public function cancel(CollaboratorCommissionLedgerEntry $entry, string $reason, User $by): CollaboratorCommissionLedgerEntry
    {
        return $this->cancelInternal($entry, $reason, $by, [CommissionStatus::Available], 'cancelled');
    }

    /**
     * `approved` → `available` once the hold has passed. The only caller is `commissions:release-held`.
     */
    public function release(CollaboratorCommissionLedgerEntry $entry): CollaboratorCommissionLedgerEntry
    {
        return $this->db->transaction(function () use ($entry): CollaboratorCommissionLedgerEntry {
            $locked = $this->lock($entry);

            if ($locked->status === CommissionStatus::Available) {
                return $locked;
            }

            if ($locked->status !== CommissionStatus::Approved || ! $this->holdHasPassed($locked)) {
                return $locked;
            }

            $this->move($locked, CommissionStatus::Available, ['available_at' => now()]);
            $this->log($locked, CollaboratorActivityEvent::CommissionApproved, 'released', []);

            return $locked->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Many entries ([D-IMP-6])
    |--------------------------------------------------------------------------
    */

    /**
     * Approve explicit ids — **never "everything matching the filter"**.
     *
     * A filter evaluated on the server is a filter whose contents the person pressing the button never
     * saw. The screen sends the ids it displayed; anything that has moved since is skipped and
     * reported. The whole batch is one transaction, so a failure leaves nothing half-approved, and the
     * rows are locked in ascending id order to match the global lock order.
     *
     * @param  list<int>  $ids
     */
    public function approveMany(array $ids, User $by): BulkResult
    {
        return $this->bulk($ids, 'bulk_approved', function (CollaboratorCommissionLedgerEntry $entry) use ($by): void {
            $this->approveLocked($entry, $by);
        }, [CommissionStatus::Pending]);
    }

    /**
     * @param  list<int>  $ids
     */
    public function rejectMany(array $ids, string $reason, User $by): BulkResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'Rejecting commission needs a reason. It is what the partner is shown in place of the money.');
        }

        return $this->bulk($ids, 'bulk_rejected', function (CollaboratorCommissionLedgerEntry $entry) use ($reason, $by): void {
            $this->cancelLocked($entry, $reason, $by, 'rejected');
        }, [CommissionStatus::Pending, CommissionStatus::Approved], $reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<int>  $ids
     * @param  list<CommissionStatus>  $eligible
     */
    private function bulk(array $ids, string $event, \Closure $act, array $eligible, ?string $reason = null): BulkResult
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return new BulkResult([], [], Money::ZERO);
        }

        if (count($ids) > self::BULK_LIMIT) {
            throw CollaboratorRuleException::refuse('entries', sprintf(
                'A bulk action covers at most %d entries at a time. %d were sent — narrow the filter '
                .'and do it in batches, so that what was approved is always what somebody looked at.',
                self::BULK_LIMIT,
                count($ids),
            ));
        }

        sort($ids);

        return $this->db->transaction(function () use ($ids, $event, $act, $eligible, $reason): BulkResult {
            $entries = CollaboratorCommissionLedgerEntry::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $changed = [];
            $skipped = [];
            $total = Money::ZERO;

            foreach ($entries as $entry) {
                if (! in_array($entry->status, $eligible, true)) {
                    $skipped[(int) $entry->getKey()] = sprintf(
                        '%s is %s — it moved after the page was loaded.',
                        (string) $entry->reference,
                        $entry->status->label(),
                    );

                    continue;
                }

                $act($entry);

                $changed[] = (int) $entry->getKey();
                $total = Money::add($total, (string) $entry->amount);
            }

            foreach (array_diff($ids, $entries->modelKeys()) as $missing) {
                $skipped[(int) $missing] = 'That entry no longer exists.';
            }

            $this->logBatch($event, $changed, $skipped, $total, $reason);

            return new BulkResult($changed, $skipped, $total);
        }, 3);
    }

    private function approveLocked(CollaboratorCommissionLedgerEntry $entry, User $by): void
    {
        $this->move($entry, CommissionStatus::Approved, [
            'approved_by' => $by->getKey(),
            'approved_at' => now(),
        ]);

        $this->log($entry, CollaboratorActivityEvent::CommissionApproved, 'approved', ['approved_by' => $by->getKey()]);

        if ($this->holdHasPassed($entry)) {
            $this->move($entry, CommissionStatus::Available, ['available_at' => now()]);
            $this->log($entry, CollaboratorActivityEvent::CommissionApproved, 'released', []);
        }
    }

    /**
     * @param  list<CommissionStatus>  $from
     */
    private function cancelInternal(
        CollaboratorCommissionLedgerEntry $entry,
        string $reason,
        User $by,
        array $from,
        string $event,
    ): CollaboratorCommissionLedgerEntry {
        $reason = trim($reason);

        if ($reason === '') {
            throw CollaboratorRuleException::reasonRequired('reason',
                'Cancelling commission needs a reason. The row stays for ever and the reason is what it says.');
        }

        return $this->db->transaction(function () use ($entry, $reason, $by, $from, $event): CollaboratorCommissionLedgerEntry {
            $locked = $this->lock($entry);

            if ($locked->status === CommissionStatus::Cancelled) {
                return $locked;
            }

            if (! in_array($locked->status, $from, true)) {
                throw $this->refuse($locked, $event);
            }

            $this->cancelLocked($locked, $reason, $by, $event);

            return $locked->refresh();
        }, 3);
    }

    private function cancelLocked(CollaboratorCommissionLedgerEntry $entry, string $reason, User $by, string $event): void
    {
        if (Money::isPositive((string) $entry->allocated_amount)) {
            throw CollaboratorRuleException::refuse('entry', sprintf(
                '%s of %s is claimed by a payout that has not been withdrawn. Cancelling it would leave '
                .'that payout funded by money the ledger says is gone — withdraw the payout first.',
                Money::format((string) $entry->allocated_amount),
                (string) $entry->reference,
            ));
        }

        $this->move($entry, CommissionStatus::Cancelled, [
            'cancelled_at' => now(),
            'cancelled_by' => $by->getKey(),
            'cancel_reason' => mb_substr($reason, 0, 255),
        ]);

        $this->returnToPromise($entry);

        $this->log($entry, CollaboratorActivityEvent::CommissionReversed, $event, ['reason' => $reason], $reason);
    }

    /**
     * Give the entitlement back what a cancelled entry had claimed against it.
     *
     * A rejection is not a reversal: the row leaves every bucket with a reason on it and no debit is
     * written. But the promise it was released against still records the release, and leaving it there
     * would cap the partner at a figure they never actually received — a PKR 2,000 fixed commission
     * whose first instalment was rejected could then only ever reach PKR 1,333.
     *
     * Only the part that was still standing goes back. Anything already reversed or clawed back was
     * returned by `unrelease()` when its debit was written, and returning it twice would open headroom
     * the promise never had.
     */
    private function returnToPromise(CollaboratorCommissionLedgerEntry $entry): void
    {
        $entitlement = $entry->entitlement;

        if ($entitlement === null || ! $entry->purpose->isEarning()) {
            return;
        }

        $standing = Money::max(Money::ZERO, Money::sub(
            (string) $entry->amount,
            Money::add((string) $entry->reversed_amount, (string) $entry->clawed_back_amount),
        ));

        $baseShare = Money::isZero((string) $entry->amount)
            ? Money::ZERO
            : Money::prorate((string) $entry->base_amount, $standing, (string) $entry->amount);

        $this->entitlements->unclaim($entitlement, $standing, $baseShare);
    }

    /**
     * Write the status and move the wallet in the same breath.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function move(CollaboratorCommissionLedgerEntry $entry, CommissionStatus $to, array $attributes): void
    {
        $from = $entry->status;

        if ($from === $to) {
            return;
        }

        CollaboratorCommissionLedgerEntry::allowDirectWrites(function () use ($entry, $to, $attributes): void {
            $entry->forceFill(array_merge($attributes, ['status' => $to->value]))->save();
        });

        $wallet = $this->wallets->lockFor($entry->collaborator);
        $this->wallets->applyDelta($wallet, LedgerDelta::forStatusChange($entry, $from));
    }

    private function lock(CollaboratorCommissionLedgerEntry $entry): CollaboratorCommissionLedgerEntry
    {
        return CollaboratorCommissionLedgerEntry::query()
            ->whereKey($entry->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function holdHasPassed(CollaboratorCommissionLedgerEntry $entry): bool
    {
        return $entry->hold_until === null
            || $entry->hold_until->lessThanOrEqualTo(Carbon::now(Format::timezone())->startOfDay());
    }

    private function refuse(CollaboratorCommissionLedgerEntry $entry, string $act): CollaboratorRuleException
    {
        return CollaboratorRuleException::refuse('status', sprintf(
            '%s is %s and cannot be %s. The ledger moves forward only: `reversed` and `cancelled` are '
            .'terminal, and a commission whose payout really settled is corrected by a clawback rather '
            .'than un-paid.',
            (string) $entry->reference,
            $entry->status->label(),
            $act,
        ));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(
        CollaboratorCommissionLedgerEntry $entry,
        CollaboratorActivityEvent $event,
        string $what,
        array $properties,
        ?string $reason = null,
    ): void {
        $collaborator = $entry->collaborator;

        if ($collaborator === null) {
            return;
        }

        $this->activity->record($event, $collaborator, $entry, array_merge($properties, [
            'transition' => $what,
            'status' => $entry->status->value,
            'amount' => (string) $entry->amount,
        ]), $reason);
    }

    /**
     * One batch summary row beside the per-entry rows, so "who approved these forty" is answerable
     * without reading forty rows and inferring it from timestamps.
     *
     * @param  list<int>  $changed
     * @param  array<int, string>  $skipped
     */
    private function logBatch(string $event, array $changed, array $skipped, string $total, ?string $reason): void
    {
        activity()
            ->withProperties([
                'count' => count($changed),
                'skipped' => count($skipped),
                'total_amount' => $total,
                'entry_ids' => $changed,
            ])
            ->event('commission.'.$event)
            ->log(sprintf('%d commission %s in one action, %d skipped.',
                count($changed), count($changed) === 1 ? 'entry' : 'entries', count($skipped)))
            ->forceFill(array_filter([
                'module' => 'collaborator_commissions',
                'reason' => $reason,
            ]))->save();
    }
}
