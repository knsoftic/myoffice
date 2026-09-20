<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionProcessingState;
use App\Enums\CommissionSkipReason;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;

/**
 * What the engine decided about one payment (spine §6.2, phase-10-12 §6.2).
 *
 * **Three outcomes, and the difference between two of them is the whole design.**
 *
 *   * `alreadyDone()` — G0. The payment was already settled: a sweeper retry, a queue redelivery, a
 *     replayed `failed_jobs` row weeks later. It writes **absolutely nothing** — not a row, not a
 *     stamp, not an activity entry. Logging it would mean every ten-minute sweep flooded the audit
 *     trail with the fact that nothing happened, and the one real event in there would be unfindable.
 *   * `skipped()` — a guard said no, with a reason and a sentence naming the specific partner or rule.
 *     **No ledger row at all, and never a zero row** (INV-2): a zero commission is a number somebody
 *     eventually sums, while a skip with a reason is something somebody can act on.
 *   * `posted()` — a row exists. `created` distinguishes a fresh one from the one a racing worker
 *     already wrote, which is a replay and not an error.
 */
final readonly class CommissionOutcome
{
    private function __construct(
        public CommissionProcessingState $state,
        public ?CommissionSkipReason $reason = null,
        public ?string $detail = null,
        public ?CollaboratorCommissionLedgerEntry $entry = null,
        public bool $created = false,
        public bool $silent = false,
        public ?string $step = null,
    ) {}

    /**
     * G0: settled already. Writes nothing, says nothing.
     */
    public static function alreadyDone(CommissionProcessingState $state): self
    {
        return new self(state: $state, silent: true);
    }

    public static function skipped(CommissionSkipReason $reason, string $detail, string $step): self
    {
        return new self(
            state: CommissionProcessingState::Skipped,
            reason: $reason,
            detail: mb_substr($detail, 0, 191),
            step: $step,
        );
    }

    /**
     * A payment that is not the engine's business at all — a voided receipt's reversal, a subject the
     * engine will never be asked about again. Distinct from a skip because `commissions:sweep` retries
     * neither, but only one of them is worth showing on the skip report.
     */
    public static function notApplicable(string $detail, string $step): self
    {
        return new self(
            state: CommissionProcessingState::NotApplicable,
            detail: mb_substr($detail, 0, 191),
            step: $step,
        );
    }

    public static function posted(CollaboratorCommissionLedgerEntry $entry, bool $created): self
    {
        return new self(
            state: CommissionProcessingState::Processed,
            entry: $entry,
            created: $created,
        );
    }

    public function isProcessed(): bool
    {
        return $this->state === CommissionProcessingState::Processed;
    }

    public function isSkipped(): bool
    {
        return $this->state === CommissionProcessingState::Skipped;
    }

    /**
     * The sentence a screen shows, falling back to the reason's own label when no specific one was
     * recorded.
     */
    public function sentence(): ?string
    {
        return $this->detail ?? $this->reason?->label();
    }
}
