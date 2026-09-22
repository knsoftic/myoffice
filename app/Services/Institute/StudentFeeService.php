<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\DataObjects\Institute\DiscountData;
use App\DataObjects\Institute\FeeStructureData;
use App\DataObjects\Institute\FeeStructureResult;
use App\DataObjects\Institute\FeeSummary;
use App\DataObjects\Institute\InstallmentLine;
use App\DataObjects\Institute\IssueFeeData;
use App\DataObjects\Institute\OverdueSweepResult;
use App\Enums\FeeDiscountType;
use App\Enums\InstallmentStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReversalType;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeDiscount;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeePayment;
use App\Models\User;
use App\Services\Finance\DocumentNumberService;
use App\Services\Finance\PaymentService;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Support\Format;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * What a student owes, and every act that changes it (phase-18 §6.1).
 *
 * Five things this class guarantees, each of which the rest of the phase relies on:
 *
 *  1. **`deriveStatus()` is the single definition of a fee's status** (§6.4.1). The payment path, the
 *     discount path and the nightly sweeper all call it, so they cannot drift apart. The spine's
 *     `PaymentService` used to carry a private second copy that already disagreed in three places —
 *     see the note on `deriveStatus()` itself.
 *  2. **The six cache columns always equal the sum of their rows** (§2.3), recomputed under the
 *     charge's own row lock, and `recomputeCaches()` is idempotent: running it twice changes nothing.
 *  3. **PI-1** (§6.3) — live lines minus waivers equal the net fee — is asserted *inside* the
 *     transaction of every act that touches a plan, a discount or a waiver. It throws; it does not warn.
 *  4. **Nothing here writes money.** No receipt, no reversal, no ledger row, no entitlement, no wallet
 *     (§6.9). Cash in and cash out go through the spine's `PaymentService`; commission happens in the
 *     spine's jobs. This class raises charges, reduces them, schedules them and recomputes caches.
 *  5. **`withinServiceContext()` is how the admission's four cache columns stay ours.** Phase 15's
 *     `StudentAdmission` refuses a write to `charged_amount` / `paid_amount` / `refunded_amount` /
 *     `balance_amount` from anywhere else, by asking this method. A screen that "just updates the
 *     balance" cannot exist — that is the whole point of a cache being re-derivable.
 */
