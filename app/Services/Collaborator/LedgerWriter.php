<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\LedgerDelta;
use App\DataObjects\Collaborator\LedgerEntryDraft;
use App\DataObjects\Collaborator\LedgerPostResult;
use App\Enums\CollaboratorActivityEvent;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Events\Collaborator\CommissionCreated;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * **The only insert path into the commission ledger** (INV-21, spine §2.19, phase-10-12 §6.3).
 *
 * Three things happen here that a direct insert would not do, and each of them is invisible by its
 * absence — a row written elsewhere looks completely normal in the table:
 *
 *   1. **The dedupe key is composed here, never accepted from a caller** ([D-IMP-5]). It is built from
 *      the source *table* token rather than from `CommissionSourceType`, because a receipt allocated to
 *      an installment line is recorded as `student_installment_payment` while still being the same
 *      receipt. Keying on the source type would give one receipt two possible keys, and `uq_cle_source`
 *      would not catch the second row because `source_type` is part of that index too.
 *   2. **The insert runs inside a SAVEPOINT.** A 1062 on `uq_cle_dedupe` is the *expected* outcome of a
 *      replay, and in MariaDB an error inside a transaction leaves the transaction usable but the
 *      statement rolled back — except that the caller's outer transaction would be poisoned if the
 *      exception escaped. Rolling back to a savepoint turns "this already exists" into an ordinary
 *      answer instead of a failed job.
 *   3. **The wallet delta and the audit row are written in the same transaction.** A committed entry
 *      whose cache write lands separately is a window where `wallet != SUM(ledger)`.
 *
 * `CommissionCreated` fires for **earnings only**, after commit. A reversal debit is somebody's money
 * going away, and it has its own events with their own wording — telling a partner they "earned"
 * -400.00 is not a notification, it is a complaint waiting to happen.
 */
