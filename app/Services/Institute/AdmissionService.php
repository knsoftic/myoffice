<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\AdmissionStage;
use App\Enums\StudentStatus;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentApplication;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * §68's admission pipeline, one public method per step (phase-14-17 §6.6, §2.30.5, §2.31).
 *
 * **Every method asserts the stage before it.** `activate()` called on an admission still at
 * `application` throws and names the step that was skipped, rather than quietly producing an active
 * student with no registration number and no fee. That is the whole value of holding the pipeline in
 * one column: there is exactly one answer to "where are we", and it is checked.
 *
 * **Stages 5 and 6 may happen in either order, and that is deliberate.** Institutes seat a student
 * before the first installment clears every day. The rule lives in the single transition into
 * `active`, governed by `institute.require_fee_before_activation` — `none`, `any_payment` or
 * `full_first_installment`. Forbidding an order in the stage machine would hide a policy where nobody
 * could find it.
 *
 * **The figures freeze on the first charge (INV-I2).** `updateFigures()` refuses once
 * `figures_locked_at` is set, because a commission has been computed from those numbers by then and
 * money may already have moved. A correction after the lock is a fee adjustment, which leaves its own
 * row in Phase 18's discount table.
 *
 * **This service writes no fee row and no enrollment row.** `requestFees()` delegates to Phase 18's
 * `StudentFeeService::generateStructure()` and `assignBatch()` to Phase 16's `BatchEnrollmentService`;
 * where those have not shipped, the method says so plainly rather than half-doing their job (INV-I1).
 */
