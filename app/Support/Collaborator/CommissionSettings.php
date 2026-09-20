<?php

declare(strict_types=1);

namespace App\Support\Collaborator;

use App\Enums\CommissionApprovalMode;
use App\Enums\CommissionBase;
use App\Enums\CommissionScope;
use App\Enums\FixedCommissionRelease;
use App\Support\Money;

/**
 * Every global setting the commission engine consults, read **once** and carried as one value
 * (spine §6.1.9, phase-10-12 §6.2).
 *
 * The point is not convenience. Spine §6.1.9 requires every earning row to snapshot the settings that
 * produced it, so that a later change to any of them can never alter what a past row means. If the
 * engine read `setting()` at ten separate points and the snapshot were assembled from a separate list,
 * the two would drift the first time somebody added a guard — and the snapshot would then be a record
 * of what the code *used to* consult. Capturing them in one object and snapshotting **that** makes the
 * two the same thing by construction.
 *
 * It is captured at the start of a calculation and never re-read inside it: a settings row edited
 * halfway through a transaction must not make one receipt use two different approval modes.
 */
final readonly class CommissionSettings
{
    /**
     * @param  list<string>  $commissionableFeeTypes
     */
    public function __construct(
        public bool $referralSystemEnabled,
        public bool $automaticCommissionEnabled,
        public CommissionApprovalMode $approvalMode,
        public int $holdDays,
        public CommissionBase $studentBase,
        public CommissionBase $projectBase,
        public array $commissionableFeeTypes,
        public bool $commissionOnAdmissionFee,
        public bool $commissionOnRegistrationFee,
        public bool $commissionOnOverpayment,
        public FixedCommissionRelease $fixedRelease,
        public string $studentCommissionDocument,
        public string $minEntryAmount,
        public bool $reversalOnRefund,
        public string $clawbackOnPaidCommission,
    ) {}

    public static function capture(): self
    {
        return new self(
            referralSystemEnabled: (bool) setting('collaborator.referral_system_enabled', true),
            automaticCommissionEnabled: (bool) setting('collaborator.automatic_commission_enabled', true),
            approvalMode: CommissionApprovalMode::tryFrom((string) setting('collaborator.commission_approval_mode', 'manual'))
                ?? CommissionApprovalMode::Manual,
            holdDays: max(0, (int) setting('collaborator.commission_hold_days', 0)),
            studentBase: CommissionBase::tryFrom((string) setting('collaborator.student_commission_base', 'paid'))
                ?? CommissionBase::Paid,
            projectBase: CommissionBase::tryFrom((string) setting('collaborator.project_commission_base', 'paid'))
                ?? CommissionBase::Paid,
            commissionableFeeTypes: self::stringList(setting('collaborator.commissionable_fee_types', [])),
            commissionOnAdmissionFee: (bool) setting('collaborator.commission_on_admission_fee', false),
            commissionOnRegistrationFee: (bool) setting('collaborator.commission_on_registration_fee', false),
            commissionOnOverpayment: (bool) setting('collaborator.commission_on_overpayment', false),
            fixedRelease: FixedCommissionRelease::tryFrom((string) setting('collaborator.fixed_commission_release', 'prorated'))
                ?? FixedCommissionRelease::Prorated,
            studentCommissionDocument: (string) setting('collaborator.student_commission_document', 'admission'),
            minEntryAmount: Money::of((string) setting('collaborator.commission_min_entry_amount', '0.00')),
            reversalOnRefund: (bool) setting('collaborator.commission_reversal_on_refund', true),
            clawbackOnPaidCommission: (string) setting('collaborator.clawback_on_paid_commission', 'offset_future'),
        );
    }

    /**
     * The business's base for a scope, before a rule's own override is considered.
     */
    public function baseFor(CommissionScope $scope): CommissionBase
    {
        return $scope === CommissionScope::Student ? $this->studentBase : $this->projectBase;
    }

    /**
     * Exactly the ten keys spine §6.1.9 names, plus the two the reversal side consults, under their
     * real setting names — so a row's snapshot can be read against the Settings screen without a
     * translation table.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'collaborator.referral_system_enabled' => $this->referralSystemEnabled,
            'collaborator.automatic_commission_enabled' => $this->automaticCommissionEnabled,
            'collaborator.commission_approval_mode' => $this->approvalMode->value,
            'collaborator.commission_hold_days' => $this->holdDays,
            'collaborator.student_commission_base' => $this->studentBase->value,
            'collaborator.project_commission_base' => $this->projectBase->value,
            'collaborator.commissionable_fee_types' => $this->commissionableFeeTypes,
            'collaborator.commission_on_admission_fee' => $this->commissionOnAdmissionFee,
            'collaborator.commission_on_registration_fee' => $this->commissionOnRegistrationFee,
            'collaborator.commission_on_overpayment' => $this->commissionOnOverpayment,
            'collaborator.fixed_commission_release' => $this->fixedRelease->value,
            'collaborator.student_commission_document' => $this->studentCommissionDocument,
            'collaborator.commission_min_entry_amount' => $this->minEntryAmount,
            'collaborator.commission_reversal_on_refund' => $this->reversalOnRefund,
            'collaborator.clawback_on_paid_commission' => $this->clawbackOnPaidCommission,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return is_array($value)
            ? array_values(array_map(static fn (mixed $item): string => (string) $item, $value))
            : [];
    }
}
