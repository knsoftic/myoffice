<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\DataObjects\Institute\FeeStructureData;
use App\Enums\FeeDiscountType;
use App\Enums\InstallmentStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Models\Institute\Course;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Models\User;
use App\Services\Finance\Exceptions\PaymentRuleException;
use App\Services\Institute\AdmissionService;
use App\Services\Institute\Exceptions\FeeRuleException;
use App\Support\Money;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * PH18-19 … PH18-30 — collection, the statuses and the nightly sweep (phase-18 §6.4, §6.6, §11.3).
 *
 * **`deriveStatus()` is the thing under test here, from every direction.** It is the single definition
 * of a fee's status (§6.4.1), and the reason it is single is that the spine's private second copy had
 * already drifted from it in three ways by the time this phase shipped. Each of those three has a test
 * of its own below.
 */
final class FeeCollectionTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsFees;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** PH18-19 — partial payments walk the four requirement statuses. */
    #[Test]
    public function partial_payments_walk_the_four_statuses(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', dueOn: Carbon::today()->addMonths(3), actor: $actor);
        $lines = $this->plan($charge, 3, actor: $actor);

        $this->assertSame(StudentFeeStatus::Pending, $charge->refresh()->status, 'Nothing received.');

        $this->receive($charge, '10000.00', $lines->first());

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Partial, $charge->status);
        $this->assertSame('20000.00', (string) $charge->balance_amount);
        $this->assertSame(InstallmentStatus::Paid, $lines->first()->refresh()->status);

        $this->receive($charge, '20000.00');

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Paid, $charge->status);
        $this->assertSame('0.00', (string) $charge->balance_amount);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * §6.4.1 row 6 — a part-paid charge past its due date reads `overdue`, not `partial`.
     *
     * One of the three rows the spine's private copy got wrong: it returned `partial` for any positive
     * receipt and never looked at the date, so a charge somebody had paid a tenth of and then ignored
     * for six months never appeared on an overdue report.
     */
    #[Test]
    public function a_part_paid_charge_past_its_due_date_reads_overdue(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', dueOn: Carbon::today()->subDays(30), actor: $actor);

        $this->receive($charge, '3000.00');

        $charge->refresh();

        $this->assertSame(
            StudentFeeStatus::Overdue,
            $this->fees()->deriveStatus($charge),
            'Part paid and long past due is overdue, not partial.',
        );
    }

    /**
     * PH18-20 — an overpayment is accepted and the gap stays visible.
     *
     * The money physically arrived, so refusing the receipt would be lying about the drawer. The
     * charge reads `overpaid` and the balance goes negative, which is the advance.
     */
    #[Test]
    public function an_overpayment_is_accepted_and_the_charge_reads_overpaid(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('25000.00', actor: $actor);

        $this->receive($charge, '35000.00');

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Overpaid, $charge->status);
        $this->assertSame('35000.00', (string) $charge->paid_amount, 'What actually arrived.');
        $this->assertSame('-10000.00', (string) $charge->balance_amount, 'A negative balance is an advance.');
        $this->assertTrue($charge->isOverpaid());
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-21 — a future-dated receipt is always refused.
     *
     * Money that has not arrived is not a receipt. This one is refused whoever asks, because a
     * receipt dated forward would sit in a window no commission rule covers yet and earn nothing
     * until the day came round — which looks exactly like a bug.
     */
    #[Test]
    public function a_future_dated_receipt_is_always_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $charge = $this->charge('10000.00', actor: $actor);

        $this->expectException(PaymentRuleException::class);

        try {
            $this->receive($charge, '5000.00', paidOn: Carbon::tomorrow());
        } finally {
            $this->assertDatabaseCount('student_fee_payments', 0);
        }
    }

    /**
     * PH18-22 — receipt numbers are unique and the counter advances by exactly what was issued.
     *
     * Gapless except for receipts that were committed and later voided, which keep their number for
     * ever: the number is on a piece of paper somebody is holding.
     */
    #[Test]
    public function receipt_numbers_are_unique_and_the_counter_advances_exactly(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', actor: $actor);

        $before = (int) setting('institute.fee_receipt_next_number', 1);

        // The amounts differ on purpose. Six identical receipts against one charge on one day by one
        // method are what the spine's duplicate fingerprint exists to question, and a test that
        // tripped it would be testing that guard rather than the numbering.
        $numbers = [];

        for ($i = 1; $i <= 6; $i++) {
            $numbers[] = (string) $this->receive($charge, sprintf('%d.00', 1000 + $i))->receipt_no;
        }

        $this->assertCount(6, array_unique($numbers), 'Six receipts, six distinct numbers.');

        app(SettingsRepository::class)->flush();

        $this->assertSame(
            $before + 6,
            (int) setting('institute.fee_receipt_next_number', 1),
            'The counter advanced by exactly what was issued.',
        );

        // A voided receipt keeps its number: it was printed.
        $voided = $charge->refresh()->payments()->first();
        $kept = (string) $voided->receipt_no;

        $this->payments()->void($voided, 'Mis-keyed');

        $this->assertSame($kept, (string) $voided->refresh()->receipt_no);
    }

    /**
     * PH18-24 — a double-clicked structure wizard produces one set of charges.
     *
     * The guard is the INSERT, not a SELECT (§6.1.3, F-3.15): the generator composes a
     * `generation_key` per head, writes, and treats a 1062 as "already generated".
     */
    #[Test]
    public function the_fee_structure_generator_is_idempotent_and_balanced(): void
    {
        $actor = $this->createSuperAdmin();
        $admission = $this->admissionWithFees($actor);

        $heads = [
            StudentFeeType::AdmissionFee->value => (string) $admission->admission_fee,
            StudentFeeType::RegistrationFee->value => (string) $admission->registration_fee,
            StudentFeeType::CourseFee->value => (string) $admission->course_fee,
        ];

        $first = $this->fees()->generateStructure($admission, new FeeStructureData(amounts: $heads), $actor);

        $this->assertTrue($first->created);
        $this->assertTrue($first->balances(), 'SUM(net) must equal the admission net payable, to the paisa.');
        $this->assertSame((string) $admission->net_payable, $first->proofTotal);

        $countAfterFirst = StudentFee::query()->where('student_admission_id', $admission->getKey())->count();

        $second = $this->fees()->generateStructure($admission->refresh(), new FeeStructureData(amounts: $heads), $actor);

        $this->assertFalse($second->created, 'A second run creates nothing.');
        $this->assertSame(
            $countAfterFirst,
            StudentFee::query()->where('student_admission_id', $admission->getKey())->count(),
            'And writes no extra rows.',
        );

        // The four admission caches are ours, and they follow.
        $this->assertSame(
            $first->proofTotal,
            (string) $admission->refresh()->charged_amount,
            'student_admissions.charged_amount is written by this service and nothing else.',
        );
    }

    /**
     * PH18-26 — the sweeper is idempotent and respects the grace period.
     */
    #[Test]
    public function the_overdue_sweeper_is_idempotent_and_respects_grace(): void
    {
        $actor = $this->createSuperAdmin();

        $late = $this->charge('5000.00', dueOn: Carbon::today()->subDays(10), actor: $actor);
        $justLate = $this->charge('5000.00', dueOn: Carbon::today()->subDays(2), actor: $actor);
        $future = $this->charge('5000.00', dueOn: Carbon::today()->addDays(10), actor: $actor);

        // With three days of grace, the two-day-old charge is not yet overdue.
        app(SettingsRepository::class)->set('institute.fee_overdue_grace_days', 3);

        $first = $this->fees()->markOverdue();

        $this->assertSame(StudentFeeStatus::Overdue, $late->refresh()->status);
        $this->assertSame(StudentFeeStatus::Pending, $justLate->refresh()->status, 'Inside the grace period.');
        $this->assertSame(StudentFeeStatus::Pending, $future->refresh()->status);
        $this->assertContains((int) $late->getKey(), $first->chargeIds);

        // A second run marks it again: no.
        $second = $this->fees()->markOverdue();

        $this->assertNotContains((int) $late->getKey(), $second->chargeIds, 'Idempotent.');
    }

    /** PH18-26 — the sweeper never touches a settled, cancelled or zero-balance charge. */
    #[Test]
    public function the_overdue_sweeper_leaves_settled_and_cancelled_charges_alone(): void
    {
        $actor = $this->createSuperAdmin();

        $paid = $this->charge('5000.00', dueOn: Carbon::today()->subDays(30), actor: $actor);
        $this->receive($paid, '5000.00');

        $cancelled = $this->charge('5000.00', dueOn: Carbon::today()->subDays(30), actor: $actor);
        $this->fees()->cancel($cancelled, 'Raised in error', $actor);

        $this->fees()->markOverdue();

        $this->assertSame(StudentFeeStatus::Paid, $paid->refresh()->status);
        $this->assertSame(StudentFeeStatus::Cancelled, $cancelled->refresh()->status);
    }

    /** PH18-27 — a charge covered entirely by a scholarship is paid and takes no receipt. */
    #[Test]
    public function a_zero_net_charge_is_paid_and_needs_no_receipt(): void
    {
        $actor = $this->createUserWithPermissions(['fee_discounts.approve', 'fee_discounts.create']);
        $charge = $this->charge('5000.00', actor: $actor);

        $this->discount($charge, '5000.00', FeeDiscountType::Scholarship, approver: $actor, actor: $actor);

        $charge->refresh();

        $this->assertSame('0.00', (string) $charge->net_amount);
        $this->assertSame(StudentFeeStatus::Paid, $charge->status);
        $this->assertSame('0.00', (string) $charge->balance_amount);
        $this->assertDatabaseCount('student_fee_payments', 0);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-30 — cancel, and why it is refused once money has arrived.
     */
    #[Test]
    public function a_charge_holding_a_receipt_cannot_be_cancelled(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('10000.00', actor: $actor);

        $this->receive($charge, '4000.00');

        try {
            $this->fees()->cancel($charge->refresh(), 'Changed their mind', $actor);
            $this->fail('A charge holding a receipt must not be cancellable.');
        } catch (FeeRuleException $e) {
            $this->assertStringContainsString(
                'Refund or void',
                implode(' ', array_merge(...array_values($e->errors()))),
                'The refusal says what to do instead.',
            );
        }

        $this->assertNotSame(StudentFeeStatus::Cancelled, $charge->refresh()->status);
    }

    /** PH18-30 — cancelling takes the unpaid lines with it, and reopening comes back correctly. */
    #[Test]
    public function cancelling_takes_the_unpaid_lines_and_reopening_lands_on_the_right_status(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('30000.00', dueOn: Carbon::today()->subDays(90), actor: $actor);

        // The plan's first line has to be in the past too. `recomputeCaches()` sets `due_date` from
        // the earliest unsettled line ([D18-3]), so a schedule starting next month would make this
        // charge stop being late the moment it got one -- which is correct, and not the scenario.
        $this->plan($charge, 3, firstDueOn: Carbon::today()->subDays(60), actor: $actor);

        $this->fees()->cancel($charge, 'Student never enrolled', $actor);

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Cancelled, $charge->status);
        $this->assertSame('Student never enrolled', $charge->cancellation_reason);
        $this->assertSame(
            3,
            $charge->installments()->where('status', InstallmentStatus::Cancelled->value)->count(),
            'The unpaid schedule goes with it.',
        );

        $reopened = $this->fees()->reopen($charge->refresh(), 'They did enrol after all', $actor);

        // Straight to overdue, because the due date passed while it was cancelled — this is the thing
        // that surprises people, so the service recomputes rather than parking it on `pending`.
        $this->assertSame(StudentFeeStatus::Overdue, $reopened->status);
        $this->assertNull($reopened->cancelled_at);
    }

    /** A cancellation without a reason is refused: the student is owed the sentence. */
    #[Test]
    public function cancelling_without_a_reason_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge('10000.00', actor: $actor);

        $this->expectException(FeeRuleException::class);

        try {
            $this->fees()->cancel($charge, '   ', $actor);
        } finally {
            $this->assertNotSame(StudentFeeStatus::Cancelled, $charge->refresh()->status);
        }
    }

    /**
     * An admission with real figures, booked through Phase 15's own service.
     */
    private function admissionWithFees(User $actor): StudentAdmission
    {
        // Through `CourseService`, like everything else: a hand-built row is missing `code` and a
        // dozen other things the application always sets, and the first thing it stops testing is
        // whatever the generator reads off the course.
        $course = $this->publishedCourse(null, [
            'course_fee' => '40000.00',
            'admission_fee' => '5000.00',
            'registration_fee' => '2500.00',
        ], $actor);

        return app(AdmissionService::class)
            ->create($this->feeStudent(), $course->refresh(), [], null, $actor);
    }
}
