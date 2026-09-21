<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\AllocationDelta;
use App\DataObjects\Collaborator\LedgerDelta;
use App\DataObjects\Collaborator\WalletSnapshot;
use App\Enums\PayoutStatus;
use App\Events\Collaborator\WalletRecalculated;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorWallet;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * The wallet, and **the only definition of a balance in this system** (spine §6.5, §117, INV-26).
 *
 * Built in two instalments against one class (D72): Phase 10 shipped `lockFor()` and `applyDelta()`,
 * because `LedgerWriter` must move the cache inside the ledger insert's own transaction — a committed
 * entry whose wallet write lands later is a window in which `wallet != SUM(ledger)`. Phase 12 adds the
 * reporting half below, now that there are rows to report on.
 *
 * **The wallet is a cache and nothing more.** `derive()` is the truth; the stored row is a copy kept
 * for speed, and `recalculate()` rewrites it from the ledger without touching a single entry. Anything
 * that prints a balance — a screen, a report, a notification, a dashboard widget, an export — reads
 * one of these. A second definition would be a second number, and two numbers about one partner's
 * money is exactly what this invariant exists to prevent.
 */
final class CollaboratorWalletService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The collaborator's wallet row, opening it on first use, **locked**.
     *
     * A wallet is not created at onboarding: a partner who never earned anything has no balance to
     * cache, and a table full of zero rows is a table somebody eventually sums. It appears the first
     * time money does.
     */
    public function lockFor(Collaborator $collaborator): CollaboratorWallet
    {
        $this->assertInTransaction('lockFor');

        $wallet = $this->lock($collaborator);

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            (new CollaboratorWallet)->forceFill([
                'collaborator_id' => $collaborator->getKey(),
                'currency' => Money::currencyCode(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // `uq_cw_collaborator` did its job against a racing worker; theirs is as good as ours.
        }

        $wallet = $this->lock($collaborator);

        if ($wallet === null) {
            throw new LogicException(sprintf(
                'The wallet for collaborator #%s was created and immediately could not be locked.',
                (string) $collaborator->getKey(),
            ));
        }

        return $wallet;
    }

    /**
     * The cached row as it stands, without locking anything. Null when the partner never earned.
     *
     * The read-only twin of {@see lockFor()}, for screens and reports. It deliberately does **not**
     * create the row: a wallet appears the first time money does, and a GET request that wrote a row
     * would mean every list screen quietly populating a table nobody asked it to.
     */
    public function for(Collaborator $collaborator): ?CollaboratorWallet
    {
        return CollaboratorWallet::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->first();
    }

    /**
     * Move the cache by what one ledger row just did.
     *
     * Called only from {@see LedgerWriter} and (from Phase 12) `PayoutService`, always inside their
     * transaction and under this row's lock. Every column moves by a **relative** expression rather
     * than being assigned a computed absolute: two workers crediting two entries to one partner in the
     * same instant then sum correctly even if one of them read the row a moment before the other wrote
     * it, and `version` is what tells a reader the row moved underneath them.
     */
    public function applyDelta(CollaboratorWallet $wallet, LedgerDelta $delta): void
    {
        $this->assertInTransaction('applyDelta');

        $updates = [
            'version' => $this->db->raw('version + 1'),
            'updated_at' => now(),
        ];

        foreach ($delta->columns() as $column => $change) {
            $updates[$column] = $this->db->raw(sprintf('%s + %s', $column, $this->literal($change)));
        }

        if ($delta->isNew) {
            $updates['ledger_entry_count'] = $this->db->raw('ledger_entry_count + 1');
        }

        // `last_entry_*` only ever moves forward. A status change on an old entry is a real wallet
        // movement, but it is not a newer entry, and a reader using these two columns to ask "has
        // anything happened since I last looked" would be told the wrong thing.
        if ($delta->isNew && $delta->entryId !== null) {
            $updates['last_entry_id'] = $delta->entryId;
            $updates['last_entry_at'] = $delta->entryAt ?? now();
        }

        $this->db->table($wallet->getTable())->where('id', $wallet->getKey())->update($updates);

        $wallet->refresh();
    }

    /**
     * Move the cache by what one payout movement just did (spine §6.5.1 part B).
     *
     * The allocation-side twin of {@see applyDelta()}. It exists separately because the identity
     * splits there: `payable_total` comes from the ledger while `reserved` and `paid` come from live
     * allocations, so an entry going `available -> paid` moves nothing on the ledger side and a real
     * amount between the allocation buckets.
     */
    public function applyAllocationDelta(CollaboratorWallet $wallet, AllocationDelta $delta): void
    {
        $this->assertInTransaction('applyAllocationDelta');

        $columns = $delta->columns();

        if ($columns === []) {
            return;
        }

        $updates = ['version' => $this->db->raw('version + 1'), 'updated_at' => now()];

        foreach ($columns as $column => $change) {
            $updates[$column] = $this->db->raw(sprintf('%s + %s', $column, $this->literal($change)));
        }

        $this->db->table($wallet->getTable())->where('id', $wallet->getKey())->update($updates);

        $wallet->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | The canonical derivation (spine §6.5.1) - Phase 12
    |--------------------------------------------------------------------------
    */

    /**
     * A partner's balance, computed from the ledger and the allocations. **Nothing else in the system
     * may compute one** (INV-26).
     *
     * Two queries, exactly the ones spine §6.5.1 writes out: part A over the ledger, part B over live
     * allocations joined to their payouts, then `available = payable - reserved - paid`. The only
     * column part A ever sums is `signed_amount`, so no query here can get a direction wrong - the
     * direction lives in the generated column, not in a CASE somebody wrote by hand.
     */
    public function derive(Collaborator $collaborator): WalletSnapshot
    {
        $ledger = $this->ledgerTotals((int) $collaborator->getKey());
        $allocations = $this->allocationTotals((int) $collaborator->getKey());

        $payable = Money::of((string) $ledger->payable_total);
        $reserved = Money::of((string) $allocations->reserved);
        $paid = Money::of((string) $allocations->paid);

        return new WalletSnapshot(
            pendingBalance: Money::of((string) $ledger->pending_balance),
            // Derived, never stored independently: `payable - reserved - paid` is what "could be paid
            // out right now" means, and a stored column would be a fourth place it could disagree.
            availableBalance: Money::sub(Money::sub($payable, $reserved), $paid),
            reservedBalance: $reserved,
            paidBalance: $paid,
            lifetimeEarned: Money::of((string) $ledger->lifetime_earned),
            totalStudentCommission: Money::of((string) $ledger->total_student_commission),
            totalProjectCommission: Money::of((string) $ledger->total_project_commission),
            totalAdjustments: Money::of((string) $ledger->total_adjustments),
            totalReversed: Money::of((string) $ledger->total_reversed),
            totalPaidOut: $paid,
            ledgerEntryCount: (int) $ledger->ledger_entry_count,
        );
    }

    /**
     * Rewrite the cache from the ledger. Idempotent, and it **never touches a ledger row**.
     *
     * This is the repair path, and its safety is that it can only move the copy. A drift is a cache
     * that fell behind - a worker killed between the insert and the delta, a historical import, a
     * restored dump - and the fix is to recompute, never to adjust an entry until the sums agree.
     *
     * `$persist = false` gives a screen the same figures without writing, which is what a reconciler
     * running as a pure reader needs.
     */
    public function recalculate(Collaborator $collaborator, bool $persist = true): WalletSnapshot
    {
        $snapshot = $this->derive($collaborator);

        if (! $persist) {
            return $snapshot;
        }

        $this->db->transaction(function () use ($collaborator, $snapshot): void {
            $wallet = $this->lockFor($collaborator);
            $drift = $snapshot->driftFrom($wallet->only(array_keys($snapshot->columns())));

            $this->db->table($wallet->getTable())
                ->where('id', $wallet->getKey())
                ->update(array_merge($snapshot->columns(), [
                    'version' => $this->db->raw('version + 1'),
                    'recalculated_at' => now(),
                    'drift_amount' => $drift,
                    'updated_at' => now(),
                ]));
        }, 3);

        WalletRecalculated::dispatch($collaborator->refresh(), $snapshot);

        return $snapshot;
    }

    /**
     * Stop new payouts without stopping earning.
     *
     * The two are genuinely separate: a partner under investigation keeps accruing what they are owed,
     * and the business simply does not pay it out yet. Freezing what they earn as well would be a
     * different and much heavier decision, and the ledger would have no way to record that it had been
     * made.
     */
    public function freeze(Collaborator $collaborator, bool $frozen, ?string $reason = null): CollaboratorWallet
    {
        $reason = $reason === null ? null : trim($reason);

        if ($frozen && ($reason === null || $reason === '')) {
            throw new LogicException(
                'Freezing a wallet stops a partner being paid. The reason is what they are told when '
                .'they ask, and there is no second place to look it up.'
            );
        }

        return $this->db->transaction(function () use ($collaborator, $frozen, $reason): CollaboratorWallet {
            $wallet = $this->lockFor($collaborator);

            $wallet->forceFill([
                'is_frozen' => $frozen,
                // The reason is kept on an unfreeze too: why it *was* frozen is the part somebody asks
                // about afterwards, and clearing it would erase the only record of the decision.
                'frozen_reason' => $frozen ? mb_substr($reason ?? '', 0, 255) : $wallet->frozen_reason,
            ])->save();

            return $wallet->refresh();
        }, 3);
    }

    /**
     * Throw unless the stored row equals the derivation. The helper every money test ends with.
     */
    public function assertConsistent(Collaborator $collaborator): void
    {
        $wallet = CollaboratorWallet::query()->where('collaborator_id', $collaborator->getKey())->first();
        $snapshot = $this->derive($collaborator);

        if ($wallet === null) {
            if ($snapshot->ledgerEntryCount > 0) {
                throw new LogicException(sprintf(
                    'Collaborator #%s has %d ledger entries and no wallet row.',
                    (string) $collaborator->getKey(),
                    $snapshot->ledgerEntryCount,
                ));
            }

            return;
        }

        $differences = $snapshot->differencesFrom($wallet->only(array_keys($snapshot->columns())));

        if ($differences !== []) {
            $lines = [];

            foreach ($differences as $column => [$was, $now, $difference]) {
                $lines[] = sprintf('%s: cached %s, ledger says %s (%s)', $column, $was, $now, $difference);
            }

            throw new LogicException(sprintf(
                'The wallet for collaborator #%s disagrees with its ledger — %s.',
                (string) $collaborator->getKey(),
                implode('; ', $lines),
            ));
        }

        if (! $snapshot->identityHolds()) {
            throw new LogicException(sprintf(
                'The closed identity fails for collaborator #%s: lifetime %s but the buckets sum to %s.',
                (string) $collaborator->getKey(),
                $snapshot->lifetimeEarned,
                $snapshot->bucketSum(),
            ));
        }
    }

    /**
     * Total money **actually paid out**, over a date range (F-4.8, ND-6).
     *
     * Derived from live allocations on `paid` payouts, never from `SUM(collaborator_payouts.amount)`
     * and never from the payout's status alone (INV-23): a payout row's amount is what was requested,
     * and what left the company is what the allocations say.
     *
     * **`$collaborator = null` means company-wide** — the same SQL with the id predicate dropped, one
     * query and never a loop, which is what makes the company figure *equal* to the sum of the
     * per-partner figures rather than merely close to it. The first parameter has no default so that
     * company-wide is always something a caller typed, and a null collaborator requires a range.
     */
    public function payoutsPaidTotal(?Collaborator $collaborator, ?DateRange $range = null): string
    {
        if ($collaborator === null && $range === null) {
            throw new LogicException(
                'payoutsPaidTotal(null, null) would sum every payout the business has ever made, which '
                .'is never the question being asked. Name a collaborator, a range, or both.'
            );
        }

        $query = $this->db->table('collaborator_payout_allocations as a')
            ->join('collaborator_payouts as p', 'p.id', '=', 'a.payout_id')
            ->where('a.is_released', 0)
            ->where('p.status', PayoutStatus::Paid->value);

        if ($collaborator !== null) {
            $query->where('a.collaborator_id', $collaborator->getKey());
        }

        if ($range !== null) {
            $range->applyDates($query, 'p.paid_on');
        }

        return Money::of((string) ($query->sum('a.amount') ?: Money::ZERO));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Spine §6.5.1 query A, verbatim. The only column summed is `signed_amount` (or, for the reversed
     * memo, the positive magnitude), so no CASE here can invert a direction.
     */
    private function ledgerTotals(int $collaboratorId): object
    {
        $row = $this->db->table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $collaboratorId)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN signed_amount ELSE 0 END), 0) AS pending_balance")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('available','paid') THEN signed_amount ELSE 0 END), 0) AS payable_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN signed_amount ELSE 0 END), 0) AS lifetime_earned")
            ->selectRaw("COALESCE(SUM(CASE WHEN purpose = 'student_commission' AND status <> 'cancelled' THEN signed_amount ELSE 0 END), 0) AS total_student_commission")
            ->selectRaw("COALESCE(SUM(CASE WHEN purpose = 'project_commission' AND status <> 'cancelled' THEN signed_amount ELSE 0 END), 0) AS total_project_commission")
            ->selectRaw("COALESCE(SUM(CASE WHEN purpose IN ('manual_adjustment','write_off') AND status <> 'cancelled' THEN signed_amount ELSE 0 END), 0) AS total_adjustments")
            ->selectRaw("COALESCE(SUM(CASE WHEN purpose IN ('reversal','clawback') AND status <> 'cancelled' THEN amount ELSE 0 END), 0) AS total_reversed")
            ->selectRaw('COUNT(*) AS ledger_entry_count')
            ->first();

        return $row ?? (object) [
            'pending_balance' => '0.00', 'payable_total' => '0.00', 'lifetime_earned' => '0.00',
            'total_student_commission' => '0.00', 'total_project_commission' => '0.00',
            'total_adjustments' => '0.00', 'total_reversed' => '0.00', 'ledger_entry_count' => 0,
        ];
    }

    /**
     * Spine §6.5.1 query B. `is_released = 0` is what makes a released allocation stop counting the
     * moment a payout is rejected or cancelled, without deleting the row that records it happened.
     */
    private function allocationTotals(int $collaboratorId): object
    {
        $row = $this->db->table('collaborator_payout_allocations as a')
            ->join('collaborator_payouts as p', 'p.id', '=', 'a.payout_id')
            ->where('a.collaborator_id', $collaboratorId)
            ->where('a.is_released', 0)
            ->selectRaw("COALESCE(SUM(CASE WHEN p.status IN ('requested','pending','approved') THEN a.amount ELSE 0 END), 0) AS reserved")
            ->selectRaw("COALESCE(SUM(CASE WHEN p.status = 'paid' THEN a.amount ELSE 0 END), 0) AS paid")
            ->first();

        return $row ?? (object) ['reserved' => '0.00', 'paid' => '0.00'];
    }

    private function lock(Collaborator $collaborator): ?CollaboratorWallet
    {
        return CollaboratorWallet::query()
            ->where('collaborator_id', $collaborator->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * A money string this process produced, safe to inline in a `SET x = x + …` fragment. The pattern
     * is the assertion: anything that is not a signed decimal at money scale never reaches SQL.
     */
    private function literal(string $amount): string
    {
        if (preg_match('/^-?\d{1,15}\.\d{2}$/', $amount) !== 1) {
            throw new LogicException(sprintf('[%s] is not a money value this class produced.', $amount));
        }

        return $amount;
    }

    private function assertInTransaction(string $method): void
    {
        if ($this->db->connection()->transactionLevel() < 1) {
            throw new LogicException(sprintf(
                'CollaboratorWalletService::%s() must run inside the transaction that wrote the ledger '
                .'row. A committed entry whose wallet write lands separately leaves a window where the '
                .'cache disagrees with the ledger, which is the one thing the reconciler exists to '
                .'prove cannot happen.',
                $method,
            ));
        }
    }
}
