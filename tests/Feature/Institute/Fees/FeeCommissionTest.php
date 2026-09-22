<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CommissionProcessingState;
use App\Enums\CommissionSkipReason;
use App\Enums\FeeDiscountType;
use App\Enums\PaymentMethod;
use App\Enums\ReferralSource;
use App\Enums\ReferralSubject;
use App\Enums\ReversalType;
use App\Enums\StudentFeeStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Institute\Student;
use App\Services\Collaborator\ReferralService;
use App\Services\Collaborator\StudentCommissionService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * PH18-01 … PH18-05 — requirement §120's named tests, from the fee side (phase-18 §6.10, §11.1).
 *
 * The spine has its own service-level copies of these in `tests/Feature/Financial`, and both must
 * pass. **These are not duplicates** — they exercise the same guarantees through the charge that
 * Phase 18 raises and the plan Phase 18 builds, which is where the wiring between the two phases can
 * actually break. The spine's copies prove the engine; these prove the engine is reached.
 *
 * Every money test ends with the spine's own `assertWalletMatchesLedger()`, which runs the nightly
 * reconciler's eight checks. An invariant asserted in one test is an invariant that breaks in the
 * others.
 */
final class FeeCommissionTest extends TestCase
{
    // Both traits define `charge()` and `receive()`, and the collision is worth resolving explicitly
    // rather than renaming either: the spine's versions build a charge the SPINE's way (a row written
    // straight, with the escape hatch), and this phase's build one the way the application does. The
    // test needs `partner()` and `assertWalletMatchesLedger()` from the spine and everything else from
    // here, so it says so.
    use BuildsFees {
        BuildsFees::charge insteadof BuildsFinancialFixtures;
        BuildsFees::receive insteadof BuildsFinancialFixtures;
    }
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /**
     * PH18-01 (§120.1) — a student with no collaborator produces **no row at all**.
     *
     * Not a zero row. A zero-amount ledger entry is a claim that somebody earned nothing, which is a
     * different statement from "there was nobody to earn anything" and would pollute every aggregate
     * that counts entries rather than summing them (INV-2).
     */
    #[Test]
    public function a_student_without_a_collaborator_creates_no_commission_row(): void
    {
        $charge = $this->charge('10000.00');

        $payment = $this->receive($charge, '10000.00');

        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);
        $this->assertDatabaseCount('collaborator_commission_entitlements', 0);

        $payment->refresh();

        $this->assertSame(CommissionProcessingState::Skipped, $payment->commission_state);
        $this->assertSame(CommissionSkipReason::NoReferral, $payment->commission_skip_reason);