final class StudentFeeService
{
    /**
     * Transaction depth, not a boolean: `addDiscount()` calls `redistributePlan()` calls
     * `recomputeCaches()`, and a flag that the innermost call cleared on its way out would drop the
     * guard while the outer transaction was still running.
     */
    private static int $depth = 0;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberService $numbers,
        private readonly InstallmentPlanCalculator $calculator,
    ) {}

    /**
     * True while this service is inside one of its own transactions ([D-IMP-2], §6.1).
     *
     * Static because `StudentAdmission`'s model hook asks the class, not an instance — it has no way
     * to reach the container's copy from inside a `saving` callback, and threading one through would
     * mean the guard could be bypassed by constructing a second service.
     */
    public static function withinServiceContext(): bool
    {
        return self::$depth > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Raising charges
    |--------------------------------------------------------------------------
    */

    /**
     * Raise one charge (§6.1 `issue()`).
     *
     * No installment, discount or payment side effect — deliberately. A generator that also built a
     * plan would make "raise a charge" and "raise a charge and schedule it" the same call, and the
     * second one is a decision somebody makes separately.
     */
    public function issue(IssueFeeData $data, ?User $actor = null): StudentFee
    {
        return $this->transaction(function () use ($data, $actor): StudentFee {
            $student = Student::query()->whereKey($data->studentId)->firstOrFail();
            $admission = $data->studentAdmissionId === null
                ? null
                : StudentAdmission::query()->whereKey($data->studentAdmissionId)->first();

            if ($admission !== null) {
                $this->assertAdmissionIsChargeable($admission);

                if ((int) $admission->student_id !== (int) $student->getKey()) {
                    throw FeeRuleException::refuse('student_admission_id',
                        'That admission belongs to a different student. A charge is raised against the '
                        .'admission the student actually holds.');
                }
            }

            $charge = $this->insertCharge($data, $student, $admission, $actor);

            // The admission's `figures_locked_at` is stamped by the first charge: from here on a
            // commission may have been computed from those numbers, so editing them would silently
            // change what a partner earned (Phase 15's own docblock says so).
            if ($admission !== null) {
                $this->lockAdmissionFigures($admission, $actor);
                $this->recomputeAdmission($admission->refresh());
            }

            activity('student_fees')
                ->performedOn($charge)
                ->causedBy($actor)
                ->withProperties([
                    'fee_number' => $charge->fee_number,
                    'fee_type' => $charge->fee_type->value,
                    'gross_amount' => (string) $charge->gross_amount,
                    'generation_key' => $charge->generation_key,
                ])
                ->log('student_fee.issued');

            return $charge;
        });
    }

    /**
     * The INSERT that is also the duplicate guard (§6.1.3, F-3.15).
     *
     * `fee_number` comes from Phase 5's numbering service inside this transaction, so a rollback
     * releases the number too. One retry on a 1062 against `uq_sf_number`: two callers can reserve the
     * same number only if the counter row was read outside a lock, which it is not — the retry is for
     * a hand-written number colliding, not for a race.
     */
    private function insertCharge(
        IssueFeeData $data,
        Student $student,
        ?StudentAdmission $admission,
        ?User $actor,
        bool $retrying = false,
    ): StudentFee {
        $charge = new StudentFee;

        $charge->forceFill([
            'fee_number' => $this->numbers->next(
                'institute.fee_record_prefix',
                'institute.fee_record_next_number',
                '%06d',
            ),
            'generation_key' => $data->generationKey,
            'branch_id' => $data->branchId ?? $student->branch_id,
            'student_id' => $student->getKey(),
            'student_admission_id' => $admission?->getKey(),
            'course_id' => $data->courseId ?? $admission?->course_id,
            'batch_id' => $data->batchId ?? $admission?->batch_id,
            'fee_type' => $data->feeType->value,
            'title' => $data->resolvedTitle(),
            'gross_amount' => $data->amount(),
            'discount_amount' => Money::ZERO,
            'scholarship_amount' => Money::ZERO,
            'net_amount' => $data->amount(),
            'paid_amount' => Money::ZERO,
            'refunded_amount' => Money::ZERO,
            'balance_amount' => $data->amount(),
            'has_installment_plan' => false,
            'installment_count' => 0,
            'due_date' => $this->resolveDueDate($data),
            'status' => StudentFeeStatus::Pending->value,
            // A display snapshot of who referred the student on the day the charge was raised. The
            // engine never reads it: it resolves the referral effective on the **payment** date,
            // because who referred somebody is a fact with a timeline (spine §2.2, D37).
            'collaborator_id' => $student->collaborator_id,
            'notes' => $data->notes,
            'created_by' => $actor?->getKey(),
            'updated_by' => $actor?->getKey(),
        ]);

        try {
            $charge->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! $retrying && $this->violates($e, 'uq_sf_number')) {
                return $this->insertCharge($data, $student, $admission, $actor, retrying: true);
            }

            throw $e;
        }

        return $charge->refresh();
    }

    /**
     * Turn one admission into its whole set of charges (§6.1 `generateStructure()`, requirement §69).
     *
     * **The INSERT is the duplicate check** (§6.1.3, F-3.15). Each head composes a `generation_key` of
     * `structure:{admission}:{head}`, writes it, and treats a 1062 on `uq_sf_generation` as "already
     * generated" — re-reading the charge that won. There is no SELECT-then-insert anywhere in this
     * path, because under concurrency an INSERT guard is a guarantee and a SELECT guard is a hope: two
     * admins on the wizard at once, a double-clicked button and a retried job all collapse onto one set.
     *
     * **`SUM(net) === admission.net_payable` is asserted, and the whole transaction aborts otherwise.**
     * That number is the spine's collectible denominator — a partner's commission is released in
     * proportion to it — so a structure that does not add up to the figure the student agreed is not a
     * rounding annoyance, it is a wrong commission waiting to be paid.
     */
    public function generateStructure(
        StudentAdmission $admission,
        FeeStructureData $data,
        ?User $actor = null,
    ): FeeStructureResult {
        return $this->transaction(function () use ($admission, $data, $actor): FeeStructureResult {
            $this->assertAdmissionIsChargeable($admission);

            $student = Student::query()->whereKey($admission->student_id)->firstOrFail();
            $heads = $data->heads();
            $charges = new Collection;
            $skipped = [];
            $newCount = 0;

            foreach ($heads as $head) {
                /** @var StudentFeeType $type */
                $type = $head['type'];
                $key = sprintf('structure:%d:%s', (int) $admission->getKey(), $type->value);

                $existing = StudentFee::query()->where('generation_key', $key)->first();

                if ($existing !== null) {
                    $skipped[] = $type->label();
                    $charges->push($existing);

                    continue;
                }

                try {
                    $charge = $this->insertCharge(
                        new IssueFeeData(
                            studentId: (int) $student->getKey(),
                            feeType: $type,
                            grossAmount: $head['amount'],
                            studentAdmissionId: (int) $admission->getKey(),
                            courseId: $admission->course_id,
                            batchId: $admission->batch_id,
                            branchId: $student->branch_id,
                            dueDate: $data->dueDateFor($type),
                            generationKey: $key,
                        ),
                        $student,
                        $admission,
                        $actor,
                    );
                } catch (UniqueConstraintViolationException $e) {
                    // Somebody else won the race. Their charge is the charge (F-3.15).
                    if (! $this->violates($e, 'uq_sf_generation')) {
                        throw $e;
                    }

                    $winner = StudentFee::query()->where('generation_key', $key)->first();

                    if ($winner === null) {
                        throw $e;
                    }

                    $skipped[] = $type->label();
                    $charges->push($winner);

                    continue;
                }

                $newCount++;
                $charges->push($charge);
            }

            if ($data->copyAdmissionDiscount) {
                $this->copyAdmissionReductions($admission, $charges, $data, $actor);
            }

            $charges = $charges->map(fn (StudentFee $c): StudentFee => $c->refresh());

            $proof = Money::sum($charges->map(static fn (StudentFee $c): string => (string) $c->net_amount)->all());
            $netPayable = Money::of((string) $admission->net_payable);

            // The abort. Everything above rolls back, so a structure that does not balance leaves the
            // admission exactly as it was rather than half charged.
            if (Money::compare($proof, $netPayable) !== 0) {
                throw FeeRuleException::structureDoesNotBalance($proof, $netPayable);
            }

            $this->lockAdmissionFigures($admission, $actor);
            $this->recomputeAdmission($admission->refresh());

            activity('student_fees')
                ->performedOn($admission)
                ->causedBy($actor)
                ->withProperties([
                    'admission_number' => $admission->admission_number,
                    'charges' => $charges->count(),
                    'new' => $newCount,
                    'skipped' => $skipped,
                    'proof_total' => $proof,
                    'net_payable' => $netPayable,
                ])
                ->log('fee_structure.generated');

            return new FeeStructureResult(
                charges: $charges,
                created: $newCount > 0,
                proofTotal: $proof,
                netPayable: $netPayable,
                skippedHeads: $skipped,
                newCount: $newCount,
            );
        });
    }

    /**
     * §6.1.2 — where the admission's agreed discount and scholarship land.
     *
     * Both go onto the **course fee** charge, because that is the head they were negotiated against.
     * If either is larger than that charge's gross it cascades in the fixed order
     * `registration_fee -> admission_fee`; if it still does not fit, the generator aborts rather than
     * writing a discount `chk_sf_discount_ceiling` would reject. One cascade order, so
     * `SUM(net) = net_payable` is reproducible run after run.
     *
     * @param  Collection<int, StudentFee>  $charges
     */
    private function copyAdmissionReductions(
        StudentAdmission $admission,
        Collection $charges,
        FeeStructureData $data,
        ?User $actor,
    ): void {
        $order = [StudentFeeType::CourseFee, StudentFeeType::RegistrationFee, StudentFeeType::AdmissionFee];

        $reductions = [
            [FeeDiscountType::FixedDiscount, Money::of((string) $admission->discount_amount)],
            [FeeDiscountType::Scholarship, Money::of((string) $admission->scholarship_amount)],
        ];

        foreach ($reductions as [$type, $total]) {
            if (Money::compare($total, Money::ZERO) !== 1) {
                continue;
            }

            $remaining = $total;

            foreach ($order as $head) {
                if (Money::compare($remaining, Money::ZERO) !== 1) {
                    break;
                }

                $charge = $charges->first(static fn (StudentFee $c): bool => $c->fee_type === $head);

                if ($charge === null) {
                    continue;
                }

                $charge = $charge->refresh();

                $room = Money::sub(
                    (string) $charge->gross_amount,
                    Money::add((string) $charge->discount_amount, (string) $charge->scholarship_amount),
                );

                if (Money::compare($room, Money::ZERO) !== 1) {
                    continue;
                }

                $take = Money::compare($remaining, $room) === 1 ? $room : $remaining;

                $this->addDiscount($charge, new DiscountData(
                    type: $type,
                    reason: sprintf('Copied from admission %s', (string) $admission->admission_number),
                    amount: $take,
                    approvedBy: $data->approvedBy ?? $admission->counselor_id,
                    effectiveOn: $admission->admission_date,
                ), $actor, alreadyApproved: true);

                $remaining = Money::sub($remaining, $take);
            }

            if (Money::compare($remaining, Money::ZERO) === 1) {
                throw FeeRuleException::refuse('heads', sprintf(
                    'The admission\'s %s of %s is larger than the charges it can be applied to — %s is '
                    .'left over. Raise the heads it was agreed against, or correct the admission figures.',
                    mb_strtolower($type->label()),
                    Money::format($total),
                    Money::format($remaining),
                ));
            }
        }
    }

    /**
     * One `monthly_fee` charge per (admission, calendar month) (§6.1 `generateMonthlyCharge()`).
     *
     * **Returns `null` rather than throwing when the month already has one.** This is called from a
     * scheduled job over every active admission, and a month already charged is the overwhelmingly
     * common case — an exception would make the normal path the error path and fill the log with it.
     *
     * The due day is clamped to the month end, so a `monthly_fee_due_day` of 31 lands on the 28th in
     * February rather than overflowing into March, which is the same trap `InstallmentInterval::Monthly`
     * exists to avoid.
     */
    public function generateMonthlyCharge(
        StudentAdmission $admission,
        CarbonInterface $month,
        string $amount,
        ?int $dueDay = null,
        ?User $actor = null,
    ): ?StudentFee {
        $period = CarbonImmutable::parse($month->toDateString())->startOfMonth();
        $key = sprintf('monthly:%d:%s', (int) $admission->getKey(), $period->format('Y-m'));

        if (Money::compare(Money::of($amount), Money::ZERO) !== 1) {
            throw FeeRuleException::zeroCharge();
        }

        $admissionDate = $admission->admission_date === null
            ? null
            : CarbonImmutable::parse($admission->admission_date->toDateString())->startOfMonth();

        if ($admissionDate !== null && $period->lessThan($admissionDate)) {
            throw FeeRuleException::refuse('month',
                'That month is before the student was admitted, so there is no monthly fee for it.');
        }

        if ($period->greaterThan(CarbonImmutable::parse(Carbon::now(Format::timezone())->toDateString())->addMonths(12))) {
            throw FeeRuleException::refuse('month',
                'Monthly fees are raised up to a year ahead. Charging further out would freeze a figure '
                .'nobody has agreed yet.');
        }

        return $this->transaction(function () use ($admission, $period, $key, $amount, $dueDay, $actor): ?StudentFee {
            $this->assertAdmissionIsChargeable($admission);

            $student = Student::query()->whereKey($admission->student_id)->firstOrFail();
            $day = $dueDay ?? (int) setting('institute.monthly_fee_due_day', 5);
            $due = $period->setDay(min(max($day, 1), $period->daysInMonth));

            try {
                $charge = $this->insertCharge(
                    new IssueFeeData(
                        studentId: (int) $student->getKey(),
                        feeType: StudentFeeType::MonthlyFee,
                        grossAmount: Money::of($amount),
                        studentAdmissionId: (int) $admission->getKey(),
                        courseId: $admission->course_id,
                        batchId: $admission->batch_id,
                        branchId: $student->branch_id,
                        title: sprintf('Monthly fee — %s', $period->format('F Y')),
                        dueDate: $due,
                        generationKey: $key,
                    ),
                    $student,
                    $admission,
                    $actor,
                );
            } catch (UniqueConstraintViolationException $e) {
                if (! $this->violates($e, 'uq_sf_generation')) {
                    throw $e;
                }

                return null;
            }

            $this->recomputeAdmission($admission->refresh());

            activity('student_fees')
                ->performedOn($charge)
                ->causedBy($actor)
                ->withProperties([
                    'fee_number' => $charge->fee_number,
                    'month' => $period->format('Y-m'),
                    'amount' => (string) $charge->gross_amount,
                ])
                ->log('student_fee.monthly_generated');

            return $charge;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Discounts (§6.5)
    |--------------------------------------------------------------------------
    */

    /**
     * Append one discount row and let the caches and the plan follow.
     *
     * The row is **appended**, never edited: that is what lets the engine answer "what was the net fee
     * on the day payment X arrived" without mutating anything. A wrong discount is undone by a
     * `reversal` row pointing at it, and `uq_sfd_reverses` makes a second undo impossible.
     */
    public function addDiscount(
        StudentFee $fee,
        DiscountData $data,
        ?User $actor = null,
        bool $alreadyApproved = false,
    ): StudentFeeDiscount {
        return $this->transaction(function () use ($fee, $data, $actor, $alreadyApproved): StudentFeeDiscount {
            $charge = $this->lock($fee);

            if ($charge->status === StudentFeeStatus::Cancelled) {
                throw FeeRuleException::chargeIsClosed($charge->status->label());
            }

            // `$alreadyApproved` is set only by `copyAdmissionReductions()`, and only there. The
            // discount it copies was agreed and approved when the admission's figures were, so
            // demanding a second approver would refuse to raise fees for any admission booked without
            // a counsellor — a default install, in other words. Every other caller goes through the
            // check, and the screens have no way to pass this flag.
            if (! $alreadyApproved) {
                $this->assertApprover($data, $actor);
            }
            $effectiveOn = $this->assertEffectiveDate($data);

            $gross = (string) $charge->gross_amount;
            $signed = $data->signedAgainst($gross);

            $this->assertCeiling($charge, $data->type, $signed);

            $discount = $this->insertDiscount($charge, $data, $signed, $effectiveOn, $actor);

            // The plan has to move with the net fee, or PI-1 breaks the moment somebody looks (§6.3.4).
            $this->redistributePlan($charge, $signed, $data->reason, $actor);
            $this->recomputeCaches($charge->refresh());

            activity('fee_discounts')
                ->performedOn($discount)
                ->causedBy($actor)
                ->withProperties([
                    'fee_number' => $charge->fee_number,
                    'type' => $data->type->value,
                    'amount' => $signed,
                    'percentage' => $data->percentage,
                    'reason' => $data->reason,
                    'approved_by' => $data->approvedBy,
                ])
                ->log('fee_discount.added');

            return $discount;
        });
    }

    /**
     * Undo a discount by writing its mirror (§6.1 `reverseDiscount()`).
     *
     * The original row is never touched. `uq_sfd_reverses` is a unique index on `reverses_discount_id`,
     * so a second reversal of the same row is a 1062 rather than a second refund of the same money.
     */
    public function reverseDiscount(StudentFeeDiscount $discount, string $reason, ?User $actor = null): StudentFeeDiscount
    {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('reversing a discount');
        }

        return $this->transaction(function () use ($discount, $reason, $actor): StudentFeeDiscount {
            $charge = $this->lock($discount->fee);
            $signed = Money::negate((string) $discount->amount);

            $reversal = new StudentFeeDiscount;

            $reversal->forceFill([
                'student_fee_id' => $charge->getKey(),
                'type' => FeeDiscountType::Reversal->value,
                'amount' => $signed,
                'percentage' => null,
                'reason' => mb_substr($reason, 0, 255),
                'approved_by' => $actor?->getKey(),
                'approved_by_name' => $actor?->name,
                'approved_at' => Carbon::now(),
                'effective_on' => Carbon::now(Format::timezone())->toDateString(),
                'reverses_discount_id' => $discount->getKey(),
                'idempotency_key' => (string) Str::ulid(),
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ]);

            StudentFeeDiscount::allowDirectWrites(static fn () => $reversal->save());

            $this->redistributePlan($charge, $signed, $reason, $actor);
            $this->recomputeCaches($charge->refresh());

            activity('fee_discounts')
                ->performedOn($reversal)
                ->causedBy($actor)
                ->withProperties([
                    'reverses_discount_id' => $discount->getKey(),
                    'amount' => $signed,
                    'reason' => $reason,
                ])
                ->log('fee_discount.reversed');

            return $reversal->refresh();
        });
    }

    /**
     * Waive part or all of one installment line (§6.1, §6.3.4).
     *
     * **A waiver needs no redistribution, and that is not an oversight.** It reduces `net_amount` by
     * exactly the amount it parks in the line's `waived_amount`, so both sides of PI-1 move together.
     * A discount that is not tied to a line moves only the right-hand side, which is precisely why
     * `addDiscount()` must redistribute and this must not.
     */
    public function waiveInstallment(
        StudentFeeInstallment $line,
        string $amount,
        string $reason,
        ?User $actor = null,
    ): StudentFeeDiscount {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('waiving an installment');
        }

        return $this->transaction(function () use ($line, $amount, $reason, $actor): StudentFeeDiscount {
            $charge = $this->lock($line->fee);

            /** @var StudentFeeInstallment $row */
            $row = StudentFeeInstallment::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            $asked = Money::of($amount);
            $remaining = Money::sub(
                Money::sub((string) $row->amount, (string) $row->paid_amount),
                (string) $row->waived_amount,
            );

            if (Money::compare($asked, Money::ZERO) !== 1) {
                throw FeeRuleException::refuse('amount', 'A waiver of zero releases nothing.');
            }

            if (Money::compare($asked, $remaining) === 1) {
                throw FeeRuleException::waiverExceedsLine($asked, $remaining, (int) $row->installment_no);
            }

            $discount = $this->insertDiscount(
                $charge,
                new DiscountData(
                    type: FeeDiscountType::Waiver,
                    reason: $reason,
                    amount: $asked,
                    approvedBy: $actor?->getKey(),
                ),
                Money::negate($asked),
                CarbonImmutable::parse(Carbon::now(Format::timezone())->toDateString()),
                $actor,
            );

            $waived = Money::add((string) $row->waived_amount, $asked);

            $row->forceFill([
                'waived_amount' => $waived,
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->recomputeInstallment($row->refresh());
            $this->recomputeCaches($charge->refresh());

            activity('installments')
                ->performedOn($row)
                ->causedBy($actor)
                ->withProperties([
                    'installment_no' => $row->installment_no,
                    'waived' => $asked,
                    'reason' => $reason,
                ])
                ->log('installment.waived');

            return $discount;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Installment plans (§6.2, §6.3)
    |--------------------------------------------------------------------------
    */

    /**
     * Build the first plan on a charge.
     *
     * **[D18-4]: refused outright once any money has been received.** The alternative would either
     * break the spine's "line sum = net" rule or write `student_fee_installment_id` onto an existing
     * payment row, which INV-8 forbids. R-2 records the trade; the screen states it.
     *
     * @param  list<InstallmentLine>  $lines
     * @return Collection<int, StudentFeeInstallment>
     */
    public function buildInstallmentPlan(StudentFee $fee, array $lines, ?User $actor = null): Collection
    {
        return $this->transaction(function () use ($fee, $lines, $actor): Collection {
            $charge = $this->lock($fee);
            $received = $charge->netReceived();

            if (Money::compare($received, Money::ZERO) !== 0) {
                throw FeeRuleException::planAfterMoney($received);
            }

            if ($charge->installments()->whereNot('status', InstallmentStatus::Cancelled->value)->exists()) {
                throw FeeRuleException::refuse('installments',
                    'This charge already has a plan. Rebuild it instead — a rebuild keeps the lines that '
                    .'hold money and their numbers.');
            }

            $written = $this->writeLines($charge, $lines, $actor);

            $this->assertPlanIntegrity($charge->refresh());
            $this->recomputeCaches($charge->refresh());

            activity('installments')
                ->performedOn($charge)
                ->causedBy($actor)
                ->withProperties([
                    'fee_number' => $charge->fee_number,
                    'lines' => $written->count(),
                    'total' => Money::sum($written->map(static fn (StudentFeeInstallment $l): string => (string) $l->amount)->all()),
                ])
                ->log('installment_plan.built');

            return $written;
        });
    }

    /**
     * Replace the unpaid part of a plan (§6.1 `rebuildInstallmentPlan()`).
     *
     * **Numbers are never reused and never renumbered** ([D18-6], D50): the new lines continue from
     * `MAX(installment_no) + 1`, so a plan that has been rebuilt reads 1, 2, 5, 6. That looks like a
     * bug and is the opposite — reusing 3 would make "the third installment" ambiguous in exactly the
     * conversation where it matters, and would collide with `uq_sfi_no` besides.
     *
     * @param  list<InstallmentLine>  $lines
     * @return Collection<int, StudentFeeInstallment>
     */
    public function rebuildInstallmentPlan(StudentFee $fee, array $lines, string $reason, ?User $actor = null): Collection
    {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('rebuilding an installment plan');
        }

        return $this->transaction(function () use ($fee, $lines, $reason, $actor): Collection {
            $charge = $this->lock($fee);

            $kept = $charge->installments()
                ->whereNot('status', InstallmentStatus::Cancelled->value)
                ->lockForUpdate()
                ->get()
                ->filter(static fn (StudentFeeInstallment $line): bool => $line->holdsMoney()
                    || $line->status === InstallmentStatus::Waived);

            // Everything unpaid goes; everything holding money stays exactly where it is.
            $charge->installments()
                ->whereNot('status', InstallmentStatus::Cancelled->value)
                ->whereNotIn('id', $kept->modelKeys())
                ->get()
                ->each(function (StudentFeeInstallment $line) use ($reason, $actor): void {
                    $line->forceFill([
                        'status' => InstallmentStatus::Cancelled->value,
                        'notes' => mb_substr($reason, 0, 500),
                        'updated_by' => $actor?->getKey(),
                    ])->save();
                });

            $written = $this->writeLines($charge, $lines, $actor);

            $this->assertPlanIntegrity($charge->refresh());
            $this->recomputeCaches($charge->refresh());

            activity('installments')
                ->performedOn($charge)
                ->causedBy($actor)
                ->withProperties([
                    'fee_number' => $charge->fee_number,
                    'kept' => $kept->count(),
                    'new' => $written->count(),
                    'reason' => $reason,
                ])
                ->log('installment_plan.rebuilt');

            return $written;
        });
    }

    /**
     * Move a signed change in net across the live unpaid lines (§6.2, §6.3.3).
     *
     * Internal: only `addDiscount()`, `reverseDiscount()` and `waiveInstallment()` reach it. A fifth
     * entry point that skipped it is exactly the risk R-1 names, which is why PI-1 throws rather than
     * warns at the end of every one of them.
     *
     * @return Collection<int, StudentFeeInstallment>
     */
    private function redistributePlan(StudentFee $charge, string $signedDelta, string $reason, ?User $actor): Collection
    {
        $live = $charge->installments()
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Overdue->value])
            ->lockForUpdate()
            ->get()
            ->filter(static fn (StudentFeeInstallment $line): bool => ! $line->holdsMoney());

        if ($live->isEmpty()) {
            return $live;
        }

        $plan = $this->calculator->redistribute(
            $live->map(static fn (StudentFeeInstallment $line): InstallmentLine => new InstallmentLine(
                number: (int) $line->installment_no,
                amount: (string) $line->amount,
                dueDate: CarbonImmutable::parse($line->due_date->toDateString()),
                id: (int) $line->getKey(),
            ))->values()->all(),
            $signedDelta,
        );

        foreach ($plan->amounts as $id => $amount) {
            StudentFeeInstallment::query()->whereKey($id)->update([
                'amount' => $amount,
                'updated_by' => $actor?->getKey(),
            ]);
        }

        foreach ($plan->cancel as $id) {
            StudentFeeInstallment::query()->whereKey($id)->update([
                'status' => InstallmentStatus::Cancelled->value,
                'notes' => mb_substr($reason, 0, 500),
                'updated_by' => $actor?->getKey(),
            ]);
        }

        if ($plan->newLineAmount !== null) {
            $this->writeLines($charge, [new InstallmentLine(
                number: $this->nextLineNumber($charge),
                amount: $plan->newLineAmount,
                dueDate: CarbonImmutable::parse(
                    ($charge->due_date ?? Carbon::now(Format::timezone()))->toDateString(),
                ),
            )], $actor);
        }

        return $charge->installments()->get();
    }

    /**
     * @param  list<InstallmentLine>  $lines
     * @return Collection<int, StudentFeeInstallment>
     */
    private function writeLines(StudentFee $charge, array $lines, ?User $actor): Collection
    {
        $written = new Collection;

        foreach ($lines as $line) {
            $row = new StudentFeeInstallment;

            $row->forceFill([
                'student_fee_id' => $charge->getKey(),
                'installment_no' => $line->number,
                'amount' => Money::of($line->amount),
                'due_date' => $line->dueDate->toDateString(),
                'paid_amount' => Money::ZERO,
                'waived_amount' => Money::ZERO,
                'status' => InstallmentStatus::Pending->value,
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $written->push($row->refresh());
        }

        return $written;
    }

    private function nextLineNumber(StudentFee $charge): int
    {
        return (int) $charge->installments()->withTrashed()->max('installment_no') + 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Caches and status (§2.3, §6.4)
    |--------------------------------------------------------------------------
    */

    /**
     * Recompute the six cache columns, the status and the due date, under the charge's row lock.
     *
     * Idempotent by construction: every value is a function of rows in other tables, so running it
     * twice produces the same answer. That is what makes `fees:verify-plan-integrity` able to **report**
     * drift rather than having to repair it — a cache that can only be fixed by the thing that broke it
     * is not a cache.
     */
    public function recomputeCaches(StudentFee $fee, ?User $actor = null): void
    {
        $this->transaction(function () use ($fee, $actor): void {
            $charge = $this->lock($fee);

            $discounts = $charge->discounts()->get();

            // The two buckets are signed sums of the same column, split by `isScholarship()`, and
            // stored as magnitudes — `chk_sf_nonneg` wants them non-negative, and a screen reading
            // "discount -5,000.00" beside "net 25,000.00" invites the wrong mental arithmetic.
            //
            // `bucket()` negates rather than taking the absolute value, and the difference matters.
            // Reductions are negative and a `correction` is positive, so a bucket whose signed sum
            // came out **positive** would mean corrections had outrun the discounts they undo — net
            // above gross, which `assertCeiling()` refuses at the door and `chk_sf_discount_ceiling`
            // refuses at the database. `abs()` would quietly turn that impossible state into a
            // plausible-looking discount; negating turns it into a negative number the CHECK rejects,
            // which is the correct behaviour for a state that should not exist.
            $bucket = static fn (bool $scholarship): string => Money::negate(Money::sum($discounts
                ->filter(static fn (StudentFeeDiscount $d): bool => $d->type->isScholarship() === $scholarship)
                ->map(static fn (StudentFeeDiscount $d): string => (string) $d->amount)
                ->all()));

            $discount = $bucket(false);
            $scholarship = $bucket(true);

            $net = Money::sub(Money::sub((string) $charge->gross_amount, $discount), $scholarship);

            // §2.3: both legs come from the payment rows, excluding voided receipts. A void means
            // "that receipt never counted", so it leaves both sides rather than appearing as a refund.
            $live = $charge->payments()
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->get(['amount', 'refunded_amount']);

            $paid = Money::sum($live->map(static fn (StudentFeePayment $p): string => (string) $p->amount)->all());
            $refunded = Money::sum($live->map(static fn (StudentFeePayment $p): string => (string) $p->refunded_amount)->all());
            $balance = Money::sub($net, Money::sub($paid, $refunded));

            $liveLines = $charge->installments()
                ->whereNot('status', InstallmentStatus::Cancelled->value)
                ->get();

            $charge->forceFill([
                'discount_amount' => $discount,
                'scholarship_amount' => $scholarship,
                'net_amount' => $net,
                'paid_amount' => $paid,
                'refunded_amount' => $refunded,
                'balance_amount' => $balance,
                'has_installment_plan' => $liveLines->isNotEmpty(),
                'installment_count' => $liveLines->count(),
                'due_date' => $this->resolvePlanDueDate($charge, $liveLines),
                'updated_by' => $actor?->getKey() ?? $charge->updated_by,
            ])->save();

            $charge->forceFill(['status' => $this->deriveStatus($charge->refresh())->value])->save();

            $admission = $charge->student_admission_id === null
                ? null
                : StudentAdmission::query()->whereKey($charge->student_admission_id)->first();

            if ($admission !== null) {
                $this->recomputeAdmission($admission);
            }
        });
    }

    /**
     * Recompute one line's cache and status (§6.4.2).
     */
    public function recomputeInstallment(StudentFeeInstallment $line, ?User $actor = null): void
    {
        $this->transaction(function () use ($line, $actor): void {
            /** @var StudentFeeInstallment $row */
            $row = StudentFeeInstallment::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            // `net_received_amount` is a generated column (`amount - refunded_amount`), so a refund can
            // never be forgotten here the way it could if this summed `amount` and subtracted refunds
            // in a second query.
            $receipts = $row->payments()
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->orderBy('paid_on')
                ->get(['paid_on', 'net_received_amount']);

            $paid = Money::sum($receipts->map(static fn (StudentFeePayment $p): string => (string) $p->net_received_amount)->all());
            $settled = Money::add($paid, (string) $row->waived_amount);

            $row->forceFill([
                'paid_amount' => $paid,
                'paid_on' => Money::compare($settled, (string) $row->amount) >= 0
                    ? $receipts->last()?->paid_on
                    : null,
                'status' => $this->deriveInstallmentStatus($row, $settled)->value,
                'updated_by' => $actor?->getKey() ?? $row->updated_by,
            ])->save();
        });
    }

    /**
     * **The single definition of a fee's status** (§6.4.1), evaluated in order, first match wins.
     *
     * The spine's `PaymentService` carried a private second copy of this, and the two had already
     * drifted in three ways by the time this phase was written: a fully-scholarshiped charge
     * (`net 0.00`, nothing received) never reached `paid`; a part-paid charge past its due date always
     * read `partial` rather than `overdue`; and `refunded_amount` was summed from `payment_reversals`
     * including reversals of **voided** receipts, which double-counts money that never counted. That is
     * the argument for one function rather than for a better second one.
     *
     * Pure: it writes nothing and takes the charge's own caches as given, so the sweeper, the payment
     * path and the discount path all reach the same answer from the same numbers.
     */
    public function deriveStatus(StudentFee $charge, ?CarbonInterface $asOf = null): StudentFeeStatus
    {
        if ($charge->cancelled_at !== null) {
            return StudentFeeStatus::Cancelled;
        }

        $net = (string) $charge->net_amount;
        $received = Money::sub((string) $charge->paid_amount, (string) $charge->refunded_amount);
        $isLate = $this->isPastDue($charge, $asOf);

        return match (true) {
            // A charge covered entirely by a scholarship is paid, not pending: there is nothing to
            // collect, and leaving it `pending` would put it on the collection desk for ever.
            Money::isZero($net) && Money::isZero($received) => StudentFeeStatus::Paid,
            Money::compare($received, $net) === 1 => StudentFeeStatus::Overpaid,
            Money::compare($received, $net) === 0 && Money::isPositive($net) => StudentFeeStatus::Paid,
            // Reachable only with a real refund — a voided receipt leaves both legs, so a void returns
            // the charge to pending/overdue instead, which is the honest answer.
            Money::isZero($received) && Money::isPositive((string) $charge->refunded_amount) => StudentFeeStatus::Refunded,
            Money::isPositive($received) => $isLate ? StudentFeeStatus::Overdue : StudentFeeStatus::Partial,
            default => $isLate ? StudentFeeStatus::Overdue : StudentFeeStatus::Pending,
        };
    }

    private function deriveInstallmentStatus(StudentFeeInstallment $line, string $settled): InstallmentStatus
    {
        if ($line->status === InstallmentStatus::Cancelled) {
            return InstallmentStatus::Cancelled;
        }

        $amount = (string) $line->amount;
        $isLate = $this->lineIsPastDue($line);

        return match (true) {
            Money::compare((string) $line->waived_amount, $amount) >= 0 => InstallmentStatus::Waived,
            Money::compare($settled, $amount) >= 0 => InstallmentStatus::Paid,
            Money::isPositive($settled) => $isLate ? InstallmentStatus::Overdue : InstallmentStatus::Partial,
            default => $isLate ? InstallmentStatus::Overdue : InstallmentStatus::Pending,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Published for other phases (F-4.6)
    |--------------------------------------------------------------------------
    */

    /**
     * A subject's whole fee position, from the cache columns only (§6.1 `summaryFor()`).
     *
     * Read-only and it writes nothing — which is why it may be called from a screen, a listener or a
     * report without wondering whether it will start a transaction.
     */
    public function summaryFor(Student|StudentAdmission $subject): FeeSummary
    {
        $charges = $this->liveChargesFor($subject)->get();

        if ($charges->isEmpty()) {
            return FeeSummary::empty();
        }

        $sum = static fn (string $column): string => Money::sum(
            $charges->map(static fn (StudentFee $c): string => (string) $c->{$column})->all(),
        );

        $nextDue = $charges
            ->filter(static fn (StudentFee $c): bool => $c->due_date !== null && $c->status->isOpen())
            ->sortBy('due_date')
            ->first()?->due_date;

        $balance = $sum('balance_amount');

        return new FeeSummary(
            gross: $sum('gross_amount'),
            discount: $sum('discount_amount'),
            scholarship: $sum('scholarship_amount'),
            net: $sum('net_amount'),
            paid: $sum('paid_amount'),
            refunded: $sum('refunded_amount'),
            balance: $balance,
            status: $this->rollUpStatus($charges, $balance),
            nextDueDate: $nextDue === null ? null : CarbonImmutable::parse($nextDue->toDateString()),
            chargeCount: $charges->count(),
        );
    }

    /**
     * The one outstanding figure an enrolment, exam-eligibility or certificate screen may ask for.
     *
     * Returned **signed**, so an advance is visible rather than silently floored — the caller decides
     * whether to show a negative number or the words "in advance".
     */
    public function outstandingFor(StudentAdmission|Student $subject): string
    {
        return Money::sum(
            $this->liveChargesFor($subject)->pluck('balance_amount')->map(static fn ($v): string => (string) $v)->all(),
        );
    }

    /**
     * Repoint a batch on a subject's charges (§6.1 `reassignBatch()`).
     *
     * **It writes no money.** Not an amount, not a discount, not a receipt, not an installment line,
     * and it never re-runs the commission engine: `student_fees.batch_id` is a display and filter
     * column, and a batch change is not an economic event (F-4.6). Audited with the calling screen's
     * mandatory reason, because a charge that moved between batches is a question somebody will ask.
     */
    public function reassignBatch(Student $student, int $fromBatchId, int $toBatchId, string $reason, ?User $actor = null): int
    {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('moving charges to another batch');
        }

        return $this->transaction(function () use ($student, $fromBatchId, $toBatchId, $reason, $actor): int {
            $charges = StudentFee::query()
                ->where('student_id', $student->getKey())
                ->where('batch_id', $fromBatchId)
                ->get();

            foreach ($charges as $charge) {
                $charge->forceFill(['batch_id' => $toBatchId, 'updated_by' => $actor?->getKey()])->save();

                activity('student_fees')
                    ->performedOn($charge)
                    ->causedBy($actor)
                    ->withProperties([
                        'from_batch_id' => $fromBatchId,
                        'to_batch_id' => $toBatchId,
                        'reason' => $reason,
                        'moved_money' => false,
                    ])
                    ->log('student_fee.batch_reassigned');
            }

            return $charges->count();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Status moves
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel a charge (§6.1 `cancel()`).
     *
     * Refused while any non-voided receipt exists: the correct act there is a refund, which leaves a
     * row. Discounts are untouched — they are append-only and a cancelled charge's history is still
     * the history of what was agreed.
     */
    public function cancel(StudentFee $fee, string $reason, ?User $actor = null): StudentFee
    {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('cancelling a charge');
        }

        return $this->transaction(function () use ($fee, $reason, $actor): StudentFee {
            $charge = $this->lock($fee);
            $received = Money::sum($charge->payments()
                ->whereNot('status', ReceivedPaymentStatus::Voided->value)
                ->pluck('amount')->map(static fn ($v): string => (string) $v)->all());

            if (Money::isPositive($received)) {
                throw FeeRuleException::chargeHoldsMoney('cancelled', $received);
            }

            $charge->forceFill([
                'status' => StudentFeeStatus::Cancelled->value,
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $actor?->getKey(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
                'updated_by' => $actor?->getKey(),
            ])->save();

            $charge->installments()
                ->whereIn('status', [
                    InstallmentStatus::Pending->value,
                    InstallmentStatus::Overdue->value,
                    InstallmentStatus::Partial->value,
                ])
                ->update([
                    'status' => InstallmentStatus::Cancelled->value,
                    'notes' => mb_substr($reason, 0, 500),
                    'updated_by' => $actor?->getKey(),
                ]);

            activity('student_fees')
                ->performedOn($charge)
                ->causedBy($actor)
                ->withProperties(['fee_number' => $charge->fee_number, 'reason' => $reason])
                ->log('student_fee.cancelled');

            return $charge->refresh();
        });
    }

    /**
     * Put a cancelled charge back in play (§6.1 `reopen()`).
     *
     * The caches are recomputed on the way out, so a charge whose due date passed while it was
     * cancelled lands straight on `overdue` rather than sitting on `pending` until the next sweep.
     */
    public function reopen(StudentFee $fee, string $reason, ?User $actor = null): StudentFee
    {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('reopening a charge');
        }

        return $this->transaction(function () use ($fee, $reason, $actor): StudentFee {
            $charge = $this->lock($fee);

            if ($charge->status !== StudentFeeStatus::Cancelled) {
                throw FeeRuleException::refuse('status', 'Only a cancelled charge can be reopened.');
            }

            $charge->forceFill([
                'status' => StudentFeeStatus::Pending->value,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->recomputeCaches($charge->refresh(), $actor);

            activity('student_fees')
                ->performedOn($charge)
                ->causedBy($actor)
                ->withProperties(['fee_number' => $charge->fee_number, 'reason' => $reason])
                ->log('student_fee.reopened');

            return $charge->refresh();
        });
    }

    /**
     * Carry a receipt from one charge to another (§6.1 `transferPayment()`, spine §6.6 row 7).
     *
     * Used when a student changes course: the money physically arrived and stays arrived, but it is
     * owed against a different charge now. The act is a `cancellation` reversal on the source plus a
     * new receipt on the target **carrying the original `paid_on`** — and that date is the whole point.
     * The commission engine resolves the referral and the rule effective on the value date, so a
     * carried receipt earns exactly what the original earned and the reversal takes exactly that back:
     * the net commission effect is about zero rather than a windfall or a clawback.
     *
     * **Both legs go through the spine's `PaymentService`.** This class writes no receipt and no
     * reversal of its own (§6.9) — it decides that the transfer should happen and hands the money
     * operations to the service that owns them.
     *
     * Refused across students. Moving one student's money onto another student's charge is not a
     * transfer, it is two separate acts — a refund and a payment — and conflating them would leave one
     * student's receipt sitting in another student's history.
     */
    public function transferPayment(
        StudentFeePayment $payment,
        StudentFee $target,
        string $reason,
        ?User $actor = null,
    ): StudentFeePayment {
        if (trim($reason) === '') {
            throw FeeRuleException::reasonRequiredFor('transferring a receipt to another charge');
        }

        if ((int) $payment->student_id !== (int) $target->student_id) {
            throw FeeRuleException::refuse('student_fee_id',
                'A receipt can only be carried between charges belonging to the same student. Moving it '
                .'to somebody else is a refund and a new payment, and each of those leaves its own row.');
        }

        if ((int) $payment->student_fee_id === (int) $target->getKey()) {
            throw FeeRuleException::refuse('student_fee_id', 'That receipt is already on this charge.');
        }

        if ($payment->status === ReceivedPaymentStatus::Voided) {
            throw FeeRuleException::refuse('status',
                'A voided receipt is money that never counted, so there is nothing to carry.');
        }

        if ($target->status === StudentFeeStatus::Cancelled) {
            throw FeeRuleException::chargeIsClosed($target->status->label());
        }

        $source = $payment->fee;
        $carried = (string) $payment->net_received_amount;

        if (Money::compare($carried, Money::ZERO) !== 1) {
            throw FeeRuleException::refuse('amount',
                'This receipt has already been refunded in full, so there is nothing left to carry.');
        }

        return $this->transaction(function () use ($payment, $target, $source, $carried, $reason, $actor): StudentFeePayment {
            // Resolved here rather than injected: `PaymentService` reaches back into this class for its
            // cache recompute, and two constructors that need each other cannot both be built. The
            // container resolves this one at the moment it is used, which breaks the cycle without
            // either service having to pretend it does not depend on the other.
            $payments = app(PaymentService::class);

            $payments->refund($payment, new RefundData(
                amount: $carried,
                reason: $reason,
                type: ReversalType::Cancellation,
            ));

            $result = $payments->recordStudentFeePayment($target, new RecordPaymentData(
                amount: $carried,
                method: $payment->payment_method,
                // The original value date, deliberately. Re-dating it to today would move the receipt
                // into a different rule window and a different referral, and the two legs would no
                // longer cancel out.
                paidOn: $payment->paid_on,
                referenceNo: $payment->reference_no,
                notes: sprintf('Carried from %s — %s', (string) $source?->fee_number, $reason),
                receivedBy: $actor?->getKey(),
                receivedByName: $actor?->name,
                // A carried receipt is the same student, the same amount and the same value date as
                // the one just reversed, so it matches its own duplicate fingerprint by construction.
                // The confirmation is the caller saying "yes, deliberately" rather than a user being
                // asked about a coincidence that is not one.
                confirmDuplicate: true,
            ));

            if ($source !== null) {
                $this->recomputeCaches($source->refresh(), $actor);
            }

            $this->recomputeCaches($target->refresh(), $actor);

            activity('student_fee_payments')
                ->performedOn($result->payment)
                ->causedBy($actor)
                ->withProperties([
                    'carried_from' => $source?->fee_number,
                    'carried_to' => $target->fee_number,
                    'original_receipt_no' => $payment->receipt_no,
                    'amount' => $carried,
                    'paid_on' => $payment->paid_on?->toDateString(),
                    'reason' => $reason,
                ])
                ->log('student_fee_payment.transferred');

            return $result->payment;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The nightly sweep (§6.6)
    |--------------------------------------------------------------------------
    */

    /**
     * Mark what is late, once (§6.6).
     *
     * Idempotent: a row already `overdue` is not rewritten and produces no event, so a second run of
     * the same night is a no-op that still reports what it looked at. Both queries are driven by
     * `(due_date, status)` and bounded by `$limit`, because this grows with the student body (R-9).
     */
    public function markOverdue(?CarbonInterface $asOf = null, int $limit = 1000): OverdueSweepResult
    {
        $today = Carbon::parse(($asOf ?? Carbon::now(Format::timezone()))->toDateString());
        $grace = max(0, (int) setting('institute.fee_overdue_grace_days', 0));
        $cutoff = $today->copy()->subDays($grace);

        $lines = StudentFeeInstallment::query()
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Partial->value])
            ->whereDate('due_date', '<', $cutoff->toDateString())
            ->whereRaw('(`amount` - `paid_amount` - `waived_amount`) > 0')
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        foreach ($lines as $line) {
            $line->forceFill(['status' => InstallmentStatus::Overdue->value])->save();
        }

        $charges = StudentFee::query()
            ->whereIn('status', [StudentFeeStatus::Pending->value, StudentFeeStatus::Partial->value])
            ->whereDate('due_date', '<', $cutoff->toDateString())
            ->where('balance_amount', '>', 0)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        foreach ($charges as $charge) {
            // Through `deriveStatus()`, not by hand. The query above has already narrowed this to
            // charges that are open, past due and still owed something — so the answer will be
            // `overdue` — but writing the value directly here is exactly how the spine's private copy
            // came to disagree with this one in the first place. One definition means every caller
            // asks it, including the caller that is sure of the answer.
            $charge->forceFill(['status' => $this->deriveStatus($charge, $today)->value])->save();
        }

        $alreadyOverdue = StudentFee::query()
            ->where('status', StudentFeeStatus::Overdue->value)
            ->whereDate('due_date', '<', $cutoff->toDateString())
            ->count();

        return new OverdueSweepResult(
            chargeIds: $charges->modelKeys(),
            installmentIds: $lines->modelKeys(),
            alreadyOverdue: max(0, $alreadyOverdue - $charges->count()),
            reachedLimit: $charges->count() === $limit || $lines->count() === $limit,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PI-1 (§6.3)
    |--------------------------------------------------------------------------
    */

    /**
     * **PI-1**: `SUM(live line amounts) - SUM(waived) == net_amount`.
     *
     * Asserted inside the transaction of every act that touches a plan, a discount or a waiver, and it
     * **throws** — a warning here would be a plan that quietly cannot be paid off, discovered by a
     * student who pays every installment and is still told they owe money.
     */
    public function assertPlanIntegrity(StudentFee $charge): void
    {
        $lines = $charge->installments()
            ->whereNot('status', InstallmentStatus::Cancelled->value)
            ->get(['amount', 'waived_amount']);

        if ($lines->isEmpty()) {
            return;
        }

        $scheduled = Money::sub(
            Money::sum($lines->map(static fn (StudentFeeInstallment $l): string => (string) $l->amount)->all()),
            Money::sum($lines->map(static fn (StudentFeeInstallment $l): string => (string) $l->waived_amount)->all()),
        );

        if (Money::compare($scheduled, (string) $charge->net_amount) !== 0) {
            throw new LogicException(sprintf(
                'PI-1 broken on %s: live lines less waivers come to %s against a net fee of %s. Nothing '
                .'has been written.',
                (string) $charge->fee_number,
                $scheduled,
                (string) $charge->net_amount,
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Every public entry point runs through here, so `withinServiceContext()` is true for the whole of
     * it — including the nested calls `addDiscount()` makes into `redistributePlan()` and
     * `recomputeCaches()`.
     */
    private function transaction(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $this->db->transaction($callback, 3);
        } finally {
            self::$depth--;
        }
    }

    private function lock(StudentFee $fee): StudentFee
    {
        /** @var StudentFee $charge */
        $charge = StudentFee::query()->whereKey($fee->getKey())->lockForUpdate()->firstOrFail();

        return $charge;
    }

    private function insertDiscount(
        StudentFee $charge,
        DiscountData $data,
        string $signed,
        CarbonInterface $effectiveOn,
        ?User $actor,
    ): StudentFeeDiscount {
        $approver = $data->approvedBy === null ? null : User::query()->whereKey($data->approvedBy)->first();

        $discount = new StudentFeeDiscount;

        $discount->forceFill([
            'student_fee_id' => $charge->getKey(),
            'type' => $data->type->value,
            'amount' => $signed,
            // Both the percentage and the computed amount, so the arithmetic is never re-done against
            // a gross that has since changed (§6.5).
            'percentage' => $data->percentage,
            'reason' => mb_substr($data->reason, 0, 255),
            'approved_by' => $approver?->getKey(),
            // Beside the foreign key on purpose: the approver's user row may be deleted years later,
            // and "approved by" with nothing after it is the sentence a dispute turns on.
            'approved_by_name' => $approver?->name,
            'approved_at' => $approver === null ? null : Carbon::now(),
            'effective_on' => $effectiveOn->toDateString(),
            'reverses_discount_id' => $data->reversesDiscountId,
            'idempotency_key' => $data->key(),
            'created_by' => $actor?->getKey(),
            'updated_by' => $actor?->getKey(),
        ]);

        StudentFeeDiscount::allowDirectWrites(static fn () => $discount->save());

        return $discount->refresh();
    }

    /**
     * §6.5's ceiling, checked before the write rather than left to `chk_sf_discount_ceiling`.
     *
     * The CHECK is the backstop and it will hold — but it raises a constraint name, and the person
     * reading it is trying to work out which of two numbers to change.
     */
    private function assertCeiling(StudentFee $charge, FeeDiscountType $type, string $signed): void
    {
        $gross = (string) $charge->gross_amount;

        $existing = $charge->discounts()->get();

        // The same negate-don't-abs convention as `recomputeCaches()`, for the same reason.
        $bucket = static fn (bool $scholarship): string => Money::negate(Money::sum($existing
            ->filter(static fn (StudentFeeDiscount $d): bool => $d->type->isScholarship() === $scholarship)
            ->map(static fn (StudentFeeDiscount $d): string => (string) $d->amount)
            ->all()));

        $discount = $bucket(false);
        $scholarship = $bucket(true);

        $type->isScholarship()
            ? $scholarship = Money::sub($scholarship, $signed)
            : $discount = Money::sub($discount, $signed);

        // A correction can only ever undo an over-discount: its bucket may not go below zero, which is
        // what stops one pushing net above gross (§6.5).
        if (Money::isNegative($discount) || Money::isNegative($scholarship)) {
            throw FeeRuleException::refuse('amount',
                'That correction is larger than the discounts it would undo. A correction can only give '
                .'back what was taken off.');
        }

        if (Money::compare(Money::add($discount, $scholarship), $gross) === 1) {
            throw FeeRuleException::discountExceedsFee($discount, $scholarship, $gross);
        }
    }

    /**
     * [D18-8]: the approver holds the ability, and is not the creator unless they hold it themselves.
     */
    private function assertApprover(DiscountData $data, ?User $actor): void
    {
        if (! (bool) setting('institute.discount_approval_required', true)) {
            return;
        }

        if ($data->approvedBy === null) {
            throw FeeRuleException::refuse('approved_by',
                'This institute requires a discount to be approved. Name who approved it.');
        }

        $approver = User::query()->whereKey($data->approvedBy)->first();

        if ($approver === null || ! $approver->can('fee_discounts.approve')) {
            throw FeeRuleException::approverMustHoldTheAbility();
        }

        if ($actor !== null
            && (int) $approver->getKey() === (int) $actor->getKey()
            && ! $actor->can('fee_discounts.approve')) {
            throw FeeRuleException::approverMayNotBeTheCreator();
        }
    }

    private function assertEffectiveDate(DiscountData $data): CarbonImmutable
    {
        $today = Carbon::now(Format::timezone())->startOfDay();
        $on = $data->effectiveOn === null
            ? CarbonImmutable::parse($today->toDateString())
            : CarbonImmutable::parse($data->effectiveOn->toDateString());

        if ($on->greaterThan(CarbonImmutable::parse($today->toDateString()))) {
            throw FeeRuleException::effectiveDateInTheFuture();
        }

        return $on;
    }

    private function assertAdmissionIsChargeable(StudentAdmission $admission): void
    {
        // Phase 15 calls it `stage`, not `status`, and terminal stages leave `active_guard` NULL.
        if ($admission->cancelled_at !== null || $admission->withdrawn_at !== null) {
            throw FeeRuleException::admissionIsNotChargeable(
                $admission->cancelled_at !== null ? 'cancelled' : 'withdrawn',
            );
        }
    }

    private function lockAdmissionFigures(StudentAdmission $admission, ?User $actor): void
    {
        if ($admission->figures_locked_at !== null) {
            return;
        }

        $admission->forceFill([
            'figures_locked_at' => Carbon::now(),
            'updated_by' => $actor?->getKey() ?? $admission->updated_by,
        ])->save();
    }

    /**
     * The four admission caches Phase 15 reserved for this service (`FEE_CACHE_COLUMNS`).
     */
    private function recomputeAdmission(StudentAdmission $admission): void
    {
        $charges = StudentFee::query()
            ->where('student_admission_id', $admission->getKey())
            ->whereNot('status', StudentFeeStatus::Cancelled->value)
            ->get(['net_amount', 'paid_amount', 'refunded_amount', 'balance_amount']);

        $sum = static fn (string $column): string => Money::sum(
            $charges->map(static fn (StudentFee $c): string => (string) $c->{$column})->all(),
        );

        $admission->forceFill([
            'charged_amount' => $sum('net_amount'),
            'paid_amount' => $sum('paid_amount'),
            'refunded_amount' => $sum('refunded_amount'),
            'balance_amount' => $sum('balance_amount'),
        ])->save();
    }

    /**
     * [D18-3]: one column drives the sweeper, the widgets and the student panel.
     *
     * With a live plan it is the earliest unsettled line; when every line is settled it is the last
     * line's, so a fully paid charge does not advertise a due date in the past. With no plan it is
     * whatever was agreed at issue.
     *
     * @param  Collection<int, StudentFeeInstallment>  $liveLines
     */
    private function resolvePlanDueDate(StudentFee $charge, Collection $liveLines): ?string
    {
        if ($liveLines->isEmpty()) {
            return $charge->due_date?->toDateString();
        }

        $unsettled = $liveLines
            ->filter(static fn (StudentFeeInstallment $l): bool => $l->status->isOpen())
            ->sortBy('due_date');

        $line = $unsettled->first() ?? $liveLines->sortBy('due_date')->last();

        return $line?->due_date?->toDateString();
    }

    private function resolveDueDate(IssueFeeData $data): string
    {
        if ($data->dueDate !== null) {
            return $data->dueDate->toDateString();
        }

        $days = max(0, (int) setting('institute.fee_due_days', 7));

        return Carbon::now(Format::timezone())->addDays($days)->toDateString();
    }

    private function isPastDue(StudentFee $charge, ?CarbonInterface $asOf): bool
    {
        if ($charge->due_date === null) {
            return false;
        }

        $grace = max(0, (int) setting('institute.fee_overdue_grace_days', 0));
        $today = Carbon::parse(($asOf ?? Carbon::now(Format::timezone()))->toDateString());

        return $charge->due_date->lessThan($today->copy()->subDays($grace));
    }

    private function lineIsPastDue(StudentFeeInstallment $line): bool
    {
        if ($line->due_date === null) {
            return false;
        }

        $grace = max(0, (int) setting('institute.fee_overdue_grace_days', 0));

        return $line->due_date->lessThan(
            Carbon::now(Format::timezone())->startOfDay()->subDays($grace),
        );
    }

    /**
     * @return Builder<StudentFee>
     */
    private function liveChargesFor(Student|StudentAdmission $subject): Builder
    {
        $query = StudentFee::query()->whereNot('status', StudentFeeStatus::Cancelled->value);

        return $subject instanceof StudentAdmission
            ? $query->where('student_admission_id', $subject->getKey())
            : $query->where('student_id', $subject->getKey());
    }

    /**
     * One status for a set of charges: the worst open one wins, because a summary card saying "paid"
     * beside an outstanding balance is the kind of thing nobody reports until it has cost money.
     *
     * @param  Collection<int, StudentFee>  $charges
     */
    private function rollUpStatus(Collection $charges, string $balance): StudentFeeStatus
    {
        $has = static fn (StudentFeeStatus $status): bool => $charges->contains(
            static fn (StudentFee $c): bool => $c->status === $status,
        );

        return match (true) {
            $has(StudentFeeStatus::Overdue) => StudentFeeStatus::Overdue,
            $has(StudentFeeStatus::Partial) => StudentFeeStatus::Partial,
            $has(StudentFeeStatus::Pending) => StudentFeeStatus::Pending,
            Money::isNegative($balance) => StudentFeeStatus::Overpaid,
            $has(StudentFeeStatus::Refunded) && Money::isZero($balance) => StudentFeeStatus::Refunded,
            default => StudentFeeStatus::Paid,
        };
    }

    private function violates(Throwable $e, string $index): bool
    {
        return str_contains($e->getMessage(), $index);
    }
}
