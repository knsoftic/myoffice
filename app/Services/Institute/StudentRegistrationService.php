<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Finance\RecordPaymentData;
use App\Enums\PaymentMethod;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The two-step registration: a student and their login, then their courses, their bill and their
 * first payment.
 *
 * **It writes nothing itself.** Every rule stays where it already lives — the duplicate guard in
 * `AdmissionService::assertNoLiveAdmissions()`, the discount ceiling in `chk_sadm_discount_ceiling`,
 * the balance proof in `StudentFeeService::generateStructure()`, the capacity lock in enrolment, the
 * idempotency guard in `PaymentService`. This class decides only the ORDER and holds the transaction,
 * which is the one thing none of them can do for each other.
 *
 * **Why it is a separate service and not a method on `AdmissionService`.** That class deliberately
 * resolves the fee and enrolment services through `app()` + `class_exists()` so it does not own them;
 * injecting four services into it would undo that on purpose. An orchestrator is its own thing.
 *
 * **What the two steps commit.** Step 1 commits on its own: the student and the login exist before
 * the operator picks a course, so a browser closed between the steps leaves a real student rather
 * than nothing. Step 2 is all-or-nothing: admissions, charges and the receipt go in one transaction,
 * because a basket half-billed is worse than a basket not billed — the operator cannot tell which
 * half without reading the database.
 *
 * **The money split is the delicate part**, and it is delicate in two places:
 *
 *   - the bill's tax is computed once on the basket and allocated to the lines, never computed per
 *     line, because a sum of rounded figures is not the rounding of the sum and
 *     `generateStructure()` aborts on the difference (`AdmissionService::spreadBasketFigures()`);
 *   - the payment is allocated across the admissions in proportion to what each owes, and then
 *     waterfalls across that admission's own charges. One typed figure, several receipts, and every
 *     course's balance true — which is what makes a later refund, a later commission and a later
 *     "how much does this student still owe for Web Development" all answer correctly.
 */
