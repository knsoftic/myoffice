<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\EntitlementContext;
use App\DataObjects\Collaborator\SupersedeContext;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\EntitlementStatus;
use App\Enums\FixedCommissionRelease;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The promise a rule made about one document, and the running total of what has been released against
 * it (spine §6.1.5, §6.1.10, phase-10-12 §6.3).
 *
 * **One is opened for every commission, including the uncapped `paid` base** ([D-FS-7]), where
 * `entitlement_amount` is NULL. One uniform path rather than a conditional one: in the conditional
 * version, the rarely-taken branch is the one carrying the bug.
 *
 * **It is also the lock.** Two receipts landing against the same document in the same second serialise
 * on this row, which is what stops a fixed amount being promised twice or a cap being exceeded by a
 * hair. Every method here expects to be inside the caller's transaction, and the row it returns is
 * locked.
 *
 * **Every figure the calculation used is snapshotted at open time and never re-read.** A rate change, a
 * settings edit or a discount six months later cannot alter what a past slice meant, because nothing
 * downstream ever asks the rule again — it asks this row.
 */
final class CommissionEntitlementService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The current promise for this document and collaborator, opening it if this is the first receipt.
     *
     * `uq_cce_current` over `(document_key, collaborator_id, current_guard)` is what guarantees "at most
     * one current". A 1062 here means a racing worker opened it a microsecond earlier, which is not an
     * error — it is the guard working — so the row is simply re-read and locked.
     */
    public function openOrLoad(EntitlementContext $context): CollaboratorCommissionEntitlement
    {
        $this->assertInTransaction('openOrLoad');

        $existing = $this->lockCurrent($context);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $this->insert($context);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker won the race. Nothing is wrong: re-read below and carry on against
            // their row, which carries the same snapshot because it was built from the same rule.
        }

        $row = $this->lockCurrent($context);

        if ($row === null) {
            throw new LogicException(
                'An entitlement was inserted and immediately could not be found. That means the '
                .'document key this context produces does not match the one the row carries — check '
                .'`document_key`, which is generated from `document_type` and the four id columns.'
            );
        }

        return $row;
    }

    /**
     * Advance the promise by one released slice.
     *
     * A conditional UPDATE rather than a read-modify-write: the `released_amount + :x <= entitlement`
     * predicate is evaluated by the database, so even a caller who somehow skipped the lock cannot push
     * the total past the cap. `chk_cce_cap` is the backstop behind that, and the arithmetic in the
     * engine is what means neither ever has to fire.
     *
     * `collected_amount` advances by the **base** amount, not by the release: it is the running total of
     * money collected against this document, and it is the numerator of the proration. Advancing it by
     * the commission instead would make every later slice a proportion of a proportion.
     */
    public function release(CollaboratorCommissionEntitlement $entitlement, string $amount, string $baseAmount): void
    {
        $this->assertInTransaction('release');

        $amount = Money::of($amount);
        $baseAmount = Money::of($baseAmount);

        if (Money::isNegative($amount) || Money::isNegative($baseAmount)) {
            throw new LogicException('A release is never negative. Undoing one is `unrelease()`, which says so.');
        }

        $affected = $this->db->table($entitlement->getTable())
            ->where('id', $entitlement->getKey())
            ->whereRaw('(entitlement_amount IS NULL OR released_amount + ? <= entitlement_amount)', [$amount])
            ->update([
                'released_amount' => $this->db->raw('released_amount + '.$this->literal($amount)),
                'collected_amount' => $this->db->raw('collected_amount + '.$this->literal($baseAmount)),
                'ledger_entry_count' => $this->db->raw('ledger_entry_count + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new LogicException(sprintf(
                'Releasing %s against entitlement #%s would take it past the %s it promised. The engine '
                .'clamps the release before calling this, so reaching here means the clamp was skipped '
                .'or the row was superseded underneath it.',
                $amount,
                (string) $entitlement->getKey(),
                (string) ($entitlement->entitlement_amount ?? 'uncapped'),
            ));
        }

        $entitlement->refresh();
        $this->settleStatus($entitlement);
    }

    /**
     * The reversal side (spine §6.3.7).
     *
     * Both running totals floor at zero rather than going negative. A promise that has had more undone
     * than it ever released is not a negative promise, it is an empty one — and `chk_cce_nonneg` would
     * refuse the row anyway, turning an arithmetic edge into a failed refund.
     */
    public function unrelease(CollaboratorCommissionEntitlement $entitlement, string $amount, string $baseAmountShare): void
    {
        $this->assertInTransaction('unrelease');

        $amount = Money::of($amount);
        $baseAmountShare = Money::of($baseAmountShare);

        $this->db->table($entitlement->getTable())
            ->where('id', $entitlement->getKey())
            ->update([
                'reversed_amount' => $this->db->raw('reversed_amount + '.$this->literal($amount)),
                'released_amount' => $this->db->raw('GREATEST(0, released_amount - '.$this->literal($amount).')'),
                'collected_amount' => $this->db->raw('GREATEST(0, collected_amount - '.$this->literal($baseAmountShare).')'),
                'updated_at' => now(),
            ]);

        $entitlement->refresh();

        // A promise that was `fully_released` and has now had part of it undone is open again: the
        // slice came back, so there is room to release it a second time if the money returns.
        if ($entitlement->status === EntitlementStatus::FullyReleased && ! $entitlement->isFullyReleased()) {
            $this->write($entitlement, ['status' => EntitlementStatus::Open->value, 'closed_on' => null]);
        }
    }

    /**
     * A release that turned out never to have happened: the entry was **cancelled**, not reversed.
     *
     * Deliberately not {@see unrelease()}. That one is the reversal side, and it raises the promise's
     * `reversed_amount` to mirror the debit row a reversal writes. A rejection writes no debit — the
     * row simply leaves every bucket with a reason on it — so adding to `reversed_amount` here would
     * claim an undoing that has no evidence anywhere in the ledger, and §6.5.3 R7 would then find a
     * promise whose figures do not match its own entries.
     *
     * What it does share is the reason the floor exists: the slice goes back, so a promise that was
     * `fully_released` is open again and the partner can still earn it on a later payment. Without
     * this, a rejected first instalment would quietly cap a PKR 2,000 fixed commission at PKR 1,333.
     */
    public function unclaim(CollaboratorCommissionEntitlement $entitlement, string $amount, string $baseAmountShare): void
    {
        $this->assertInTransaction('unclaim');

        $amount = Money::of($amount);
        $baseAmountShare = Money::of($baseAmountShare);

        if (Money::isZero($amount) && Money::isZero($baseAmountShare)) {
            return;
        }

        $this->db->table($entitlement->getTable())
            ->where('id', $entitlement->getKey())
            ->update([
                'released_amount' => $this->db->raw('GREATEST(0, released_amount - '.$this->literal($amount).')'),
                'collected_amount' => $this->db->raw('GREATEST(0, collected_amount - '.$this->literal($baseAmountShare).')'),
                'updated_at' => now(),
            ]);

        $entitlement->refresh();

        if ($entitlement->status === EntitlementStatus::FullyReleased && ! $entitlement->isFullyReleased()) {
            $this->write($entitlement, ['status' => EntitlementStatus::Open->value, 'closed_on' => null]);
        }
    }

    /**
     * Re-floor a promise whose document changed, on a **successor row** (spine §6.5, §6.6 rows 6-8).
     *
     * `entitlement_amount = max(new_promise, released_amount)`: the new promise can never be set below
     * what has already been handed over, because that would make `chk_cce_cap` false about a past fact.
     * The shortfall is recorded in `over_released_amount` and surfaces on the discrepancy queue for a
     * human decision — **nothing is clawed back automatically**, because no money left the company.
     */
    public function supersede(
        CollaboratorCommissionEntitlement $entitlement,
        SupersedeContext $context,
        string $reason,
    ): CollaboratorCommissionEntitlement {
        $this->assertInTransaction('supersede');

        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException('Superseding a promise changes what a partner is owed. It is recorded with its reason.');
        }

        $promise = $this->promiseFor(
            calculationType: $entitlement->calculation_type,
            base: $entitlement->commission_base,
            release: $entitlement->fixed_release ?? FixedCommissionRelease::Prorated,
            rate: $entitlement->commission_rate === null ? null : (string) $entitlement->commission_rate,
            fixedAmount: $entitlement->fixed_amount === null ? null : (string) $entitlement->fixed_amount,
            documentBaseAmount: $context->documentBaseAmount,
            maxCommissionAmount: $entitlement->max_commission_amount === null ? null : (string) $entitlement->max_commission_amount,
        );

        $released = (string) $entitlement->released_amount;
        $floored = $promise === null ? null : Money::max($promise, $released);
        $over = $promise === null ? Money::ZERO : Money::max(Money::ZERO, Money::sub($released, $promise));

        // **The predecessor closes first.** `current_guard` is `superseded_at IS NULL`, and both rows
        // share a `document_key`, so writing the successor while the old row still held the slot would
        // be a 1062 on `uq_cce_current` — the guard doing exactly its job, against the one writer that
        // is allowed to move the promise. Closing first leaves the document momentarily with no current
        // promise, which is safe because this whole method runs inside the caller's transaction and no
        // other worker can see the gap.
        $this->write($entitlement, [
            'status' => EntitlementStatus::Superseded->value,
            'superseded_at' => now(),
            'supersede_reason' => mb_substr($reason, 0, 255),
            'closed_on' => $this->today()->toDateString(),
        ]);

        $successor = CollaboratorCommissionEntitlement::allowDirectWrites(
            function () use ($entitlement, $context, $floored, $over): CollaboratorCommissionEntitlement {
                $row = new CollaboratorCommissionEntitlement;

                $row->forceFill(array_merge(
                    $this->carriedColumns($entitlement),
                    [
                        'document_base_amount' => $context->documentBaseAmount,
                        'collectible_amount' => $context->collectibleAmount,
                        'entitlement_amount' => $floored,
                        'over_released_amount' => $over,
                        // The running totals move across: the successor continues the same promise, it
                        // does not start a second one beside it.
                        'released_amount' => (string) $entitlement->released_amount,
                        'reversed_amount' => (string) $entitlement->reversed_amount,
                        'collected_amount' => (string) $entitlement->collected_amount,
                        'ledger_entry_count' => (int) $entitlement->ledger_entry_count,
                        'status' => EntitlementStatus::Open->value,
                        'opened_on' => $this->today()->toDateString(),
                        'supersedes_id' => $entitlement->getKey(),
                        'rule_snapshot' => $entitlement->rule_snapshot,
                    ],
                ));

                $row->save();

                return $row->refresh();
            }
        );

        $this->settleStatus($successor);

        return $successor->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | The promise itself (spine §6.1.5)
    |--------------------------------------------------------------------------
    */

    /**
     * What this rule promises about this document, or NULL for "uncapped".
     *
     * NULL is deliberately not `'0.00'` and not a very large number: the `paid` base and `per_payment`
     * fixed release genuinely have no total to run out of, and a caller that treats "no limit" as a
     * figure will eventually compare against it.
     */
    public function promiseFor(
        CommissionCalculationType $calculationType,
        CommissionBase $base,
        FixedCommissionRelease $release,
        ?string $rate,
        ?string $fixedAmount,
        string $documentBaseAmount,
        ?string $maxCommissionAmount,
    ): ?string {
        $promise = $this->isPerPaymentMethod($calculationType, $base, $release)
            ? null
            : match ($calculationType) {
                CommissionCalculationType::Fixed => Money::of((string) $fixedAmount),
                CommissionCalculationType::Percentage => Money::percentage($documentBaseAmount, (string) $rate),
                // A manual adjustment has no document promise; it is posted directly.
                CommissionCalculationType::Manual => null,
            };

        if ($maxCommissionAmount === null) {
            return $promise;
        }

        // A cap always produces a capped entitlement, even on a base that would otherwise be uncapped:
        // "at most 5,000 on this partner" has to mean something on the `paid` base too, which is the
        // base most partners are on.
        return Money::min($promise ?? $maxCommissionAmount, $maxCommissionAmount);
    }

    /**
     * Does this rule earn **per receipt**, rather than promising a total up front?
     *
     * Two shapes do: a fixed amount released `per_payment`, and a percentage of the `paid` base. Both
     * are "each commissionable receipt earns on its own", and neither has a total that can run out —
     * which is why {@see promiseFor()} returns NULL for them.
     *
     * A `max_commission_amount` then gives them an `entitlement_amount` anyway, as a **ceiling**. That
     * distinction is the reason this predicate is published rather than inferred from
     * `entitlement_amount === null`: the engine's branch selection needs to know that a capped `paid`
     * rule still earns its percentage per receipt and is merely clamped at the top, not that it has
     * turned into a promise released in proportion to collection. Reading the cap as a promise would
     * quietly change "10 % of every receipt, up to 1,500" into "1,500 spread across the charge", which
     * is a different number on every receipt but the last.
     */
    public function isPerPaymentMethod(
        CommissionCalculationType $calculationType,
        CommissionBase $base,
        FixedCommissionRelease $release,
    ): bool {
        return match ($calculationType) {
            CommissionCalculationType::Fixed => $release === FixedCommissionRelease::PerPayment,
            CommissionCalculationType::Percentage => $base === CommissionBase::Paid,
            CommissionCalculationType::Manual => true,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function lockCurrent(EntitlementContext $context): ?CollaboratorCommissionEntitlement
    {
        return CollaboratorCommissionEntitlement::query()
            ->where('document_type', $context->document->documentType->value)
            ->where($context->document->documentColumn(), $context->document->documentId)
            ->where('collaborator_id', $context->collaborator->getKey())
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->first();
    }

    private function insert(EntitlementContext $context): void
    {
        $rule = $context->rule;
        $document = $context->document;
        $settings = $context->settings;

        $promise = $this->promiseFor(
            calculationType: $rule->calculationType,
            base: $document->base,
            release: $rule->release,
            rate: $rule->rate,
            fixedAmount: $rule->fixedAmount,
            documentBaseAmount: $document->documentBaseAmount,
            maxCommissionAmount: $rule->maxCommissionAmount,
        );

        CollaboratorCommissionEntitlement::allowDirectWrites(function () use (
            $context, $rule, $document, $settings, $promise
        ): void {
            $row = new CollaboratorCommissionEntitlement;

            $row->forceFill([
                'collaborator_id' => $context->collaborator->getKey(),
                'collaborator_referral_id' => $context->referral->getKey(),
                'commission_setting_id' => $rule->ruleId(),
                'rule_source' => $rule->source->value,
                'document_type' => $document->documentType->value,
                $document->documentColumn() => $document->documentId,
                'commission_for' => $context->scope->value,
                'calculation_type' => $rule->calculationType->value,
                'commission_rate' => $rule->rate,
                'fixed_amount' => $rule->fixedAmount,
                'fixed_release' => $rule->release->value,
                'commission_base' => $document->base->value,
                'approval_mode' => $settings->approvalMode->value,
                'hold_days' => $settings->holdDays,
                'document_base_amount' => $document->documentBaseAmount,
                'collectible_amount' => $document->collectibleAmount,
                'entitlement_amount' => $promise,
                'max_commission_amount' => $rule->maxCommissionAmount,
                'status' => EntitlementStatus::Open->value,
                'opened_on' => $this->today()->toDateString(),
                // Cast `array`; handing it a pre-encoded string would nest one JSON document
                // inside another and every reader would get back a string.
                'rule_snapshot' => [
                    'rule' => $rule->snapshot(),
                    'document' => $document->snapshot(),
                    'settings' => $settings->snapshot(),
                    'base_fallback' => $rule->baseFallback,
                    'opened_at' => now()->toIso8601String(),
                ],
            ]);

            $row->save();
        });
    }

    /**
     * The columns a successor inherits unchanged. The rule, the rate, the base and the approval mode
     * are what the promise *was made under*, and re-reading any of them here is the one way a supersede
     * could silently re-price history.
     *
     * @return array<string, mixed>
     */
    private function carriedColumns(CollaboratorCommissionEntitlement $entitlement): array
    {
        $columns = [
            'collaborator_id', 'collaborator_referral_id', 'commission_setting_id', 'rule_source',
            'document_type', 'student_admission_id', 'student_fee_id', 'project_id', 'project_milestone_id',
            'commission_for', 'calculation_type', 'commission_rate', 'fixed_amount', 'fixed_release',
            'commission_base', 'approval_mode', 'hold_days', 'max_commission_amount',
        ];

        $carried = [];

        foreach ($columns as $column) {
            $carried[$column] = $entitlement->getRawOriginal($column);
        }

        return $carried;
    }

    /**
     * Flip `fully_released` the moment the promise is exhausted, and back when it is not.
     */
    private function settleStatus(CollaboratorCommissionEntitlement $entitlement): void
    {
        if ($entitlement->status !== EntitlementStatus::Open && $entitlement->status !== EntitlementStatus::FullyReleased) {
            return;
        }

        $done = $entitlement->isFullyReleased();
        $target = $done ? EntitlementStatus::FullyReleased : EntitlementStatus::Open;

        if ($entitlement->status === $target) {
            return;
        }

        $this->write($entitlement, [
            'status' => $target->value,
            'closed_on' => $done ? $this->today()->toDateString() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(CollaboratorCommissionEntitlement $entitlement, array $attributes): void
    {
        CollaboratorCommissionEntitlement::allowDirectWrites(function () use ($entitlement, $attributes): void {
            $entitlement->forceFill($attributes)->save();
        });
    }

    /**
     * A bcmath string that has already been through `Money`, quoted for a raw expression. Not a bound
     * parameter because these appear inside `SET x = x + ?` fragments, where binding would mean
     * threading a parameter list through every caller for no safety gain — the value is a decimal
     * string this class produced, and the assertion says so.
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
                'CommissionEntitlementService::%s() must run inside the caller\'s transaction. The '
                .'entitlement row is the lock that serialises two receipts against one document, and '
                .'outside a transaction that lock is released the instant it is taken.',
                $method,
            ));
        }
    }

    private function today(): Carbon
    {
        return Carbon::now(Format::timezone())->startOfDay();
    }
}
