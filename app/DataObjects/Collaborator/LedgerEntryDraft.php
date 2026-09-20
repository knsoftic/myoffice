<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionApprovalMode;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\LedgerEntryType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Models\Collaborator\CollaboratorReferral;
use App\Support\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * One proposed ledger row, before `LedgerWriter` gives it a dedupe key and writes it
 * (spine §2.11, §6.1.9, phase-10-12 §6.3).
 *
 * **`amount` is always a positive magnitude; the direction is `entryType`.** `signed_amount` is a
 * generated column combining the two, and it is the only column anything ever sums. A draft carrying a
 * negative amount is a caller that has decided to express direction twice, which is how a sum
 * eventually gets it once.
 *
 * **There is no `dedupeKey` field.** The key is composed by the writer from the source table token
 * ([D-IMP-5]) and never accepted from a caller: a caller who can name the key is a caller who can
 * accidentally name a different one for the same receipt, and `uq_cle_dedupe` would then permit the
 * second row it exists to prevent.
 */
final readonly class LedgerEntryDraft
{
    /**
     * @param  array<string, mixed>  $ruleSnapshot  the permanent record of spine §6.1.9
     * @param  array<string, int|null>  $subjectColumns  `student_id`, `student_fee_id`, `project_id`, `project_milestone_id`
     */
    public function __construct(
        public Collaborator $collaborator,
        public LedgerEntryPurpose $purpose,
        public LedgerEntryType $entryType,
        public CommissionSourceType $sourceType,
        public int $sourceId,
        public string $amount,
        public string $baseAmount,
        public string $grossAmount,
        public CommissionBase $base,
        public CommissionCalculationType $calculationType,
        public CommissionStatus $status,
        public CommissionApprovalMode $approvalMode,
        public CarbonInterface $transactionDate,
        public array $ruleSnapshot,
        public ?CollaboratorCommissionEntitlement $entitlement = null,
        public ?CollaboratorReferral $referral = null,
        public ?int $commissionSettingId = null,
        public ?CommissionRuleSource $ruleSource = null,
        public ?string $commissionRate = null,
        public ?string $fixedAmount = null,
        public ?string $entitlementTotal = null,
        public ?string $releasedBefore = null,
        public ?int $studentFeePaymentId = null,
        public ?int $projectPaymentId = null,
        public ?int $paymentReversalId = null,
        public ?int $reversesEntryId = null,
        public array $subjectColumns = [],
        public ?CarbonInterface $holdUntil = null,
        public ?string $notes = null,
    ) {
        if (Money::compare($this->amount, Money::ZERO) !== 1) {
            throw new InvalidArgumentException(
                'A ledger row is a positive magnitude with its direction in `entry_type` — `chk_cle_amount` '
                .'refuses anything else. A zero row is never written at all (INV-2): a guard that '
                .'produced nothing writes a skip reason on the payment instead.'
            );
        }
    }

    /**
     * Does this row credit the partner?
     */
    public function isCredit(): bool
    {
        return $this->entryType === LedgerEntryType::Credit;
    }

    /**
     * The four subject columns, defaulted to null so a draft never has to list the ones it does not use.
     *
     * @return array<string, int|null>
     */
    public function subjects(): array
    {
        return array_merge([
            'student_id' => null,
            'student_fee_id' => null,
            'project_id' => null,
            'project_milestone_id' => null,
        ], $this->subjectColumns);
    }
}
