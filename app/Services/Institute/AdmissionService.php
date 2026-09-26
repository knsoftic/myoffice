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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 * **A basket of courses is N admissions, not one admission with N courses ([D172]).** `createMany()`
 * exists because the operator ticks several courses on one screen and quotes one figure; what it
 * creates is still one admission per course, because everything downstream is per course. A batch
 * belongs to exactly one course (`batches.course_id` NOT NULL), `admissions.batch_id` is one scalar,
 * `completed_on` is one date, a certificate is drafted from one enrolment, and `uq_sadm_live` is
 * `(student_id, course_id, active_guard)` - a row covering three courses would make the first three
 * unanswerable and the fourth unenforceable. So the screen totals; the database does not.
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
     * A basket: one admission per course, all of them or none of them.
     *
     * **The whole point is the transaction.** `create()` opens its own, and its duplicate guard runs
     * *before* it - so calling `create()` in a loop commits each course separately, and a basket whose
     * third course is already live leaves two admissions standing with their own numbers, their own
     * student-status transition and no record anywhere of what the operator meant to do. The failure
     * looks like "nothing was saved", because it comes back as a field error on the form they just
     * submitted. This method does the work once, inside one transaction, and refuses the whole basket.
     *
     * **Every course is checked before any row is written**, and every conflict is reported together.
     * Checking inside the loop would name the first clash and hide the rest, so the operator would fix
     * one course, resubmit, and meet the next one.
     *
     * **The discount is agreed on the basket and stored per course, pro-rata.** One typed figure has
     * to land in N `discount_amount` columns, each with its own CHECK ceiling. `Money::allocate()`
     * splits it by each line's gross with largest-remainder placement, so the shares sum back to
     * exactly what was typed - no paisa invented, none lost - and each share stays under its line's
     * ceiling because the split is proportional to that ceiling.
     *
     * **`admission_fee` and `registration_fee` are charged once for the basket by default.** They are
     * per-student heads in practice: `register()` itself issues one registration number per student.
     * Prefilling them from every course would triple what a three-course student is quoted, silently.
     * Pass `fees_once => false` to charge them per course.
     *
     * The money consequence to know about, because no screen shows it: a partner commission is
     * resolved **per admission**, and `max_commission_amount` and a `fixed` amount are applied per
     * entitlement. A basket of three earns a fixed rule three times, and - the mirror - can split one
     * sale into three releases that each fall under `collaborator.commission_min_entry_amount` and are
     * skipped. Neither is new: creating three admissions by hand has always done this. What is new is
     * that one click does it, which is why it is written down here rather than left to be discovered.
     *
     * @param  list<array<string, mixed>>  $lines  one per course, each with a `course_id` and any of
     *                                             `course_fee`, `admission_fee`, `registration_fee`,
     *                                             `monthly_fee` overriding that course's catalogue price
     * @param  array<string, mixed>  $shared  what the basket agrees once: `admission_date`,
     *                                        `counselor_id`, `delivery_mode`, `preferred_timing`,
     *                                        `discount_amount`, `scholarship_amount`,
     *                                        `discount_reason`, `notes`, `branch_id`, `fees_once`
     * @return Collection<int, StudentAdmission>
     */
    public function createMany(Student $student, array $lines, array $shared = [], ?User $actor = null): Collection
    {
        $lines = $this->resolveLines($lines);
        $this->assertNoLiveAdmissions($student, array_column($lines, 'course'));

        $lines = $this->spreadBasketFigures($lines, $shared);

        try {
            return $this->db->transaction(function () use ($student, $lines, $shared, $actor): Collection {
                $created = new Collection;

                foreach ($lines as $line) {
                    /** @var Course $course */
                    $course = $line['course'];

                    $created->push($this->insertAdmission(
                        $student,
                        $course,
                        array_merge($shared, $line['overrides']),
                        null,
                        $actor,
                    ));
                }

                // Once, not once per course: the student moves to `applied` because they applied.
                $this->moveStudentToApplied($student, $actor);

                return $created;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // `assertNoLiveAdmissions()` is a SELECT, so two submits of the same basket can both pass
            // it and race into `uq_sadm_live`. The database is the real guard; this turns its 1062
            // into the sentence the operator would have got a moment earlier.
            throw CourseRuleException::refuse('course_ids',
                'One of these courses was admitted a moment ago - most likely this form was submitted '
                .'twice. Nothing was saved by this attempt. Reload the student to see what exists.');
        }
    }

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
            $admission = $this->insertAdmission($student, $course, $overrides, $application, $actor);

            // The student follows the admission: §2.31 step 3 puts them at `applied`.
            $this->moveStudentToApplied($student, $actor);

            return $admission;
        }, 3);
    }

    /**
     * The insert itself, with no transaction and no student-status side effect.
     *
     * Extracted so `create()` and `createMany()` write the row exactly the same way. The two things it
     * deliberately does NOT do are the two that must not happen once per course: opening a transaction
     * (the basket needs one around all of them) and moving the student (which happens once).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertAdmission(
        Student $student,
        Course $course,
        array $overrides,
        ?StudentApplication $application,
        ?User $actor,
    ): StudentAdmission {
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

        return $admission->refresh();
    }

    /** §2.31 step 3. Guarded rather than blind: `applied -> applied` is not a transition. */
    private function moveStudentToApplied(Student $student, ?User $actor): void
    {
        if ($student->status === StudentStatus::Inquiry || $student->status === StudentStatus::Dropped
            || $student->status === StudentStatus::Completed) {
            $this->students->changeStatus($student, StudentStatus::Applied, null, $actor);
        }
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

    /**
     * Turn the submitted lines into `['course' => Course, 'overrides' => array]`, in submitted order.
     *
     * Order is load-bearing: the basket's admission and registration fees land on the FIRST line, so
     * whichever course the operator ticked first is the one that carries them. Any order would do; a
     * stable one is what lets the screen's preview agree with what gets stored.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{course: Course, overrides: array<string, mixed>}>
     */
    private function resolveLines(array $lines): array
    {
        if ($lines === []) {
            throw CourseRuleException::refuse('course_ids', 'Pick at least one course.');
        }

        $resolved = [];
        $seen = [];

        foreach ($lines as $line) {
            $courseId = (int) ($line['course_id'] ?? 0);

            if (isset($seen[$courseId])) {
                // Not merely tidiness: two lines for one course would both pass the pre-check and then
                // collide on `uq_sadm_live` mid-transaction, which reads to the operator as a database
                // error rather than as the duplicate tick it is.
                throw CourseRuleException::refuse('course_ids',
                    'The same course is in this basket twice. One admission per course is what the rest '
                    .'of the system is built on, so pick it once.');
            }

            $course = Course::query()->find($courseId);

            if (! $course instanceof Course) {
                throw CourseRuleException::refuse('course_ids', 'One of the chosen courses no longer exists.');
            }

            $seen[$courseId] = true;

            $overrides = array_filter(
                [
                    'course_fee' => $line['course_fee'] ?? null,
                    'admission_fee' => $line['admission_fee'] ?? null,
                    'registration_fee' => $line['registration_fee'] ?? null,
                    'monthly_fee' => $line['monthly_fee'] ?? null,
                ],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );

            $resolved[] = ['course' => $course, 'overrides' => $overrides];
        }

        return $resolved;
    }

    /**
     * Every course in the basket, checked before a single row is written.
     *
     * `assertNoLiveAdmission()` throws on the first clash. Used in a loop over a basket that is about
     * to be inserted, that would name one course, leave the operator to fix it, resubmit, and meet the
     * next one. One query, every conflict, one sentence.
     *
     * @param  list<Course>  $courses
     */
    private function assertNoLiveAdmissions(Student $student, array $courses): void
    {
        $ids = array_map(static fn (Course $course): int => (int) $course->getKey(), $courses);

        $live = StudentAdmission::query()
            ->live()
            ->where('student_id', $student->getKey())
            ->whereIn('course_id', $ids)
            ->get(['id', 'course_id', 'admission_number', 'stage']);

        if ($live->isEmpty()) {
            return;
        }

        $names = [];

        foreach ($courses as $course) {
            $clash = $live->firstWhere('course_id', (int) $course->getKey());

            if ($clash instanceof StudentAdmission) {
                $names[] = sprintf('%s (%s, %s)', $course->name, $clash->admission_number, $clash->stage->label());
            }
        }

        throw CourseRuleException::refuse('course_ids', sprintf(
            '%s already has a live admission to %s. Nothing was saved. Finish, cancel or withdraw %s '
            .'before starting another, or untick %s here.',
            $student->name,
            implode('; ', $names),
            count($names) === 1 ? 'it' : 'them',
            count($names) === 1 ? 'it' : 'them',
        ));
    }

    /**
     * Spread what the basket agreed once across the lines that have to store it.
     *
     * Two separate jobs, and both of them exist because the columns are per admission while the
     * conversation with the student was about a package.
     *
     * **The one-off heads.** `admission_fee` and `registration_fee` go on the first line and are
     * zeroed on the rest, unless `fees_once` is false. Left alone they would default from each course
     * and triple what a three-course student is quoted, with nothing on the screen saying so.
     *
     * **The discount and the scholarship.** Each is split by line gross with `Money::allocate()`, so
     * the shares sum back to exactly the figure that was typed. Two properties matter and neither is
     * obvious: largest-remainder placement means no paisa is invented or lost, and a split proportional
     * to gross cannot push a line past its own `chk_sadm_discount_ceiling`, because a share of a whole
     * that is within the total is within that line's part of it.
     *
     * The basket total is checked here, before the transaction, so the operator gets one sentence about
     * the package rather than `figuresFrom()`'s per-line refusal about a course they never typed into.
     *
     * @param  list<array{course: Course, overrides: array<string, mixed>}>  $lines
     * @param  array<string, mixed>  $shared
     * @return list<array{course: Course, overrides: array<string, mixed>}>
     */
    private function spreadBasketFigures(array $lines, array $shared): array
    {
        $once = ! array_key_exists('fees_once', $shared) || (bool) $shared['fees_once'];

        $grossByLine = [];

        foreach ($lines as $index => $line) {
            /** @var Course $course */
            $course = $line['course'];
            $overrides = $line['overrides'];

            $courseFee = Money::of((string) ($overrides['course_fee'] ?? $course->course_fee ?? '0.00'));

            if ($once && $index > 0) {
                $admissionFee = Money::zero();
                $registrationFee = Money::zero();
            } else {
                $admissionFee = Money::of((string) ($overrides['admission_fee'] ?? $course->admission_fee ?? '0.00'));
                $registrationFee = Money::of((string) ($overrides['registration_fee'] ?? $course->registration_fee ?? '0.00'));
            }

            $lines[$index]['overrides']['course_fee'] = $courseFee;
            $lines[$index]['overrides']['admission_fee'] = $admissionFee;
            $lines[$index]['overrides']['registration_fee'] = $registrationFee;

            $grossByLine[$index] = Money::sum($courseFee, $admissionFee, $registrationFee);
        }

        $basketGross = Money::sum(array_values($grossByLine));
        $discount = Money::of((string) ($shared['discount_amount'] ?? '0.00'));
        $scholarship = Money::of((string) ($shared['scholarship_amount'] ?? '0.00'));
        $reductions = Money::add($discount, $scholarship);

        if (Money::compare($reductions, $basketGross) > 0) {
            throw CourseRuleException::refuse('discount_amount', sprintf(
                'A discount of %s and a scholarship of %s come to more than the %s this basket of %d '
                .'course(s) charges. Nothing is sold below free.',
                money($discount),
                money($scholarship),
                money($basketGross),
                count($lines),
            ));
        }

        // Every weight zero means a basket of free courses. There is nothing to split and nothing to
        // split it by, so `Money::allocate()` would throw on the zero weights - and it is right to.
        if (Money::isZero($basketGross)) {
            foreach (array_keys($lines) as $index) {
                $lines[$index]['overrides']['discount_amount'] = Money::zero();
                $lines[$index]['overrides']['scholarship_amount'] = Money::zero();
            }

            return $lines;
        }

        $discountShares = Money::allocate($discount, $grossByLine);
        $scholarshipShares = Money::allocate($scholarship, $grossByLine);

        foreach (array_keys($lines) as $index) {
            $lines[$index]['overrides']['discount_amount'] = $discountShares[$index];
            $lines[$index]['overrides']['scholarship_amount'] = $scholarshipShares[$index];
        }

        return $lines;
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
