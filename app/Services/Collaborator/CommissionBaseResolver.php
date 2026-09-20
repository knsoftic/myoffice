<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionBase;
use App\Enums\EntitlementDocumentType;
use App\Models\Finance\ProjectPayment;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Which document a payment earns against, and the two figures that bound the promise
 * (spine §6.1.4, phase-10-12 §6.3).
 *
 * **It reads the payment row and the document row, and nothing else.** In particular it never reads a
 * cached `paid_amount`: that column is maintained by `PaymentService` and is exactly the kind of figure
 * that is right 999 times and stale once, at which point a partner is either underpaid in silence or
 * paid on money the business does not have. The denominator is what is *collectible* — the net of the
 * charge, the net value of the project, the amount of the milestone — and how much of it has been
 * collected is the entitlement's own `collected_amount`, which is written in the same transaction as
 * the release that advanced it.
 *
 * That is what makes every base mode the same sentence — "money received, bounded by a collectible
 * figure" — and why commission can never accrue on unreceived money (INV-19).
 */
final class CommissionBaseResolver
{
    /**
     * Whether Phase 18's admissions table exists yet. Resolved once per process: the student side asks
     * on every receipt, and the answer cannot change while the process is running.
     */
    private ?bool $admissionsExist = null;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The student side (spine §6.1.4, first three rows).
     *
     * The document is the admission or the charge, per `collaborator.student_commission_document` —
     * "one promise per admission" and "one promise per charge" are genuinely different businesses, and
     * the setting is how the institute says which one it runs. A charge with no admission behind it
     * falls back to the charge, because the alternative is refusing to pay commission on a receipt
     * somebody has already taken money for.
     */
    public function forStudentPayment(StudentFeePayment $payment, CommissionBase $base): BaseResolution
    {
        $fee = $payment->relationLoaded('fee') ? $payment->fee : $payment->fee()->first();

        if (! $fee instanceof StudentFee) {
            throw new RuntimeException(sprintf(
                'Receipt %s has no fee charge behind it. `student_fee_payments.student_fee_id` is NOT '
                .'NULL with a RESTRICT foreign key, so this can only mean the row was read across a '
                .'rolled-back write.',
                (string) $payment->receipt_no,
            ));
        }

        $admission = $this->admissionFor($fee);

        if ($admission !== null) {
            return $this->resolve(
                type: EntitlementDocumentType::StudentAdmission,
                id: (int) $admission->id,
                gross: (string) $admission->course_fee,
                net: (string) $admission->net_payable,
                base: $base,
            );
        }

        return $this->resolve(
            type: EntitlementDocumentType::StudentFee,
            id: (int) $fee->getKey(),
            gross: (string) $fee->gross_amount,
            net: $fee->collectibleAmount(),
            base: $base,
        );
    }

    /**
     * The project side (spine §6.1.4, last four rows). Phase 11 calls it; the figures are stated here
     * because the quadruple has to have one definition, not one per engine.
     *
     * `milestone` is the only base whose document is not the project, and the milestone it names is the
     * one the payment was recorded against — never "the next unpaid one", which would silently move the
     * promise every time somebody reordered a plan.
     */
    public function forProjectPayment(ProjectPayment $payment, CommissionBase $base, RuleResolution $rule): BaseResolution
    {
        $project = $payment->relationLoaded('project') ? $payment->project : $payment->project()->first();

        if ($project === null) {
            throw new RuntimeException(sprintf(
                'Payment %s has no project behind it, which its NOT NULL foreign key makes impossible.',
                (string) $payment->payment_no,
            ));
        }

        if ($base === CommissionBase::Milestone) {
            $milestone = $payment->relationLoaded('milestone') ? $payment->milestone : $payment->milestone()->first();

            if ($milestone === null) {
                throw new RuntimeException(
                    'A milestone base reached the resolver with no milestone on the payment. G10 is '
                    .'what stops that, and it runs before this.'
                );
            }

            return $this->resolve(
                type: EntitlementDocumentType::ProjectMilestone,
                id: (int) $milestone->getKey(),
                gross: (string) $milestone->amount,
                net: (string) $milestone->amount,
                base: $base,
            );
        }

        return $this->resolve(
            type: EntitlementDocumentType::Project,
            id: (int) $project->getKey(),
            gross: $base === CommissionBase::TotalValue
                ? (string) $project->project_value
                : (string) $project->net_value,
            net: (string) $project->net_value,
            base: $base,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The quadruple. One place, so the three student modes and the four project modes cannot drift
     * apart into seven slightly different definitions of "what the promise is a percentage of".
     */
    private function resolve(
        EntitlementDocumentType $type,
        int $id,
        string $gross,
        string $net,
        CommissionBase $base,
    ): BaseResolution {
        // `gross` is the only mode that promises on the pre-discount figure. Every other mode promises
        // on the net, and `paid` uses it purely as the overpayment cap — the same shape either way, so
        // no branch downstream has to know which mode it is in.
        $documentBase = $base === CommissionBase::Gross || $base === CommissionBase::TotalValue
            ? Money::of($gross)
            : Money::of($net);

        return new BaseResolution(
            documentType: $type,
            documentId: $id,
            documentBaseAmount: $documentBase,
            // Never negative: a document collected past its own net had nothing more to collect, it did
            // not become collectible in reverse.
            collectibleAmount: Money::max(Money::ZERO, Money::of($net)),
            base: $base,
        );
    }

    /**
     * The admission behind a charge, when the institute promises per admission and one exists.
     *
     * Read as a plain row rather than through a model: the admissions table belongs to Phase 18, and
     * this must keep working — falling back to the charge — for every install that has not reached it.
     */
    private function admissionFor(StudentFee $fee): ?object
    {
        if ((string) setting('collaborator.student_commission_document', 'admission') !== 'admission') {
            return null;
        }

        if ($fee->student_admission_id === null || ! $this->admissionsExist()) {
            return null;
        }

        return $this->db->table('student_admissions')
            ->where('id', $fee->student_admission_id)
            ->first(['id', 'course_fee', 'net_payable']);
    }

    private function admissionsExist(): bool
    {
        return $this->admissionsExist ??= Schema::hasTable('student_admissions');
    }
}