        // The charge is still perfectly normal: no commission is not an error condition.
        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Paid, $charge->status);
        $this->assertSame('0.00', (string) $charge->balance_amount);
        $this->assertCachesMatchRows($charge);
    }

    /**
     * PH18-02 (§120.2) — one receipt, one entry, at the configured rate.
     */
    #[Test]
    public function a_fee_payment_creates_one_commission_at_the_configured_rate(): void
    {
        $partner = $this->partner('10.0000');
        $charge = $this->charge('10000.00', $this->fixtureStudentFor($partner));

        $payment = $this->receive($charge, '10000.00');

        $entries = CollaboratorCommissionLedgerEntry::query()->get();

        $this->assertCount(1, $entries, 'One receipt earns one entry.');

        $entry = $entries->first();

        $this->assertSame('1000.00', (string) $entry->amount, '10% of the 10,000 collectible.');
        $this->assertSame('10000.00', (string) $entry->base_amount);
        $this->assertSame('10.0000', (string) $entry->commission_rate);
        $this->assertSame(
            $payment->paid_on->toDateString(),
            $entry->transaction_date->toDateString(),
            'The entry is dated by the VALUE date, so a back-dated receipt lands in the right period.',
        );
        $this->assertNotEmpty($entry->rule_snapshot, 'The rule that produced it is snapshotted onto the row.');

        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * PH18-03 (§120.3) — the same payment processed twice creates one commission.
     *
     * Three guards, and the test exercises all three because only one of them is a guarantee: the
     * `idempotency_key` stops a second receipt, the job's uniqueness lock saves the work, and
     * `uq_cle_dedupe` is what makes a second ledger row physically impossible.
     */
    #[Test]
    public function the_same_fee_payment_processed_twice_creates_one_commission(): void
    {
        $partner = $this->partner('10.0000');
        $charge = $this->charge('10000.00', $this->fixtureStudentFor($partner));

        $first = $this->receive($charge, '10000.00');

        // The same modal submitted again carries the same key, so there is one receipt.
        $replay = $this->payments()->recordStudentFeePayment($charge->refresh(), new RecordPaymentData(
            amount: '10000.00',
            method: PaymentMethod::Cash,
            paidOn: $first->paid_on,
            idempotencyKey: $first->idempotency_key,
        ));

        $this->assertFalse($replay->created, 'A replayed POST returns the receipt that exists.');
        $this->assertSame((int) $first->getKey(), (int) $replay->payment->getKey());
        $this->assertDatabaseCount('student_fee_payments', 1);
        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 1);

        // And running the engine again over the same receipt adds nothing.
        app(StudentCommissionService::class)->handlePayment($first->refresh());

        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 1);
        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * PH18-04 (§120.4) — three installments, three commissions.
     *
     * This is the case the whole plan machinery exists for: commission follows money actually
     * received, so a 30,000 charge paid in three parts earns three times rather than once.
     */
    #[Test]
    public function three_installments_create_three_commissions(): void
    {
        $partner = $this->partner('10.0000');
        $charge = $this->charge('30000.00', $this->fixtureStudentFor($partner));
        $lines = $this->plan($charge, 3);

        foreach ($lines as $line) {
            $this->receive($charge, (string) $line->amount, $line);
        }

        $entries = CollaboratorCommissionLedgerEntry::query()->orderBy('id')->get();

        $this->assertCount(3, $entries);
        $this->assertSame(
            ['1000.00', '1000.00', '1000.00'],
            $entries->map(static fn ($e): string => (string) $e->amount)->all(),
        );

        // Each entry points at its own receipt AND its own line — the two together are what make the
        // three distinguishable when somebody asks which installment earned what.
        $this->assertCount(3, $entries->pluck('student_fee_payment_id')->unique());

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Paid, $charge->status);
        $this->assertSame('0.00', (string) $charge->balance_amount);
        $this->assertPlanIntegrity($charge);
        $this->assertCachesMatchRows($charge);
        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * PH18-05 (§120.5) — a refund writes a negative entry and leaves the original byte-identical.
     *
     * The original row is the evidence of what was earned and why: the rate, the base, the rule
     * snapshot. Editing it to "correct" the amount would destroy the only record of the calculation
     * that produced a payment somebody may already have received.
     */
    #[Test]
    public function a_refund_creates_a_negative_reversal_and_preserves_the_original(): void
    {
        $partner = $this->partner('10.0000');
        $charge = $this->charge('10000.00', $this->fixtureStudentFor($partner));

        $payment = $this->receive($charge, '10000.00');

        $original = CollaboratorCommissionLedgerEntry::query()->firstOrFail();
        $before = [
            'amount' => (string) $original->amount,
            'rate' => (string) $original->commission_rate,
            'base_amount' => (string) $original->base_amount,
            'rule_snapshot' => json_encode($original->rule_snapshot),
        ];

        $this->payments()->refund($payment->refresh(), new RefundData(
            amount: '10000.00',
            reason: 'Student withdrew',
            type: ReversalType::FullRefund,
        ));

        $entries = CollaboratorCommissionLedgerEntry::query()->orderBy('id')->get();

        $this->assertCount(2, $entries, 'A reversal is a new row, never an edit.');

        $reversal = $entries->last();

        $this->assertSame('1000.00', (string) $reversal->amount);
        $this->assertSame('-1000.00', (string) $reversal->signed_amount, 'The direction is in the sign.');
        $this->assertSame((int) $original->getKey(), (int) $reversal->reverses_entry_id);

        // Byte-identical, re-read from the database.
        $original->refresh();

        $this->assertSame($before['amount'], (string) $original->amount);
        $this->assertSame($before['rate'], (string) $original->commission_rate);
        $this->assertSame($before['base_amount'], (string) $original->base_amount);
        $this->assertSame($before['rule_snapshot'], json_encode($original->rule_snapshot));

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Refunded, $charge->status, 'Nothing received, something refunded.');
        $this->assertCachesMatchRows($charge);
        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * §6.4.1 row 5 — a **void** is not a refund, and the charge says so.
     *
     * A voided receipt leaves both legs of the calculation, so the charge returns to `pending` rather
     * than to `refunded`: "that receipt never counted" is a different claim from "the money went
     * back", and the spine's private `chargeStatus()` used to conflate them.
     */
    #[Test]
    public function voiding_a_receipt_returns_the_charge_to_pending_not_refunded(): void
    {
        $partner = $this->partner('10.0000');
        $charge = $this->charge('10000.00', $this->fixtureStudentFor($partner));

        $payment = $this->receive($charge, '10000.00');

        $this->assertSame(StudentFeeStatus::Paid, $charge->refresh()->status);

        $this->payments()->void($payment->refresh(), 'Mis-keyed');

        $charge->refresh();

        $this->assertSame(StudentFeeStatus::Pending, $charge->status, 'A void is not a refund.');
        $this->assertSame('0.00', (string) $charge->paid_amount, 'A voided receipt leaves the paid leg.');
        $this->assertSame('0.00', (string) $charge->refunded_amount, '…and the refunded leg too.');
        $this->assertSame('10000.00', (string) $charge->balance_amount);
        $this->assertCachesMatchRows($charge);
        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * §6.4.1 row 2 — a charge covered entirely by a scholarship reads `paid`, not `pending`.
     *
     * This is one of the three rows the spine's private copy got wrong. There is nothing to collect,
     * and leaving it `pending` would put it on the collection desk for ever.
     */
    #[Test]
    public function a_fully_scholarshiped_charge_reads_paid_and_earns_nothing(): void
    {
        $partner = $this->partner('10.0000');
        $charge = $this->charge('10000.00', $this->fixtureStudentFor($partner));

        $this->discount($charge, '10000.00', FeeDiscountType::Scholarship);

        $charge->refresh();

        $this->assertSame('0.00', (string) $charge->net_amount);
        $this->assertSame(StudentFeeStatus::Paid, $charge->status, 'Nothing to collect is paid, not pending.');
        $this->assertSame('0.00', (string) $charge->balance_amount);

        // No receipt, so no commission — and, again, no zero row.
        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);
        $this->assertCachesMatchRows($charge);
        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * A student credited to a partner, through the referral service — never a hand-written column.
     */
    private function fixtureStudentFor(Collaborator $partner): Student
    {
        $student = $this->feeStudent();

        app(ReferralService::class)->attachSubject(
            ReferralSubject::Student,
            (int) $student->getKey(),
            $partner,
            ReferralSource::ManualSelection,
            null,
            Carbon::parse('2020-01-01'),
        );

        return $student;
    }
}
