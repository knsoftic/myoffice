<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Collaborator\CommissionOutcome;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionBase;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\LedgerEntryPurpose;
use App\Enums\ReferralSubject;
use App\Enums\StudentFeeType;
use App\Models\Collaborator\CollaboratorReferral;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Models\Project\Project;
use App\Services\Collaborator\Concerns\RunsCommissionGuards;
use App\Support\Collaborator\CommissionSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * **The only place a student commission amount is computed** (spine §6.1, phase-10-12 §6.1 [D-IMP-3]).
 *
 * Everything that wants a student commission — the record-payment screen, an import, the sweeper, the
 * audited manual re-evaluation — arrives here through `ProcessStudentFeeCommission`, and none of them
 * does its own arithmetic. That is enforced rather than agreed: a static scan asserts `Money::percentage`
 * and the string `commission_rate` appear nowhere outside `app/Services/Collaborator/` and `Money`
 * itself.
 *
 * The sequence lives in {@see RunsCommissionGuards} and is shared with the project engine. This class
 * supplies the four things that are genuinely about students: which subject carries the attribution,
 * which fee types are commissionable, which document the promise is against, and which columns the
 * ledger row fills in.
 */
final class StudentCommissionService
{
    use RunsCommissionGuards;

    public function __construct(
        private readonly CommissionEngineContext $engine,
    ) {}

    /**
     * Evaluate one receipt. Idempotent under unlimited replay; `$force` bypasses G2 only.
     */
    public function handlePayment(StudentFeePayment $payment, bool $force = false): CommissionOutcome
    {
        return $this->process($payment, $force);
    }

    /*
    |--------------------------------------------------------------------------
    | What the shared sequence asks of the student side
    |--------------------------------------------------------------------------
    */

    protected function engine(): CommissionEngineContext
    {
        return $this->engine;
    }

    protected function scope(): CommissionScope
    {
        return CommissionScope::Student;
    }

    protected function purpose(): LedgerEntryPurpose
    {
        return LedgerEntryPurpose::StudentCommission;
    }

    /**
     * The attribution is on the **student**, not on the charge.
     *
     * A student referred by a partner earns them commission on every fee that student is charged, which
     * is what a referral agreement actually says. Attributing per charge would mean a partner stopped
     * earning the moment the institute raised a second invoice.
     *
     * Asked by type and id rather than through a model: the student model belongs to Phase 18, and the
     * engine has to work against the id the charge already carries.
     */
    protected function referralFor(Model $payment): ?CollaboratorReferral
    {
        return $this->engine->referrals->effectiveOnSubject(
            ReferralSubject::Student,
            (int) $payment->student_id,
            $payment->paid_on,
        );
    }

    /**
     * There is no per-project override on the student side — §45's override is a statement about one
     * engagement, and a student is not one.
     */
    protected function ruleProject(Model $payment): ?Project
    {
        return null;
    }

    /**
     * G10, student form: is this **kind** of fee commissionable (spine §6.1.2)?
     *
     * The allowed set is the rule's own list when it has one, otherwise the business setting. Two Phase
     * 2 booleans then override the set for their own types — an institute that does not pay commission
     * on admission fees has said so in one place, and a rule that happens to list `admission_fee` must
     * not quietly reverse that.
     *
     * @return array{0: CommissionSkipReason, 1: string}|null
     */
    protected function scopeGuard(Model $payment, RuleResolution $rule, CommissionSettings $settings): ?array
    {
        $fee = $this->feeFor($payment);
        $type = $fee->fee_type;

        $allowed = $rule->appliesToFeeTypes ?? $settings->commissionableFeeTypes;
        $permitted = in_array($type->value, $allowed, true);

        if ($type === StudentFeeType::AdmissionFee) {
            $permitted = $settings->commissionOnAdmissionFee;
        }

        if ($type === StudentFeeType::RegistrationFee) {
            $permitted = $settings->commissionOnRegistrationFee;
        }

        if ($permitted) {
            return null;
        }

        return [
            CommissionSkipReason::FeeTypeNotCommissionable,
            sprintf('%s does not earn commission%s.',
                $type->label(),
                $rule->appliesToFeeTypes === null
                    ? ' under the institute\'s settings'
                    : ' under this partner\'s rule'),
        ];
    }

    protected function resolveDocument(Model $payment, CommissionBase $base, RuleResolution $rule): BaseResolution
    {
        return $this->engine->bases->forStudentPayment($payment, $base);
    }

    /**
     * §51's reporting distinction, and **only** a reporting distinction: a receipt allocated to an
     * installment line is still one `student_fee_payments` row, and `LedgerWriter` keys its dedupe on
     * the table rather than on this ([D-IMP-5]). Without that rule the same receipt could produce two
     * rows, one per source type, and `uq_cle_source` would not catch it.
     */
    protected function sourceTypeFor(Model $payment): CommissionSourceType
    {
        return $payment->student_fee_installment_id === null
            ? CommissionSourceType::StudentFeePayment
            : CommissionSourceType::StudentInstallmentPayment;
    }

    /**
     * @return array<string, int|null>
     */
    protected function subjectColumnsFor(Model $payment): array
    {
        return [
            'student_id' => (int) $payment->student_id,
            'student_fee_id' => (int) $payment->student_fee_id,
        ];
    }

    protected function paymentColumn(): string
    {
        return 'student_fee_payment_id';
    }

    private function feeFor(Model $payment): StudentFee
    {
        return $payment->relationLoaded('fee') ? $payment->fee : $payment->fee()->firstOrFail();
    }
}
