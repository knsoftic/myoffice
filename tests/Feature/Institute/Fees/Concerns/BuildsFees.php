<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees\Concerns;

use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Institute\DiscountData;
use App\DataObjects\Institute\IssueFeeData;
use App\Enums\FeeDiscountType;
use App\Enums\InstallmentInterval;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\RemainderPlacement;
use App\Enums\StudentFeeType;
use App\Models\Institute\Student;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeePayment;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Institute\InstallmentPlanCalculator;
use App\Services\Institute\StudentFeeService;
use App\Services\Institute\StudentService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fixtures for the Phase 18 suite (phase-18 §11).
 *
 * **Everything goes through the service that owns it.** A fixture that inserted a charge or a discount
 * directly would be testing a shape the application never produces — and Phase 18 already learned what
 * that costs: the spine's own `charge()` fixture wrote `discount_amount` with no discount row behind
 * it, which survived only because the old `PaymentService::recomputeCharge()` left those columns
 * alone. The moment one definition of the caches existed, the fixture's impossible state moved a
 * commission by 500.00.
 *
 * Money in always goes through `PaymentService`: this phase writes no receipt of its own (§6.9), and
 * neither does its test suite.
 */
trait BuildsFees
{
    private int $feeSequence = 0;

    protected function fees(): StudentFeeService
    {
        return app(StudentFeeService::class);
    }

    protected function calculator(): InstallmentPlanCalculator
    {
        return app(InstallmentPlanCalculator::class);
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    /**
     * A real student row, through the service, so it has a real code and a real branch.
     */
    protected function feeStudent(array $attributes = []): Student
    {
        $this->feeSequence++;

        return app(StudentService::class)->create(array_merge([
            'name' => 'Fee Student '.$this->feeSequence,
            'phone' => '0344'.str_pad((string) $this->feeSequence, 7, '0', STR_PAD_LEFT),
        ], $attributes));
    }

    /**
     * One charge, raised the way the application raises one.
     */
    protected function charge(
        string $gross = '30000.00',
        ?Student $student = null,
        StudentFeeType $type = StudentFeeType::CourseFee,
        ?Carbon $dueOn = null,
        ?User $actor = null,
    ): StudentFee {
        return $this->fees()->issue(new IssueFeeData(
            studentId: (int) ($student ?? $this->feeStudent())->getKey(),
            feeType: $type,
            grossAmount: $gross,
            dueDate: $dueOn,
        ), $actor);
    }

    /**
     * A plan built through the calculator and the service — never hand-written lines, because the
     * arithmetic is the thing under test everywhere else.
     *
     * @return Collection<int, StudentFeeInstallment>
     */
    protected function plan(
        StudentFee $charge,
        int $count = 3,
        ?Carbon $firstDueOn = null,
        InstallmentInterval $interval = InstallmentInterval::Monthly,
        RemainderPlacement $placement = RemainderPlacement::Last,
        ?User $actor = null,
    ): Collection {
        return $this->fees()->buildInstallmentPlan(
            $charge->refresh(),
            $this->calculator()->generate(
                netAmount: (string) $charge->net_amount,
                count: $count,
                firstDueOn: $firstDueOn ?? Carbon::today()->addMonth(),
                interval: $interval,
                placement: $placement,
            ),
            $actor,
        );
    }

    /**
     * A discount, with an approver who actually holds the ability — because [D18-8] refuses one who
     * does not, and a fixture that quietly bypassed that would stop the rule being tested at all.
     */
    protected function discount(
        StudentFee $charge,
        string $amount = '5000.00',
        FeeDiscountType $type = FeeDiscountType::FixedDiscount,
        ?User $approver = null,
        ?User $actor = null,
    ): void {
        $approver ??= $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);

        $this->fees()->addDiscount($charge->refresh(), new DiscountData(
            type: $type,
            reason: 'Fixture discount',
            amount: $amount,
            approvedBy: (int) $approver->getKey(),
        ), $actor ?? $approver);
    }

