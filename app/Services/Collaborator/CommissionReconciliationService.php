<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\ReconciliationFinding;
use App\DataObjects\Collaborator\ReconciliationReport;
use App\Enums\CommissionProcessingState;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\PayoutStatus;
use App\Enums\ReconciliationStatus;
use App\Events\Collaborator\WalletDriftDetected;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorWallet;
use App\Models\Collaborator\CollaboratorWalletReconciliation;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Proves that every wallet still equals its ledger (finance spine §6.5.3).
 *
 * **A pure reader unless `repair` is passed, and even then it only ever rewrites the cache.** When the
 * wallet and the ledger disagree the ledger is right by definition — it is the thing the wallet is a
 * cache of — so the repair path is `recalculate()` and nothing else. A "repair" that adjusted a
 * commission entry would be the system quietly deciding what somebody earned.
 *
 * Eight checks, two severities (§6.5.4). R1 and R8 are **drift**: the cache fell behind, or a payment
 * was processed without leaving a trace of why nothing was posted. Both are recoverable, and both are
 * reported rather than silently fixed on the nightly run, because a cache that fell behind once will
 * fall behind again and an auto-repair would hide the reason. R2–R7 are **structural failures**: the
 * ledger itself does not hold together, nothing is repaired at all, and only a human with
 * `collaborator_commissions.create` may post a `manual_adjustment` with a written reason.
 *
 * Every run writes a row whether it passed or not. The history of being provably correct is itself the
 * evidence §50 asks for — a reconciliation table containing only failures proves nothing about the days
 * it says nothing about.
 */