final class StudentRegistrationService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentService $students,
        private readonly AdmissionService $admissions,
        private readonly StudentFeeService $fees,
        private readonly PaymentService $payments,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Step 1 — the student, and the login in the same act
    |--------------------------------------------------------------------------
    */

    /**
     * Create the student and, when a password was typed, their portal login.
     *
     * One transaction, because a student who exists without the login the operator was told they
     * were creating is the kind of half-state somebody discovers a week later.
     *
     * @param  array<string, mixed>  $data  the §66 student columns, as the form posts them
     */
    public function createStudentWithLogin(
        array $data,
        #[\SensitiveParameter] ?string $password = null,
        ?User $actor = null,
    ): Student {
        return $this->db->transaction(function () use ($data, $password, $actor): Student {
            $student = $this->students->create($data, $actor);

            if ($password !== null && trim($password) !== '') {
                $this->students->createLogin($student, $actor, $password);
            }

            return $student->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2 — the courses, the bill and the money
    |--------------------------------------------------------------------------
    */

    /**
     * Enrol the student on every ticked course, raise the bill, and take what they are paying now.
     *
     * @param  list<array<string, mixed>>  $lines  one per course, as `AdmissionService::createMany()` takes them
     * @param  array<string, mixed>  $shared  discount, scholarship, extra fee, dates — the basket's own figures
     * @param  array<string, mixed>  $payment  `amount`, `payment_method`, `paid_on`, `reference_no`, `idempotency_key`
     * @return array{admissions: Collection<int, StudentAdmission>, charges: Collection<int, StudentFee>, receipts: int, paid: string, balance: string}
     */
    public function enrolAndBill(
        Student $student,
        array $lines,
        array $shared = [],
        array $payment = [],
        ?User $actor = null,
    ): array {
        if ($student->user_id === null && (string) $student->email === '') {
            throw CourseRuleException::refuse('student_id',
                'This student has neither a login nor an e-mail address, so nothing could be sent to '
                .'them and nothing can be billed to them. Finish step one first.');
        }

        return $this->db->transaction(function () use ($student, $lines, $shared, $payment, $actor): array {
            $admissions = $this->admissions->createMany($student, $lines, $shared, $actor);

            $charges = new Collection;

            foreach ($admissions as $admission) {
                /*
                | Both relations loaded before the pipeline reads them.
                |
                | `register()` reads `$admission->student` and `requestFees()` reads
                | `$admission->course`, and these admissions were just built -- nothing has loaded
                | either. `Model::shouldBeStrict()` is on outside production, so a lazy read here is an
                | exception, and it is the same mistake `assignBatch()` shipped with. Two queries per
                | course, for a basket of one to three.
                */
                $admission->loadMissing(['student', 'course']);

                // Registration first: the number is issued once per STUDENT, and `register()` already
                // knows that, so the second and third admissions move their stage and issue nothing.
                // `register()` returns the same instance through `refresh()`, which reloads the
                // relations it already has -- so `course` survives into `requestFees()` below.
                $admission = $this->admissions->register($admission, $actor);

                // Straight to the fee service rather than through `requestFees()`: that method also
                // validates an installment request the registration screen does not collect, and the
                // stage move it makes is the one `generateStructure()`'s caller wants anyway.
                $this->admissions->requestFees($admission, [], $actor);

                $charges = $charges->concat(
                    StudentFee::query()
                        ->where('student_admission_id', $admission->getKey())
                        ->orderBy('id')
                        ->get(),
                );
            }

            $taken = $this->takePayment($admissions, $payment, $actor);

            $admissions = $admissions->map(static fn (StudentAdmission $a): StudentAdmission => $a->refresh());

            $billed = Money::sum($admissions->map(static fn ($a): string => (string) $a->net_payable)->all());

            return [
                'admissions' => $admissions,
                'charges' => $charges,
                'receipts' => $taken['receipts'],
                'paid' => $taken['paid'],
                'balance' => Money::sub($billed, $taken['paid']),
            ];
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Spread one typed figure across the basket, and record a receipt for every charge it touches.
     *
     * **Proportional across admissions, waterfall within one.** A student paying 10,000 against three
     * courses has paid something towards all three, not all of it towards the first — so each course's
     * balance, each course's commission and each course's later refund answer correctly. Inside a
     * single admission the share simply fills the charges in the order they were raised, because the
     * heads of one admission are one bill and their order is not a business fact.
     *
     * `Money::allocate()` places the remainder, so the receipts add to exactly what was typed. Nothing
     * is invented and nothing evaporates.
     *
     * @param  Collection<int, StudentAdmission>  $admissions
     * @param  array<string, mixed>  $payment
     * @return array{receipts: int, paid: string}
     */
    private function takePayment(Collection $admissions, array $payment, ?User $actor): array
    {
        $amount = Money::of((string) ($payment['amount'] ?? '0.00'));

        if (! Money::isPositive($amount)) {
            return ['receipts' => 0, 'paid' => Money::zero()];
        }

        $owedByAdmission = [];

        foreach ($admissions as $admission) {
            $owedByAdmission[(int) $admission->getKey()] = Money::max(
                (string) $admission->refresh()->net_payable,
                Money::zero(),
            );
        }

        $totalOwed = Money::sum(array_values($owedByAdmission));

        if (Money::isZero($totalOwed)) {
            throw CourseRuleException::refuse('payment_amount',
                'This basket charges nothing, so there is nothing to pay against. Remove the amount or '
                .'check the fees.');
        }

        if (Money::compare($amount, $totalOwed) > 0) {
            throw CourseRuleException::refuse('payment_amount', sprintf(
                'The bill is %s and %s was entered. Take the bill amount or less — an advance has to be '
                .'recorded against a charge that exists, from the fee screen.',
                money($totalOwed),
                money($amount),
            ));
        }

        $shares = Money::allocate($amount, $owedByAdmission);

        $method = $payment['payment_method'] ?? PaymentMethod::Cash->value;
        $paidOn = trim((string) ($payment['paid_on'] ?? ''));
        $paidOn = $paidOn === '' ? Carbon::now() : Carbon::parse($paidOn);
        $reference = $payment['reference_no'] ?? null;

        // One key per receipt, derived from the one the form minted, so a double-submitted
        // registration replays into the same receipts instead of taking the money again.
        $root = trim((string) ($payment['idempotency_key'] ?? '')) ?: (string) Str::ulid();

        $receipts = 0;

        foreach ($admissions as $admission) {
            $remaining = $shares[(int) $admission->getKey()] ?? Money::zero();

            if (! Money::isPositive($remaining)) {
                continue;
            }

            $lines = StudentFee::query()
                ->where('student_admission_id', $admission->getKey())
                ->orderBy('id')
                ->get();

            foreach ($lines as $charge) {
                if (! Money::isPositive($remaining)) {
                    break;
                }

                $balance = Money::max((string) $charge->balance_amount, Money::zero());

                if (! Money::isPositive($balance)) {
                    continue;
                }

                $slice = Money::min($remaining, $balance);

                $this->payments->recordStudentFeePayment($charge, new RecordPaymentData(
                    amount: $slice,
                    method: $method instanceof PaymentMethod ? $method : PaymentMethod::from((string) $method),
                    paidOn: $paidOn,
                    referenceNo: $reference,
                    idempotencyKey: substr($root.'-'.$charge->getKey(), 0, 64),
                ));

                $remaining = Money::sub($remaining, $slice);
                $receipts++;
            }
        }

        return ['receipts' => $receipts, 'paid' => $amount];
    }
}
