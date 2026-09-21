<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\ReconciliationStatus;
use App\Support\Money;

/**
 * What one collaborator's reconciliation found (spine §6.5.3).
 *
 * Carries the derivation, the cache it was compared against, and every finding. Deliberately **not** a
 * boolean: "the wallet is wrong" is not actionable, and the eight checks fail in eight different ways
 * that need eight different responses. A drift in R1 is repaired by recomputing; a failure in R4 means
 * an allocation and its entry disagree, which recomputing would paper over.
 *
 * The status follows from the findings and is never passed in — a caller that could choose the status
 * could call a failed run ok.
 */
final readonly class ReconciliationReport
{
    /**
     * @param  array<string, mixed>  $stored  the cached wallet columns, as they were when read
     * @param  list<ReconciliationFinding>  $findings
     */
    public function __construct(
        public int $collaboratorId,
        public WalletSnapshot $snapshot,
        public array $stored,
        public array $findings,
        public string $driftTotal,
        public string $payoutCrossCheck,
        public int $allocationMismatches,
        public int $orphanAllocations,
        public int $reversalGroupMismatches,
        public string $bucketCrossfootDiff,
        public ?int $lastEntryId,
        public int $durationMs = 0,
        public bool $repaired = false,
    ) {}

    /**
     * §6.5.4: any structural finding is `failed`, otherwise any finding at all is `drift`, otherwise
     * `ok` — and `repaired` only when the cache was actually rewritten.
     */
    public function status(): ReconciliationStatus
    {
        foreach ($this->findings as $finding) {
            if ($finding->isStructural()) {
                return ReconciliationStatus::Failed;
            }
        }

        if ($this->findings === []) {
            return ReconciliationStatus::Ok;
        }

        return $this->repaired ? ReconciliationStatus::Repaired : ReconciliationStatus::Drift;
    }

    public function passed(): bool
    {
        return $this->findings === [];
    }

    /**
     * May this be repaired by recomputing the cache? Only when nothing structural is wrong — a repair
     * on a broken ledger would hide the break behind a freshly computed number.
     */
    public function isRepairable(): bool
    {
        if ($this->findings === []) {
            return false;
        }

        foreach ($this->findings as $finding) {
            if ($finding->isStructural()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The same report, recorded as having had its cache rewritten.
     *
     * A copy rather than a mutation: the report is what a check found, and a check that could be
     * edited after the fact would not be worth recording. `repaired` is the one thing about it that is
     * decided later, by whoever chose to act on it.
     */
    public function asRepaired(): self
    {
        return new self(
            collaboratorId: $this->collaboratorId,
            snapshot: $this->snapshot,
            stored: $this->stored,
            findings: $this->findings,
            driftTotal: $this->driftTotal,
            payoutCrossCheck: $this->payoutCrossCheck,
            allocationMismatches: $this->allocationMismatches,
            orphanAllocations: $this->orphanAllocations,
            reversalGroupMismatches: $this->reversalGroupMismatches,
            bucketCrossfootDiff: $this->bucketCrossfootDiff,
            lastEntryId: $this->lastEntryId,
            durationMs: $this->durationMs,
            repaired: true,
        );
    }

    /**
     * @return list<ReconciliationFinding>
     */
    public function structural(): array
    {
        return array_values(array_filter($this->findings, static fn (ReconciliationFinding $f): bool => $f->isStructural()));
    }

    /**
     * One line naming what disagreed — the body of the alert, the row on the queue screen, and the
     * message a failing test prints.
     */
    public function summary(): string
    {
        if ($this->findings === []) {
            return sprintf('Collaborator #%d: the wallet matches the ledger.', $this->collaboratorId);
        }

        return sprintf('Collaborator #%d — %s.', $this->collaboratorId, implode('; ', array_map(
            static fn (ReconciliationFinding $f): string => $f->line(),
            $this->findings,
        )));
    }

    /**
     * The row `collaborator_wallet_reconciliations` stores, minus the run's own columns.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $columns = [
            'expected_pending' => $this->snapshot->pendingBalance,
            'expected_available' => $this->snapshot->availableBalance,
            'expected_reserved' => $this->snapshot->reservedBalance,
            'expected_paid' => $this->snapshot->paidBalance,
            'expected_lifetime' => $this->snapshot->lifetimeEarned,
            'expected_student' => $this->snapshot->totalStudentCommission,
            'expected_project' => $this->snapshot->totalProjectCommission,
            'expected_adjustments' => $this->snapshot->totalAdjustments,
            'expected_reversed' => $this->snapshot->totalReversed,
            'expected_entry_count' => $this->snapshot->ledgerEntryCount,
            'stored_entry_count' => (int) ($this->stored['ledger_entry_count'] ?? 0),
            'drift_total' => $this->driftTotal,
            'identity_holds' => $this->snapshot->identityHolds(),
            'payout_cross_check' => $this->payoutCrossCheck,
            'allocation_mismatch_count' => $this->allocationMismatches,
            'orphan_allocation_count' => $this->orphanAllocations,
            'reversal_group_mismatch_count' => $this->reversalGroupMismatches,
            'bucket_crossfoot_diff' => $this->bucketCrossfootDiff,
            'last_entry_id' => $this->lastEntryId,
            'status' => $this->status()->value,
            'repaired' => $this->repaired,
            'details' => $this->details(),
            'duration_ms' => $this->durationMs,
        ];

        foreach (self::STORED_COLUMNS as $bucket => $walletColumn) {
            $columns['stored_'.$bucket] = Money::of((string) ($this->stored[$walletColumn] ?? Money::ZERO));
        }

        return $columns;
    }

    /**
     * What the reconciliation row calls each bucket, and what the wallet calls it.
     *
     * @var array<string, string>
     */
    private const STORED_COLUMNS = [
        'pending' => 'pending_balance',
        'available' => 'available_balance',
        'reserved' => 'reserved_balance',
        'paid' => 'paid_balance',
        'lifetime' => 'lifetime_earned',
        'student' => 'total_student_commission',
        'project' => 'total_project_commission',
        'adjustments' => 'total_adjustments',
        'reversed' => 'total_reversed',
    ];

    /**
     * The `details` JSON: every finding with its ids, so the drift screen can link straight to the
     * offending rows rather than asking somebody to go looking (§6.5.4).
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'findings' => array_map(static fn (ReconciliationFinding $f): array => [
                'check' => $f->check,
                'severity' => $f->severity,
                'message' => $f->message,
                'details' => $f->details,
            ], $this->findings),
        ];
    }
}
