<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * What one ledger row does to the wallet cache — as the difference between two states
 * (spine §6.5.1, §6.1.10).
 *
 * **Every figure here is derived from the canonical SQL's own predicates**, not written out a second
 * time from memory. `derive()` sums over all rows; this sums over one row, twice — once for what it
 * used to contribute and once for what it contributes now — and takes the difference. Two definitions
 * of "which bucket does an `approved` entry sit in" is exactly how a cache drifts from its ledger, so
 * there is one, in {@see contribution()}, and both the insert and every status change go through it.
 *
 * A new row has `previousStatus === null`: it contributed nothing before, so the delta is simply its
 * contribution. A status change carries both, and the row count does not move.
 */
final readonly class LedgerDelta
{
    public function __construct(
        public string $signedAmount,
        public string $amount,
        public LedgerEntryPurpose $purpose,
        public CommissionStatus $status,
        public ?CommissionStatus $previousStatus,
        public bool $isNew,
        public ?int $entryId = null,
        public ?CarbonInterface $entryAt = null,
    ) {}

    /**
     * A freshly posted row.
     */
    public static function forNewEntry(CollaboratorCommissionLedgerEntry $entry): self
    {
        return new self(
            signedAmount: (string) $entry->signed_amount,
            amount: (string) $entry->amount,
            purpose: $entry->purpose,
            status: $entry->status,
            previousStatus: null,
            isNew: true,
            entryId: (int) $entry->getKey(),
            entryAt: $entry->posted_at ?? $entry->created_at,
        );
    }

    /**
     * An existing row moving between statuses — approval, release, cancellation, reversal.
     */
    public static function forStatusChange(CollaboratorCommissionLedgerEntry $entry, CommissionStatus $from): self
    {
        return new self(
            signedAmount: (string) $entry->signed_amount,
            amount: (string) $entry->amount,
            purpose: $entry->purpose,
            status: $entry->status,
            previousStatus: $from,
            isNew: false,
            entryId: (int) $entry->getKey(),
            entryAt: $entry->posted_at ?? $entry->updated_at,
        );
    }

    /**
     * The cached columns this delta moves, as `column => signed change`. Zero changes are dropped, so a
     * `pending -> approved` move — both buckets being the same one — writes nothing at all.
     *
     * `available_balance` carries the ledger's whole payable side. The payout legs of the §6.5.1
     * identity (`available = payable - reserved - paid`) are moved by `PayoutService`, which owns the
     * allocations those two figures are derived from.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        $now = $this->contribution($this->status);
        $before = $this->previousStatus === null
            ? array_fill_keys(array_keys($now), Money::ZERO)
            : $this->contribution($this->previousStatus);

        $delta = [];

        foreach ($now as $column => $value) {
            $change = Money::sub($value, $before[$column]);

            if (! Money::isZero($change)) {
                $delta[$column] = $change;
            }
        }

        return $delta;
    }

    /**
     * What this row contributes to each cached column while it is in `$status` — the single-row form of
     * the CASE expressions in spine §6.5.1 query A.
     *
     * @return array<string, string>
     */
    private function contribution(CommissionStatus $status): array
    {
        $signed = Money::of($this->signedAmount);
        $magnitude = Money::of($this->amount);

        // `pending` and `approved` are both "earned but not yet spendable": an entry inside a hold
        // window has been approved and still cannot be paid out, so it belongs where a partner reads
        // it as pending.
        $inPending = $status === CommissionStatus::Pending || $status === CommissionStatus::Approved;
        $inPayable = $status === CommissionStatus::Available || $status === CommissionStatus::Paid;

        // A cancelled row leaves every total, including the memo ones: it is a decision that the entry
        // never counted. A **reversed** row stays in `lifetime_earned` on purpose — it was earned, and
        // the debit that undid it is a separate row carrying its own sign.
        $counts = $status !== CommissionStatus::Cancelled;

        return [
            'pending_balance' => $inPending ? $signed : Money::ZERO,
            'available_balance' => $inPayable ? $signed : Money::ZERO,
            'lifetime_earned' => $counts ? $signed : Money::ZERO,
            'total_student_commission' => $counts && $this->purpose === LedgerEntryPurpose::StudentCommission ? $signed : Money::ZERO,
            'total_project_commission' => $counts && $this->purpose === LedgerEntryPurpose::ProjectCommission ? $signed : Money::ZERO,
            'total_adjustments' => $counts && $this->isAdjustment() ? $signed : Money::ZERO,
            // The one total measured as a magnitude rather than a signed amount: "how much has been
            // taken back" reads as a positive figure on the statement (§50, §56), beside the negative
            // rows that produced it.
            'total_reversed' => $counts && $this->isUndo() ? $magnitude : Money::ZERO,
        ];
    }

    private function isAdjustment(): bool
    {
        return $this->purpose === LedgerEntryPurpose::ManualAdjustment
            || $this->purpose === LedgerEntryPurpose::WriteOff;
    }

    private function isUndo(): bool
    {
        return $this->purpose === LedgerEntryPurpose::Reversal
            || $this->purpose === LedgerEntryPurpose::Clawback;
    }
}
