<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionSkipReason;
use App\Enums\FixedCommissionRelease;
use App\Support\Collaborator\CommissionCalculation;
use App\Support\Collaborator\CommissionSettings;
use App\Support\Money;

/**
 * Steps C4 to C7 of the spine, as a **pure function** (spine §6.1.6, §6.1.7).
 *
 * It reads nothing and writes nothing: every input is passed in, and the same inputs always produce
 * the same answer. That is what lets three call sites share it — the engine that posts the entry, the
 * preview the record-payment wizard shows a cashier *before* they commit, and the re-evaluation that
 * explains a past decision. A preview computed by a second implementation would be a preview that is
 * occasionally wrong, and the one thing a cashier must be able to trust is the figure on the screen
 * matching the figure on the receipt.
 *
 * All four branches of §6.1.7 live here, and the clamp that follows all of them. The arithmetic is
 * `App\Support\Money` end to end — `prorate()` in particular, because branch C's whole promise is that
 * the slices sum to the entitlement exactly and a share computed in two roundings does not.
 */
final class CommissionCalculator
{
    public function __construct(
        private readonly CommissionEntitlementService $entitlements,
    ) {}

    /**
     * @param  string|null  $promise  the entitlement amount; NULL means uncapped
     */
    public function calculate(
        RuleResolution $rule,
        BaseResolution $document,
        CommissionSettings $settings,
        string $paymentAmount,
        string $collectedBefore,
        string $releasedBefore,
        ?string $promise,
    ): CommissionCalculation {
        $branch = $this->branch($rule);

        // ---- C4: what of this payment is commissionable -------------------------------------
        $remaining = $document->remainingCollectible($collectedBefore);

        $baseAmount = $settings->commissionOnOverpayment
            ? Money::of($paymentAmount)
            : Money::min(Money::of($paymentAmount), $remaining);

        $trace = [
            'branch' => $branch,
            'document_base_amount' => $document->documentBaseAmount,
            'collectible_amount' => $document->collectibleAmount,
            'collected_before' => $collectedBefore,
            'base_amount' => $baseAmount,
            'entitlement_amount' => $promise,
            'released_before' => $releasedBefore,
            'base_fallback' => $rule->baseFallback,
        ];

        if (Money::isZero($baseAmount)) {
            return Money::isPositive(Money::of($paymentAmount))
                ? CommissionCalculation::skips(
                    CommissionSkipReason::OverpaymentOnly,
                    sprintf('The %s collectible on this document was already collected in full, so this '
                        .'%s is an overpayment and earns nothing.',
                        Money::format($document->collectibleAmount), Money::format($paymentAmount)),
                    'C4', $branch, $trace,
                )
                : CommissionCalculation::skips(
                    CommissionSkipReason::BaseZero,
                    'The receipt is for nothing, so there is nothing to earn on.',
                    'C4', $branch, $trace,
                );
        }

        // ---- C5: the release --------------------------------------------------------------------
        if ($branch === 'A' || $branch === 'B') {
            // Per-receipt methods. A cap, if the rule has one, is applied by the clamp below rather
            // than by changing the arithmetic — "10 % of each receipt, up to 1,500" is a ceiling, not
            // a promise spread across the document.
            $release = $branch === 'B'
                ? Money::of((string) $rule->fixedAmount)
                : Money::percentage($baseAmount, (string) $rule->rate);
        } elseif ($branch === 'D') {
            $release = Money::sub((string) $promise, $releasedBefore);
        } else {
            if (Money::isZero($document->collectibleAmount)) {
                return CommissionCalculation::skips(
                    CommissionSkipReason::NoCollectibleDenominator,
                    'The document has nothing collectible against it, so a proportional release has '
                    .'nothing to be a proportion of.',
                    'C5', $branch, $trace,
                );
            }

            $collectedAfter = Money::add($collectedBefore, $baseAmount);

            // The cumulative target ([D-FS-10]). `prorate()` rather than a multiply and a divide: the
            // two-step form quantises the product before dividing, and this is the method whose whole
            // promise is that the slices sum to the entitlement exactly.
            $target = Money::prorate((string) $promise, $collectedAfter, $document->collectibleAmount);
            $release = Money::sub($target, $releasedBefore);

            $trace['collected_after'] = $collectedAfter;
            $trace['target'] = $target;
        }

        $uncapped = $release;

        if ($promise !== null) {
            $release = Money::min($release, Money::sub($promise, $releasedBefore));
        }

        $release = Money::max(Money::ZERO, $release);

        $trace['release'] = $release;
        $trace['rounding_residual'] = Money::sub($uncapped, $release);

        if (Money::isZero($release) && $promise !== null && Money::compare($releasedBefore, $promise) >= 0) {
            return CommissionCalculation::skips(
                CommissionSkipReason::EntitlementCapReached,
                sprintf('The %s promised on this document has already been released in full.',
                    Money::format($promise)),
                'C5', $branch, $trace,
            );
        }

        // ---- C6 / C7 -------------------------------------------------------------------------------
        if (Money::isZero($release)) {
            return CommissionCalculation::skips(
                CommissionSkipReason::RoundsToZero,
                sprintf('%s of %s rounds to nothing at the paisa.',
                    $this->rateSentence($rule), Money::format($baseAmount)),
                'C6', $branch, $trace,
            );
        }

        if (Money::compare($release, $settings->minEntryAmount) < 0) {
            return CommissionCalculation::skips(
                CommissionSkipReason::BelowMinimumCommission,
                sprintf('%s is below the %s minimum the business sets for a ledger entry.',
                    Money::format($release), Money::format($settings->minEntryAmount)),
                'C7', $branch, $trace,
            );
        }

        return CommissionCalculation::releases($baseAmount, $release, $branch, $trace);
    }

    /**
     * Which of §6.1.7's four branches applies. Named rather than inferred at each use, because it is
     * written into the trace and a screen shows it beside the figures.
     */
    public function branch(RuleResolution $rule): string
    {
        if ($this->entitlements->isPerPaymentMethod($rule->calculationType, $rule->base, $rule->release)) {
            return $rule->calculationType === CommissionCalculationType::Fixed ? 'B' : 'A';
        }

        return $rule->release === FixedCommissionRelease::OnFirstPayment ? 'D' : 'C';
    }

    private function rateSentence(RuleResolution $rule): string
    {
        return $rule->calculationType === CommissionCalculationType::Fixed
            ? Money::format((string) $rule->fixedAmount)
            : rtrim(rtrim((string) $rule->rate, '0'), '.').'%';
    }
}