final class AdmissionService
{
    /** §2.30.5, verbatim. The two terminal moves are reachable from any live stage. */
    private const TRANSITIONS = [
        'application' => ['registration'],
        'registration' => ['fee_collection', 'batch_assignment'],
        'fee_collection' => ['batch_assignment'],
        'batch_assignment' => ['active'],
        'active' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'withdrawn' => [],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentNumberService $numbers,
        private readonly StudentService $students,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Step 3 — the admission record
    |--------------------------------------------------------------------------
    */

    /**
     * Create the admission from an application (or from a walk-in, with `$application` null).
     *
     * @param  array<string, mixed>  $overrides  the agreed figures, where they differ from the course
     */
    public function create(
        Student $student,
        Course $course,
        array $overrides = [],
        ?StudentApplication $application = null,
        ?User $actor = null,
    ): StudentAdmission {
        $this->assertNoLiveAdmission($student, $course);

        return $this->db->transaction(function () use ($student, $course, $overrides, $application, $actor): StudentAdmission {
            $figures = $this->figuresFrom($course, $overrides);

            $admission = new StudentAdmission;
            $admission->forceFill(array_merge($figures, [
                'admission_number' => $this->numbers->nextAdmissionNumber(),
                'student_id' => $student->getKey(),
                'course_id' => $course->getKey(),
                'branch_id' => $overrides['branch_id'] ?? $student->branch_id ?? $course->branch_id,
                'student_application_id' => $application?->getKey(),
                'course_inquiry_id' => $application?->course_inquiry_id ?? ($overrides['course_inquiry_id'] ?? null),
                'stage' => AdmissionStage::Application->value,
                'admission_date' => $overrides['admission_date'] ?? Carbon::now()->toDateString(),
                'counselor_id' => $overrides['counselor_id'] ?? $actor?->getKey(),
                'delivery_mode' => $overrides['delivery_mode'] ?? $application?->preferred_delivery_mode?->value ?? $course->delivery_mode?->value,
                'preferred_timing' => $overrides['preferred_timing'] ?? $application?->preferred_timing?->value,
                'monthly_fee' => $overrides['monthly_fee'] ?? $course->monthly_fee,
                'payment_method' => $overrides['payment_method'] ?? null,
                'discount_reason' => $overrides['discount_reason'] ?? null,
                'notes' => $overrides['notes'] ?? null,
                'created_by' => $actor?->getKey(),
            ]))->save();

            // The student follows the admission: §2.31 step 3 puts them at `applied`.
            if ($student->status === StudentStatus::Inquiry || $student->status === StudentStatus::Dropped
                || $student->status === StudentStatus::Completed) {
                $this->students->changeStatus($student, StudentStatus::Applied, null, $actor);
            }

            return $admission->refresh();
        }, 3);
    }

    /**
     * The agreed figures, before anything has been charged.
     *
     * @param  array<string, mixed>  $money
     */
    public function updateFigures(StudentAdmission $admission, array $money, string $reason, ?User $actor = null): StudentAdmission
    {
        if ($admission->figuresAreLocked()) {
            throw CourseRuleException::refuse('figures', sprintf(
                'The figures on %s were locked when the first charge was issued. A commission has been '
                .'computed from them and money may already have moved, so a correction is a fee '
                .'adjustment on the charge — not an edit here (INV-I2).',
                $admission->admission_number,
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired('figures',
                'Changing what was agreed takes a reason: it is the answer to "why is this student '
                .'paying less than that one".');
        }

        return $this->db->transaction(function () use ($admission, $money, $reason, $actor): StudentAdmission {
            $figures = $this->figuresFrom($admission->course, array_merge([
                'course_fee' => $admission->course_fee,
                'admission_fee' => $admission->admission_fee,
                'registration_fee' => $admission->registration_fee,
                'discount_amount' => $admission->discount_amount,
                'scholarship_amount' => $admission->scholarship_amount,
            ], $money));

            $admission->withReason($reason);
            $admission->forceFill(array_merge($figures, [
                'discount_reason' => $money['discount_reason'] ?? $admission->discount_reason,
                'monthly_fee' => $money['monthly_fee'] ?? $admission->monthly_fee,
                'updated_by' => $actor?->getKey(),
            ]))->save();

            return $admission->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Steps 4 to 7
    |--------------------------------------------------------------------------
    */

    /** Step 4: issue the registration number. */
    public function register(StudentAdmission $admission, ?User $actor = null): StudentAdmission
    {
        $this->assertStage($admission, AdmissionStage::Registration);

        return $this->db->transaction(function () use ($admission, $actor): StudentAdmission {
            $student = $admission->student;

            // Issued once per student, not once per admission: somebody taking a second course is the
            // same registered person, and a second number would make them two people in the records.
            if (! $student->isRegistered()) {
                $student->forceFill([
                    'registration_number' => $this->numbers->nextRegistrationNumber($student, $admission),
                    'updated_by' => $actor?->getKey(),
                ])->save();
            }

            $admission->forceFill([
                'stage' => AdmissionStage::Registration->value,
                'registration_date' => Carbon::now()->toDateString(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            if ($student->refresh()->status === StudentStatus::Applied) {
                $this->students->changeStatus($student, StudentStatus::Registered, null, $actor);
            }

            return $admission->refresh();
        }, 3);
    }

    /**
     * Step 5: hand the fee structure to Phase 18, which is the only thing that may write a charge.
     *
     * @param  array<string, mixed>  $plan
     */
    public function requestFees(StudentAdmission $admission, array $plan = [], ?User $actor = null): StudentAdmission
    {
        $this->assertStage($admission, AdmissionStage::FeeCollection);

        $course = $admission->course;
        $installments = (int) ($plan['installments'] ?? $admission->requested_installments);

        if ($installments > 0) {
            if (! (bool) $course->installment_available) {
                throw CourseRuleException::refuse('installments', sprintf(
                    '%s is not sold in installments, so a plan cannot be built for it.',
                    $course->name,
                ));
            }

            if ($installments > (int) $course->max_installments) {
                throw CourseRuleException::refuse('installments', sprintf(
                    '%s allows at most %d installments; %d were asked for.',
                    $course->name,
                    (int) $course->max_installments,
                    $installments,
                ));
            }
        }

        $service = 'App\\Services\\Institute\\StudentFeeService';

        if (! class_exists($service)) {
            throw CourseRuleException::refuse('fees',
                'The fee structure is written by StudentFeeService, which ships with Phase 18. This '
                .'phase will not write a charge row of its own: a charge nobody can reconcile against '
                .'the service that owns them is worse than not having one (INV-I1).');
        }

        return $this->db->transaction(function () use ($admission, $plan, $installments, $service, $actor): StudentAdmission {
            app($service)->generateStructure($admission, $plan, $actor);

            $admission->forceFill([
                'stage' => AdmissionStage::FeeCollection->value,
                'installment_plan_requested' => $installments > 0,
                'requested_installments' => $installments,
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $admission->refresh();
        }, 3);
    }

    /**
     * Step 6: the batch seat, enrolled by Phase 16's service under a batch row lock.
     *
     * @param  array<string, mixed>  $options
     */
    public function assignBatch(StudentAdmission $admission, int $batchId, array $options = [], ?User $actor = null): StudentAdmission
    {
        $this->assertStage($admission, AdmissionStage::BatchAssignment);

        $service = 'App\\Services\\Institute\\BatchEnrollmentService';

        if (! class_exists($service)) {
            throw CourseRuleException::refuse('batch',
                'Enrolment is BatchEnrollmentService\'s, which ships with Phase 16 — capacity is '
                .'checked there under a row lock, and a seat handed out anywhere else is a seat that '
                .'was never counted.');
        }

        return $this->db->transaction(function () use ($admission, $batchId, $options, $service, $actor): StudentAdmission {
            app($service)->enroll($admission->student, $batchId, $admission, $options, $actor);

            $admission->forceFill([
                'stage' => AdmissionStage::BatchAssignment->value,
                'batch_id' => $batchId,
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $admission->refresh();
        }, 3);
    }

    /** Step 7: the student becomes active — the one place §68's ordering rule is applied. */
    public function activate(StudentAdmission $admission, ?User $actor = null): StudentAdmission
    {
        $this->assertStage($admission, AdmissionStage::Active);

        $unmet = $this->activationGaps($admission);

        if ($unmet !== []) {
            throw CourseRuleException::refuse('stage', sprintf(
                'This admission is not ready to activate: %s. Each one is a step of its own, and '
                .'skipping it here would produce an active student the rest of the system cannot '
                .'account for.',
                implode('; ', $unmet),
            ));
        }

        return $this->db->transaction(function () use ($admission, $actor): StudentAdmission {
            $admission->forceFill([
                'stage' => AdmissionStage::Active->value,
                'activated_on' => Carbon::now()->toDateString(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $student = $admission->student;

            if ($student->status !== StudentStatus::Active) {
                $this->students->changeStatus($student, StudentStatus::Active, null, $actor);
            }

            $this->students->createLogin($student->refresh(), $actor);

            return $admission->refresh();
        }, 3);
    }

    public function complete(StudentAdmission $admission, ?User $actor = null): StudentAdmission
    {
        $this->assertStage($admission, AdmissionStage::Completed);

        return $this->db->transaction(function () use ($admission, $actor): StudentAdmission {
            $admission->forceFill([
                'stage' => AdmissionStage::Completed->value,
                'completed_on' => Carbon::now()->toDateString(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $student = $admission->student;

            // Only when nothing else is running: a student finishing one of two courses is still an
            // active student, and marking them complete would take them off every live roster.
            if ($student->liveAdmissions()->count() === 0 && $student->status === StudentStatus::Active) {
                $this->students->changeStatus($student, StudentStatus::Completed, null, $actor);
            }

            return $admission->refresh();
        }, 3);
    }

    /** The institute calls it off. Refused while Phase 18 holds a cleared receipt. */
    public function cancel(StudentAdmission $admission, string $reason, ?User $actor = null): StudentAdmission
    {
        return $this->terminate($admission, AdmissionStage::Cancelled, $reason, $actor);
    }

    /** The student walks away. */
    public function withdraw(StudentAdmission $admission, string $reason, ?User $actor = null): StudentAdmission
    {
        return $this->terminate($admission, AdmissionStage::Withdrawn, $reason, $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | What the stepper asks
    |--------------------------------------------------------------------------
    */

    /**
     * Why this admission cannot be activated yet — the checklist §8.8's last step renders, each item
     * linked to the step that fills it. Empty means ready.
     *
     * @return list<string>
     */
    public function activationGaps(StudentAdmission $admission): array
    {
        $gaps = [];

        if (! $admission->student->isRegistered()) {
            $gaps[] = 'no registration number has been issued';
        }

        if ($admission->batch_id === null) {
            $gaps[] = 'no batch has been assigned';
        }

        $rule = (string) setting('institute.require_fee_before_activation', 'any_payment');

        if ($rule === 'none') {
            return $gaps;
        }

        // Read from Phase 18's own rows, never recomputed here: the charge and the receipt are its
        // records, and a second opinion about whether somebody has paid is one opinion too many.
        if (Money::compare((string) $admission->charged_amount, Money::ZERO) <= 0) {
            $gaps[] = 'nothing has been charged yet';

            return $gaps;
        }

        $paid = Money::sub((string) $admission->paid_amount, (string) $admission->refunded_amount);

        if ($rule === 'any_payment' && Money::compare($paid, Money::ZERO) <= 0) {
            $gaps[] = 'no payment has been received (the institute requires at least one)';
        }

        if ($rule === 'full_first_installment') {
            $due = $this->firstInstallmentAmount($admission);

            if ($due !== null && Money::compare($paid, $due) < 0) {
                $gaps[] = sprintf('the first installment of %s is not paid in full', money($due));
            } elseif ($due === null && Money::compare($paid, Money::ZERO) <= 0) {
                $gaps[] = 'no payment has been received';
            }
        }

        return $gaps;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function terminate(StudentAdmission $admission, AdmissionStage $to, string $reason, ?User $actor): StudentAdmission
    {
        if ($admission->stage->isTerminal()) {
            throw CourseRuleException::refuse('stage', sprintf(
                '%s is already %s.', $admission->admission_number, $admission->stage->label(),
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::reasonRequired('reason', sprintf(
                'Marking an admission %s takes a reason — it is the record of why somebody who was '
                .'enrolled no longer is.',
                $to->label(),
            ));
        }

        if ($to === AdmissionStage::Cancelled) {
            $this->assertNoClearedReceipt($admission);
        }

        return $this->db->transaction(function () use ($admission, $to, $reason, $actor): StudentAdmission {
            $admission->withReason($reason);

            $admission->forceFill($to === AdmissionStage::Cancelled ? [
                'stage' => $to->value,
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $actor?->getKey(),
                'cancellation_reason' => $reason,
                'updated_by' => $actor?->getKey(),
            ] : [
                'stage' => $to->value,
                'withdrawn_at' => Carbon::now(),
                'withdrawal_reason' => $reason,
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $admission->refresh();
        }, 3);
    }

    /**
     * A cancellation is not a refund. If money has cleared, somebody has to decide what happens to it
     * first, and that decision is Phase 18's.
     */
    private function assertNoClearedReceipt(StudentAdmission $admission): void
    {
        if (Money::compare((string) $admission->paid_amount, Money::ZERO) <= 0) {
            return;
        }

        throw CourseRuleException::refuse('stage', sprintf(
            '%s has %s received against it. Cancelling would leave that money attached to an admission '
            .'that no longer exists — refund or transfer it first, which is a fee decision, not a '
            .'side effect of this one.',
            $admission->admission_number,
            money((string) $admission->paid_amount),
        ));
    }

    private function assertStage(StudentAdmission $admission, AdmissionStage $to): void
    {
        $from = $admission->stage;
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (in_array($to->value, $allowed, true)) {
            return;
        }

        throw CourseRuleException::refuse('stage', sprintf(
            '%s is at %s and cannot go straight to %s. %s',
            $admission->admission_number,
            $from->label(),
            $to->label(),
            $allowed === []
                ? 'It has reached the end of the pipeline.'
                : 'The next step is '.implode(' or ', array_map(
                    static fn (string $s): string => AdmissionStage::from($s)->label(),
                    $allowed,
                )).'.',
        ));
    }

    private function assertNoLiveAdmission(Student $student, Course $course): void
    {
        $live = StudentAdmission::query()
            ->live()
            ->where('student_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->first();

        if ($live instanceof StudentAdmission) {
            throw CourseRuleException::refuse('course_id', sprintf(
                '%s already has a live admission to %s (%s, %s). Finish, cancel or withdraw it before '
                .'starting another.',
                $student->name,
                $course->name,
                $live->admission_number,
                $live->stage->label(),
            ));
        }
    }

    /**
     * The three fees, the two reductions, and the two totals — computed through Money, never in PHP
     * arithmetic, and never trusting a total the form sent.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    private function figuresFrom(Course $course, array $overrides): array
    {
        $courseFee = Money::of((string) ($overrides['course_fee'] ?? $course->course_fee ?? '0.00'));
        $admissionFee = Money::of((string) ($overrides['admission_fee'] ?? $course->admission_fee ?? '0.00'));
        $registrationFee = Money::of((string) ($overrides['registration_fee'] ?? $course->registration_fee ?? '0.00'));
        $discount = Money::of((string) ($overrides['discount_amount'] ?? '0.00'));
        $scholarship = Money::of((string) ($overrides['scholarship_amount'] ?? '0.00'));

        $total = Money::sum($courseFee, $admissionFee, $registrationFee);
        $reductions = Money::add($discount, $scholarship);

        if (Money::compare($reductions, $total) > 0) {
            throw CourseRuleException::refuse('discount_amount', sprintf(
                'A discount of %s and a scholarship of %s come to more than the %s being charged. '
                .'Nothing is sold below free.',
                money($discount),
                money($scholarship),
                money($total),
            ));
        }

        return [
            'course_fee' => $courseFee,
            'admission_fee' => $admissionFee,
            'registration_fee' => $registrationFee,
            'discount_amount' => $discount,
            'scholarship_amount' => $scholarship,
            'total_amount' => $total,
            'net_payable' => Money::sub($total, $reductions),
        ];
    }

    /**
     * What the first installment is worth, when Phase 18 has built one. Null when there is no plan —
     * the activation rule then falls back to "any payment", which is what it can honestly check.
     */
    private function firstInstallmentAmount(StudentAdmission $admission): ?string
    {
        $connection = $this->db->connection();

        if (! $connection->getSchemaBuilder()->hasTable('student_fee_installments')) {
            return null;
        }

        $amount = $connection->table('student_fee_installments')
            ->join('student_fees', 'student_fees.id', '=', 'student_fee_installments.student_fee_id')
            ->where('student_fees.student_admission_id', $admission->getKey())
            ->orderBy('student_fee_installments.due_date')
            ->orderBy('student_fee_installments.id')
            ->value('student_fee_installments.amount');

        return $amount === null ? null : Money::of((string) $amount);
    }
}