    /**
     * Money in, through the spine.
     */
    protected function receive(
        StudentFee $charge,
        string $amount,
        ?StudentFeeInstallment $line = null,
        ?Carbon $paidOn = null,
    ): StudentFeePayment {
        return $this->payments()->recordStudentFeePayment($charge->refresh(), new RecordPaymentData(
            amount: $amount,
            method: PaymentMethod::Cash,
            paidOn: $paidOn ?? Carbon::today(),
            installmentId: $line?->getKey(),
        ))->payment;
    }

    /**
     * PI-1 as an assertion: live lines minus waivers equal the net fee.
     *
     * Every test that touches a plan, a discount or a waiver ends with this — the same discipline the
     * financial suite applies with `assertWalletMatchesLedger()`, and for the same reason: an invariant
     * checked in one test is an invariant that breaks in the others.
     */
    protected function assertPlanIntegrity(StudentFee $charge): void
    {
        $charge = $charge->refresh();

        $live = $charge->installments()
            ->whereNot('status', InstallmentStatus::Cancelled->value)
            ->get();

        // [D18-10]: an overpaid charge has no schedule left to be in balance with. The service and
        // the nightly verifier skip the same case; see StudentFeeService::assertPlanIntegrity().
        if ($live->isEmpty() || Money::isNegative((string) $charge->balance_amount)) {
            return;
        }

        $scheduled = Money::sub(
            Money::sum($live->map(static fn (StudentFeeInstallment $l): string => (string) $l->amount)->all()),
            Money::sum($live->map(static fn (StudentFeeInstallment $l): string => (string) $l->waived_amount)->all()),
        );

        $this->assertSame(
            (string) $charge->net_amount,
            $scheduled,
            sprintf('PI-1 on %s: live lines less waivers must equal the net fee.', (string) $charge->fee_number),
        );
    }

    /**
     * The six cache columns of §2.3, re-derived from the rows and compared.
     *
     * This is what `fees:verify-plan-integrity` does nightly, run inline — because a cache that only
     * the nightly job checks is a cache that can be wrong all day.
     */
    protected function assertCachesMatchRows(StudentFee $charge): void
    {
        $charge = $charge->refresh();

        $discounts = $charge->discounts()->get();

        $bucket = static fn (bool $scholarship): string => Money::negate(Money::sum($discounts
            ->filter(static fn ($d): bool => $d->type->isScholarship() === $scholarship)
            ->map(static fn ($d): string => (string) $d->amount)
            ->all()));

        $live = $charge->payments()
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->get(['amount', 'refunded_amount']);

        $paid = Money::sum($live->map(static fn ($p): string => (string) $p->amount)->all());
        $refunded = Money::sum($live->map(static fn ($p): string => (string) $p->refunded_amount)->all());
        $net = Money::sub(Money::sub((string) $charge->gross_amount, $bucket(false)), $bucket(true));

        $this->assertSame($bucket(false), (string) $charge->discount_amount, 'discount_amount is the sum of its rows.');
        $this->assertSame($bucket(true), (string) $charge->scholarship_amount, 'scholarship_amount is the sum of its rows.');
        $this->assertSame($net, (string) $charge->net_amount, 'net_amount is gross less both buckets.');
        $this->assertSame($paid, (string) $charge->paid_amount, 'paid_amount is the sum of its non-voided receipts.');
        $this->assertSame($refunded, (string) $charge->refunded_amount, 'refunded_amount comes from the payment rows.');
        $this->assertSame(
            Money::sub($net, Money::sub($paid, $refunded)),
            (string) $charge->balance_amount,
            'balance_amount is net less what the business actually holds.',
        );
    }

    /**
     * @return list<string>
     */
    protected function amountsOf(StudentFee $charge): array
    {
        return $charge->refresh()->installments()
            ->whereNot('status', InstallmentStatus::Cancelled->value)
            ->orderBy('installment_no')
            ->get()
            ->map(static fn (StudentFeeInstallment $l): string => (string) $l->amount)
            ->all();
    }
}