final class LedgerWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorWalletService $wallets,
        private readonly CollaboratorActivityService $activity,
    ) {}

    /**
     * Write one row, or return the one that is already there.
     */
    public function post(LedgerEntryDraft $draft): LedgerPostResult
    {
        $this->assertInTransaction();

        $key = $this->dedupeKey($draft);
        $existing = $this->findByKey($key);

        if ($existing !== null) {
            return new LedgerPostResult($existing, false);
        }

        $wallet = $this->wallets->lockFor($draft->collaborator);
        $connection = $this->db->connection();

        $connection->statement('SAVEPOINT ledger_post');

        try {
            $entry = $this->insert($draft, $key, (int) $wallet->getKey());
        } catch (UniqueConstraintViolationException) {
            // A racing worker posted it between the read and the insert. The savepoint keeps the
            // caller's transaction alive, which matters: the same transaction still has to stamp the
            // payment and commit, and a poisoned transaction would turn an idempotent replay into a
            // receipt that never records its own outcome.
            $connection->statement('ROLLBACK TO SAVEPOINT ledger_post');

            $winner = $this->findByKey($key);

            if ($winner === null) {
                throw new LogicException(sprintf(
                    'The ledger refused [%s] as a duplicate and then could not find it. That means the '
                    .'collision was on a different unique index — uq_cle_source or uq_cle_reversal_pair '
                    .'— which is a real disagreement between two guards, not a replay.',
                    $key,
                ));
            }

            return new LedgerPostResult($winner, false);
        } catch (Throwable $e) {
            $connection->statement('ROLLBACK TO SAVEPOINT ledger_post');

            throw $e;
        }

        $connection->statement('RELEASE SAVEPOINT ledger_post');

        $this->wallets->applyDelta($wallet, LedgerDelta::forNewEntry($entry));
        $this->audit($draft, $entry);

        if ($draft->purpose->isEarning()) {
            CommissionCreated::dispatch($entry);
        }

        return new LedgerPostResult($entry, true);
    }

    /**
     * The flag the model's `creating` hook checks, published so a test can prove the guard is closed
     * outside this class.
     */
    public function isWriting(): bool
    {
        return CollaboratorCommissionLedgerEntry::isWriting();
    }

    /*
    |--------------------------------------------------------------------------
    | The key
    |--------------------------------------------------------------------------
    */

    /**
     * [D-IMP-5]. Keyed by the **table** the source row lives in, the purpose, and who it credits.
     *
     * A manual adjustment has no source row to key on, so it takes a ULID: two identical write-offs on
     * the same day for the same partner are two separate decisions somebody made, and collapsing them
     * into one would silently lose the second.
     */
    public function dedupeKey(LedgerEntryDraft $draft): string
    {
        $token = $this->sourceToken($draft->sourceType);

        if ($token === 'manual') {
            return 'manual:'.strtolower((string) Str::ulid());
        }

        if ($draft->purpose === LedgerEntryPurpose::Reversal || $draft->purpose === LedgerEntryPurpose::Clawback) {
            $key = sprintf('%s:%d:reversal:entry:%d', $token, $draft->sourceId, (int) $draft->reversesEntryId);

            // The clawback of an entry that was already paid out is a second, legitimate debit against
            // the same original from the same reversal — spine §2.19 says so, and `uq_cle_source`
            // separates the pair by purpose. Without the suffix the two would share a dedupe key and
            // the second would be swallowed as a replay.
            return $draft->purpose === LedgerEntryPurpose::Clawback ? $key.':clawback' : $key;
        }

        return sprintf(
            '%s:%d:%s:collab:%d',
            $token,
            $draft->sourceId,
            $draft->purpose->value,
            (int) $draft->collaborator->getKey(),
        );
    }

    /**
     * The table a source type lives in. Two source types share one table on purpose: an installment
     * receipt **is** a `student_fee_payments` row, and the distinction exists for reporting (§51).
     */
    private function sourceToken(CommissionSourceType $type): string
    {
        return match ($type) {
            CommissionSourceType::StudentFeePayment,
            CommissionSourceType::StudentInstallmentPayment => 'student_fee_payment',
            CommissionSourceType::ProjectPayment => 'project_payment',
            CommissionSourceType::PaymentReversal => 'payment_reversal',
            CommissionSourceType::ManualAdjustment => 'manual',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function findByKey(string $key): ?CollaboratorCommissionLedgerEntry
    {
        return CollaboratorCommissionLedgerEntry::query()->where('dedupe_key', $key)->first();
    }

    private function insert(LedgerEntryDraft $draft, string $key, int $walletId): CollaboratorCommissionLedgerEntry
    {
        return CollaboratorCommissionLedgerEntry::allowDirectWrites(
            function () use ($draft, $key, $walletId): CollaboratorCommissionLedgerEntry {
                $entry = new CollaboratorCommissionLedgerEntry;

                $entry->forceFill(array_merge($draft->subjects(), [
                    'dedupe_key' => $key,
                    'collaborator_id' => $draft->collaborator->getKey(),
                    'collaborator_wallet_id' => $walletId,
                    'entitlement_id' => $draft->entitlement?->getKey(),
                    'collaborator_referral_id' => $draft->referral?->getKey(),
                    'commission_setting_id' => $draft->commissionSettingId,
                    'rule_source' => $draft->ruleSource?->value,
                    'entry_type' => $draft->entryType->value,
                    'purpose' => $draft->purpose->value,
                    'source_type' => $draft->sourceType->value,
                    'source_id' => $draft->sourceId,
                    'student_fee_payment_id' => $draft->studentFeePaymentId,
                    'project_payment_id' => $draft->projectPaymentId,
                    'payment_reversal_id' => $draft->paymentReversalId,
                    'reverses_entry_id' => $draft->reversesEntryId,
                    'gross_amount' => Money::of($draft->grossAmount),
                    'commission_base' => $draft->base->value,
                    'base_amount' => Money::of($draft->baseAmount),
                    'calculation_type' => $draft->calculationType->value,
                    'commission_rate' => $draft->commissionRate,
                    'fixed_amount' => $draft->fixedAmount,
                    'entitlement_total' => $draft->entitlementTotal,
                    'released_before' => $draft->releasedBefore,
                    'amount' => Money::of($draft->amount),
                    // The array, not a JSON string: the column is cast `array`, so encoding it here
                    // would store a JSON document whose only content is another JSON document, and
                    // every reader would get back a string where it expected the trace.
                    'rule_snapshot' => $draft->ruleSnapshot,
                    'status' => $draft->status->value,
                    'approval_mode' => $draft->approvalMode->value,
                    'hold_until' => $draft->holdUntil?->toDateString(),
                    'available_at' => $draft->status === CommissionStatus::Available ? now() : null,
                    'transaction_date' => $draft->transactionDate->toDateString(),
                    'posted_at' => now(),
                    'currency' => Money::currencyCode(),
                    'notes' => $draft->notes === null ? null : mb_substr($draft->notes, 0, 255),
                ]));

                $entry->save();

                return $entry->refresh();
            }
        );
    }

    /**
     * The partner-facing audit row. §60's eleven are what a collaborator sees on their own feed, so an
     * earning and a reversal are logged under the events that exist for them; an adjustment and a
     * write-off are staff acts on a partner's balance and are logged under the reversal event, which is
     * the one that means "your balance moved for a reason somebody recorded".
     */
    private function audit(LedgerEntryDraft $draft, CollaboratorCommissionLedgerEntry $entry): void
    {
        $this->activity->record(
            $draft->purpose->isEarning()
                ? CollaboratorActivityEvent::CommissionCreated
                : CollaboratorActivityEvent::CommissionReversed,
            $draft->collaborator,
            $entry,
            [
                'amount' => (string) $entry->amount,
                'signed_amount' => (string) $entry->signed_amount,
                'purpose' => $entry->purpose->value,
                'status' => $entry->status->value,
                'source_type' => $entry->source_type->value,
                'source_id' => (int) $entry->source_id,
            ],
            $draft->notes,
        );
    }

    private function assertInTransaction(): void
    {
        if ($this->db->connection()->transactionLevel() < 1) {
            throw new LogicException(
                'LedgerWriter::post() must run inside a transaction. The row, the wallet delta and the '
                .'payment stamp are one fact about one receipt, and a crash between any two of them '
                .'would leave a partner with money the ledger cannot explain or a receipt that will be '
                .'processed a second time.'
            );
        }
    }
}