class CommissionReconciliationService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorWalletService $wallets,
    ) {}

    /**
     * Run the eight checks and record the result.
     *
     * `$collaborator = null` means every collaborator, chunked, under **one** `run_uuid` — the uuid is
     * what makes "the run of 12 March" a thing you can query, rather than a scatter of rows that
     * happen to share a date.
     *
     * The spine writes this as `run(?Collaborator, string, bool): ReconciliationReport`. One report
     * cannot describe a thousand wallets, so the per-collaborator reader is {@see check()} and this
     * returns the reports it wrote, in the order it wrote them.
     *
     * @return list<ReconciliationReport>
     */
    public function run(?Collaborator $collaborator, string $runType = 'manual', bool $repair = false): array
    {
        $runUuid = (string) Str::uuid();

        if ($collaborator !== null) {
            return [$this->reconcile($collaborator, $runUuid, $runType, $repair)];
        }

        $reports = [];

        Collaborator::query()
            ->withTrashed()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$reports, $runUuid, $runType, $repair): void {
                foreach ($chunk as $one) {
                    $reports[] = $this->reconcile($one, $runUuid, $runType, $repair);
                }
            });

        return $reports;
    }

    /**
     * The eight checks, and nothing else: no row written, no event fired, no repair.
     *
     * This is what the test helper and every screen call. Separating it from {@see run()} is what lets
     * `assertWalletMatchesLedger()` in the suite and the nightly job share one implementation without
     * the suite filling a table with reconciliation rows.
     */
    public function check(Collaborator $collaborator): ReconciliationReport
    {
        $startedAt = hrtime(true);

        $snapshot = $this->wallets->derive($collaborator);
        $wallet = CollaboratorWallet::query()->where('collaborator_id', $collaborator->getKey())->first();
        $stored = $wallet === null ? [] : $wallet->only(array_keys($snapshot->columns()));

        $id = (int) $collaborator->getKey();
        $findings = [];

        // ---- R1: the cache equals the derivation, to the paisa ------------------------------------
        $drift = $wallet === null ? Money::ZERO : $snapshot->driftFrom($stored);
        $differences = $wallet === null ? [] : $snapshot->differencesFrom($stored);

        if ($wallet === null && $snapshot->ledgerEntryCount > 0) {
            $findings[] = ReconciliationFinding::failed('R2',
                sprintf('%d ledger entries and no wallet row at all', $snapshot->ledgerEntryCount));
        }

        if ($differences !== []) {
            $findings[] = ReconciliationFinding::drift('R1',
                'the cache disagrees with the ledger by '.Money::format($drift),
                ['columns' => array_map(
                    static fn (array $d): array => ['stored' => $d[0], 'derived' => $d[1], 'difference' => $d[2]],
                    $differences,
                )]);
        }

        if ($wallet !== null && (int) $wallet->ledger_entry_count !== $snapshot->ledgerEntryCount) {
            // Worth its own finding: a drift of zero with a row count that disagrees means an entry
            // vanished and its sum happened to cancel, which is worse than a visible difference.
            $findings[] = ReconciliationFinding::drift('R1',
                sprintf('%d entries counted, the wallet says %d',
                    $snapshot->ledgerEntryCount, (int) $wallet->ledger_entry_count));
        }

        // ---- R2: the closed identity of §6.5.2 ----------------------------------------------------
        if (! $snapshot->identityHolds()) {
            $findings[] = ReconciliationFinding::failed('R2',
                sprintf('lifetime %s but the buckets sum to %s',
                    $snapshot->lifetimeEarned, $snapshot->bucketSum()),
                ['lifetime' => $snapshot->lifetimeEarned, 'buckets' => $snapshot->bucketSum()]);
        }

        // ---- R3: two independent tables must agree on what was paid -------------------------------
        $payoutTotal = Money::of((string) ($this->db->table('collaborator_payouts')
            ->where('collaborator_id', $id)
            ->where('status', PayoutStatus::Paid->value)
            ->sum('amount') ?: Money::ZERO));

        $crossCheck = Money::sub($snapshot->paidBalance, $payoutTotal);

        if (! Money::isZero($crossCheck)) {
            $findings[] = ReconciliationFinding::failed('R3',
                sprintf('allocations say %s was paid, the payouts say %s',
                    $snapshot->paidBalance, $payoutTotal),
                ['allocations' => $snapshot->paidBalance, 'payouts' => $payoutTotal]);
        }

        // ---- R4: allocations, entries and payouts tell the same story -----------------------------
        [$allocationMismatches, $orphans, $r4] = $this->checkAllocations($id);
        $findings = array_merge($findings, $r4);

        // ---- R5: the pair-flip invariant (INV-15) -------------------------------------------------
        $crossfoot = $this->bucketCrossfoot($id);

        if (! Money::isZero($crossfoot)) {
            $findings[] = ReconciliationFinding::failed('R5',
                sprintf('the reversed bucket does not net to zero — it is out by %s', $crossfoot),
                ['difference' => $crossfoot]);
        }

        // ---- R6: each entry's undone total equals its debits ---------------------------------------
        [$groupMismatches, $r6] = $this->checkReversalGroups($id);
        $findings = array_merge($findings, $r6);

        // ---- R7: entitlements released no more than they promised ---------------------------------
        $findings = array_merge($findings, $this->checkEntitlements($id));

        // ---- R8: no silent skips ------------------------------------------------------------------
        $findings = array_merge($findings, $this->checkSilentSkips($id));

        return new ReconciliationReport(
            collaboratorId: $id,
            snapshot: $snapshot,
            stored: $stored,
            findings: array_values($findings),
            driftTotal: $drift,
            payoutCrossCheck: $crossCheck,
            allocationMismatches: $allocationMismatches,
            orphanAllocations: $orphans,
            reversalGroupMismatches: $groupMismatches,
            bucketCrossfootDiff: $crossfoot,
            lastEntryId: $this->lastEntryId($id),
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /**
     * Throw unless every check passes. The one line every money test in §11 ends with.
     *
     * The message names the failing checks, because "the wallet is wrong" sends whoever reads it
     * looking, and the report already knows exactly what disagreed.
     */
    public function assertConsistent(Collaborator $collaborator): ReconciliationReport
    {
        $report = $this->check($collaborator);

        if (! $report->passed()) {
            throw new LogicException($report->summary());
        }

        return $report;
    }

    /*
    |--------------------------------------------------------------------------
    | Recording a run
    |--------------------------------------------------------------------------
    */

    private function reconcile(
        Collaborator $collaborator,
        string $runUuid,
        string $runType,
        bool $repair,
    ): ReconciliationReport {
        $report = $this->check($collaborator);

        if ($repair && $report->isRepairable()) {
            // Only the cache, and only when nothing structural is wrong (§6.5.4).
            $this->wallets->recalculate($collaborator);

            $report = $report->asRepaired();
        }

        $record = $this->record($collaborator, $report, $runUuid, $runType);

        if ($report->status()->needsAttention()) {
            WalletDriftDetected::dispatch($collaborator, $report, $record);
        }

        return $report;
    }

    /**
     * One row per collaborator per run, pass or fail (§6.5.4).
     */
    private function record(
        Collaborator $collaborator,
        ReconciliationReport $report,
        string $runUuid,
        string $runType,
    ): CollaboratorWalletReconciliation {
        $wallet = $this->db->transaction(fn (): CollaboratorWallet => $this->wallets->lockFor($collaborator), 3);

        $row = new CollaboratorWalletReconciliation;

        $row->forceFill(array_merge($report->columns(), [
            'run_uuid' => $runUuid,
            'run_type' => mb_substr($runType, 0, 16),
            'collaborator_id' => $collaborator->getKey(),
            'collaborator_wallet_id' => $wallet->getKey(),
            'as_of' => now(),
            'checked_at' => now(),
            'repaired_at' => $report->repaired ? now() : null,
            'repaired_by' => $report->repaired ? auth()->id() : null,
            'created_at' => now(),
        ]))->save();

        // The wallet carries the verdict too, so a screen does not have to join to the last run to
        // find out whether the number beside it can be trusted.
        CollaboratorWallet::query()->whereKey($wallet->getKey())->update([
            'last_reconciled_at' => now(),
            'reconciliation_status' => $report->status()->value,
            'drift_amount' => $report->status() === ReconciliationStatus::Ok ? Money::ZERO : $report->driftTotal,
            'updated_at' => now(),
        ]);

        return $row->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | The individual checks
    |--------------------------------------------------------------------------
    */

    /**
     * R4 — three things that must agree: an entry's `allocated_amount` and the live allocations on it,
     * a live payout's `amount` and the live allocations under it, and the rule that a live allocation
     * only ever points at an entry that is `available` or `paid`.
     *
     * @return array{0: int, 1: int, 2: list<ReconciliationFinding>}
     */
    private function checkAllocations(int $collaboratorId): array
    {
        $findings = [];

        $entryRows = $this->db->table('collaborator_commission_ledger_entries as e')
            ->leftJoin('collaborator_payout_allocations as a', function ($join): void {
                $join->on('a.ledger_entry_id', '=', 'e.id')->where('a.is_released', '=', 0);
            })
            ->where('e.collaborator_id', $collaboratorId)
            ->groupBy('e.id', 'e.allocated_amount')
            ->havingRaw('e.allocated_amount <> COALESCE(SUM(a.amount), 0)')
            ->select('e.id', 'e.allocated_amount', $this->db->raw('COALESCE(SUM(a.amount), 0) as live_total'))
            ->get();

        foreach ($entryRows as $row) {
            $findings[] = ReconciliationFinding::failed('R4',
                sprintf('entry #%d claims %s allocated, its live allocations total %s',
                    (int) $row->id, (string) $row->allocated_amount, (string) $row->live_total),
                ['entry_id' => (int) $row->id]);
        }

        $payoutRows = $this->db->table('collaborator_payouts as p')
            ->leftJoin('collaborator_payout_allocations as a', function ($join): void {
                $join->on('a.payout_id', '=', 'p.id')->where('a.is_released', '=', 0);
            })
            ->where('p.collaborator_id', $collaboratorId)
            // A rejected or cancelled payout keeps the amount it *was*: zeroing it would erase what
            // somebody asked for. Only payouts still in flight or settled must match their claims.
            ->whereIn('p.status', [
                PayoutStatus::Requested->value, PayoutStatus::Pending->value,
                PayoutStatus::Approved->value, PayoutStatus::Paid->value,
            ])
            ->groupBy('p.id', 'p.amount')
            ->havingRaw('p.amount <> COALESCE(SUM(a.amount), 0)')
            ->select('p.id', 'p.amount', $this->db->raw('COALESCE(SUM(a.amount), 0) as live_total'))
            ->get();

        foreach ($payoutRows as $row) {
            $findings[] = ReconciliationFinding::failed('R4',
                sprintf('payout #%d is for %s but its live allocations total %s (INV-22)',
                    (int) $row->id, (string) $row->amount, (string) $row->live_total),
                ['payout_id' => (int) $row->id]);
        }

        $orphans = $this->db->table('collaborator_payout_allocations as a')
            ->join('collaborator_commission_ledger_entries as e', 'e.id', '=', 'a.ledger_entry_id')
            ->where('a.collaborator_id', $collaboratorId)
            ->where('a.is_released', 0)
            ->whereNotIn('e.status', [CommissionStatus::Available->value, CommissionStatus::Paid->value])
            ->pluck('a.id');

        if ($orphans->isNotEmpty()) {
            $findings[] = ReconciliationFinding::failed('R4',
                sprintf('%d live allocation(s) point at an entry that cannot be paid', $orphans->count()),
                ['allocation_ids' => $orphans->all()]);
        }

        return [$entryRows->count() + $payoutRows->count(), $orphans->count(), $findings];
    }

    /**
     * R5 — bucket sums with and without the `reversed` rows must be identical.
     *
     * INV-15 says a fully undone entry and every one of its debits move to `reversed` together, so that
     * bucket always nets to zero. If excluding it changes the total, a credit and its debit are sitting
     * in different buckets, and one of them is inflating a balance somebody can spend.
     */
    private function bucketCrossfoot(int $collaboratorId): string
    {
        $row = $this->db->table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $collaboratorId)
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(signed_amount), 0) as with_reversed')
            ->selectRaw('COALESCE(SUM(CASE WHEN status <> ? THEN signed_amount ELSE 0 END), 0) as without_reversed',
                [CommissionStatus::Reversed->value])
            ->first();

        return Money::sub(
            Money::of((string) ($row->with_reversed ?? Money::ZERO)),
            Money::of((string) ($row->without_reversed ?? Money::ZERO)),
        );
    }

    /**
     * R6 — for every earning entry, `reversed_amount + clawed_back_amount` equals the debits that point
     * at it, and never exceeds the entry.
     *
     * @return array{0: int, 1: list<ReconciliationFinding>}
     */
    private function checkReversalGroups(int $collaboratorId): array
    {
        $findings = [];

        $rows = $this->db->table('collaborator_commission_ledger_entries as e')
            ->leftJoin('collaborator_commission_ledger_entries as d', function ($join): void {
                $join->on('d.reverses_entry_id', '=', 'e.id')
                    ->whereIn('d.purpose', [LedgerEntryPurpose::Reversal->value, LedgerEntryPurpose::Clawback->value])
                    ->where('d.status', '<>', CommissionStatus::Cancelled->value);
            })
            ->where('e.collaborator_id', $collaboratorId)
            ->whereIn('e.purpose', [
                LedgerEntryPurpose::StudentCommission->value,
                LedgerEntryPurpose::ProjectCommission->value,
                LedgerEntryPurpose::ManualAdjustment->value,
            ])
            ->groupBy('e.id', 'e.amount', 'e.reversed_amount', 'e.clawed_back_amount')
            ->havingRaw('e.reversed_amount + e.clawed_back_amount <> COALESCE(SUM(d.amount), 0)'
                .' OR e.reversed_amount + e.clawed_back_amount > e.amount')
            ->select('e.id', 'e.amount', 'e.reversed_amount', 'e.clawed_back_amount',
                $this->db->raw('COALESCE(SUM(d.amount), 0) as debit_total'))
            ->get();

        foreach ($rows as $row) {
            $undone = Money::add((string) $row->reversed_amount, (string) $row->clawed_back_amount);

            $findings[] = ReconciliationFinding::failed('R6',
                sprintf('entry #%d records %s undone against %s of debits, on an entry of %s',
                    (int) $row->id, $undone, (string) $row->debit_total, (string) $row->amount),
                ['entry_id' => (int) $row->id]);
        }

        return [$rows->count(), $findings];
    }

    /**
     * R7 — an entitlement released exactly what its entries say, and never more than it promised.
     *
     * The ceiling is the more interesting half: `released_amount > entitlement_amount` means a promise
     * of PKR 2,000 paid out PKR 2,400, which branch C's proration exists to make impossible. Seeing it
     * means the proration was bypassed somewhere.
     *
     * @return list<ReconciliationFinding>
     */
    private function checkEntitlements(int $collaboratorId): array
    {
        $findings = [];

        $rows = $this->db->table('collaborator_commission_entitlements as t')
            ->leftJoin('collaborator_commission_ledger_entries as e', function ($join): void {
                $join->on('e.entitlement_id', '=', 't.id')
                    ->where('e.status', '<>', CommissionStatus::Cancelled->value);
            })
            ->where('t.collaborator_id', $collaboratorId)
            ->groupBy('t.id', 't.released_amount', 't.entitlement_amount')
            ->havingRaw('t.released_amount <> COALESCE(SUM(e.signed_amount), 0)')
            ->select('t.id', 't.released_amount', 't.entitlement_amount',
                $this->db->raw('COALESCE(SUM(e.signed_amount), 0) as net_released'))
            ->get();

        foreach ($rows as $row) {
            $findings[] = ReconciliationFinding::failed('R7',
                sprintf('entitlement #%d records %s released, its entries net to %s',
                    (int) $row->id, (string) $row->released_amount, (string) $row->net_released),
                ['entitlement_id' => (int) $row->id]);
        }

        $overReleased = $this->db->table('collaborator_commission_entitlements')
            ->where('collaborator_id', $collaboratorId)
            // A zero promise is an uncapped one (the `paid` base has no document-level ceiling), so it
            // is not a breach of anything.
            ->where('entitlement_amount', '>', 0)
            ->whereRaw('released_amount > entitlement_amount')
            ->pluck('id');

        if ($overReleased->isNotEmpty()) {
            $findings[] = ReconciliationFinding::failed('R7',
                sprintf('%d entitlement(s) released more than they promised', $overReleased->count()),
                ['entitlement_ids' => $overReleased->all()]);
        }

        return $findings;
    }

    /**
     * R8 — every payment marked `processed` left a trace: a ledger row, or a recorded reason why not.
     *
     * Drift rather than failure, because nothing about the money is wrong — what is missing is the
     * explanation, and a commission that vanished without one is exactly the conversation this system
     * exists to be able to have.
     *
     * @return list<ReconciliationFinding>
     */
    private function checkSilentSkips(int $collaboratorId): array
    {
        $findings = [];

        foreach ([
            'student_fee_payments' => 'student_fee_payment_id',
            'project_payments' => 'project_payment_id',
        ] as $table => $column) {
            $silent = $this->db->table($table.' as p')
                ->where('p.collaborator_id', $collaboratorId)
                ->where('p.commission_state', CommissionProcessingState::Processed->value)
                ->whereNull('p.commission_skip_reason')
                ->whereNotExists(fn ($q) => $q
                    ->from('collaborator_commission_ledger_entries as e')
                    ->whereColumn('e.'.$column, 'p.id'))
                ->pluck('p.id');

            if ($silent->isNotEmpty()) {
                $findings[] = ReconciliationFinding::drift('R8',
                    sprintf('%d %s row(s) processed with neither a commission nor a reason',
                        $silent->count(), str_replace('_', ' ', $table)),
                    ['table' => $table, 'ids' => $silent->all()]);
            }
        }

        return $findings;
    }

    private function lastEntryId(int $collaboratorId): ?int
    {
        $id = $this->db->table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $collaboratorId)
            ->max('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * When the last run was, for a screen that wants to say so. Null means never.
     */
    public function lastRunAt(?Collaborator $collaborator = null): ?Carbon
    {
        $at = CollaboratorWalletReconciliation::query()
            ->when($collaborator !== null, fn ($q) => $q->where('collaborator_id', $collaborator->getKey()))
            ->max('checked_at');

        return $at === null ? null : Carbon::parse((string) $at);
    }
}
