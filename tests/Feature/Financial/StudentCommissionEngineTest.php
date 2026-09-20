<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\RuleData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CollaborationType;
use App\Enums\CollaboratorStatus;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionProcessingState;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\CommissionStatus;
use App\Enums\FixedCommissionRelease;
use App\Enums\LedgerEntryPurpose;
use App\Enums\LedgerEntryType;
use App\Enums\ReversalType;
use App\Enums\StudentFeeType;
use App\Models\Activity;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Services\Collaborator\CommissionRuleService;
use App\Services\Collaborator\StudentCommissionService;
use App\Services\Finance\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The student commission engine, against the requirements themselves (phase-10-12 §11.1, §11.2).
 *
 * Every test here ends with `assertWalletMatchesLedger()`. The figures are the ones the requirement
 * document states in rupees, not values derived from the code: a test that computed its expectation
 * the same way the code does would pass whatever the code did.
 */
final class StudentCommissionEngineTest extends TestCase
{
    use BuildsFinancialFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'manual');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('collaborator.commission_on_overpayment', false);
        $this->setting('collaborator.commission_min_entry_amount', '0.00');
        $this->setting('collaborator.commission_on_admission_fee', false);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.refund_approval_required', false);
    }

    /*
    |--------------------------------------------------------------------------
    | §120 — the requirement tests
    |--------------------------------------------------------------------------
    */

    /** FT-01 — §120.1 */
    #[Test]
    public function a_student_without_a_collaborator_creates_no_commission_row(): void
    {
        $fee = $this->charge(null, '30000.00');

        $payment = $this->receive($fee, '10000.00')->payment;

        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);
        $this->assertDatabaseCount('collaborator_commission_entitlements', 0);

        $payment->refresh();
        $this->assertSame(CommissionProcessingState::Skipped, $payment->commission_state);
        $this->assertSame(CommissionSkipReason::NoReferral, $payment->commission_skip_reason);
        $this->assertNotNull($payment->commission_skip_detail);

        $this->assertSame(1, Activity::query()->where('event', 'commission.skipped')->count(),
            'Exactly one activity row per skip — not none, and not one per sweep.');
    }

    /** FT-02 — §120.2 */
    #[Test]
    public function a_student_payment_creates_one_commission_at_the_configured_rate(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');

        $payment = $this->receive($fee, '10000.00', ['on' => '2026-03-10'])->payment;
        $entry = $this->entryFor($payment);

        $this->assertNotNull($entry);
        $this->assertSame('1000.00', (string) $entry->amount);
        $this->assertSame(LedgerEntryType::Credit, $entry->entry_type);
        $this->assertSame(LedgerEntryPurpose::StudentCommission, $entry->purpose);
        $this->assertSame('10000.00', (string) $entry->base_amount);
        $this->assertSame('10000.00', (string) $entry->gross_amount);
        $this->assertSame('10.0000', (string) $entry->commission_rate);
        $this->assertSame(CommissionBase::Paid, $entry->commission_base);
        $this->assertSame(CommissionStatus::Pending, $entry->status, 'Manual approval mode posts pending.');
        $this->assertNotEmpty($entry->rule_snapshot);
        $this->assertSame('2026-03-10', $entry->transaction_date->toDateString(),
            'The transaction date is the receipt\'s value date, never the day the job ran.');

        $wallet = $this->walletOf($partner);
        $this->assertSame('1000.00', (string) $wallet?->pending_balance);
        $this->assertSame('0.00', (string) $wallet?->available_balance);

        $this->assertWalletMatchesLedger($partner);
    }

    /** FT-03 — §120.3 */
    #[Test]
    public function the_same_fee_payment_processed_twice_creates_one_commission(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;

        $engine = app(StudentCommissionService::class);

        // Replayed however many times — a sweeper retry, a redelivered job, an operator replaying
        // `failed_jobs` weeks later.
        $engine->handlePayment($payment->refresh());
        $engine->handlePayment($payment->refresh());

        $this->assertSame(1, CollaboratorCommissionLedgerEntry::query()->earnings()->count());

        // And the database refuses it even when the service is bypassed entirely.
        $existing = $this->entryFor($payment->refresh());

        $this->expectExceptionMessageMatches('/Duplicate entry|uq_cle_/i');

        CollaboratorCommissionLedgerEntry::allowDirectWrites(function () use ($existing): void {
            $copy = $existing->replicate();
            $copy->forceFill(['id' => null])->save();
        });
    }

    /** FT-04 — §120.4 / §42 */
    #[Test]
    public function three_installments_create_three_commissions(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');

        $lines = [
            $this->installment($fee, 1, '10000.00'),
            $this->installment($fee, 2, '10000.00'),
            $this->installment($fee, 3, '10000.00'),
        ];

        $payments = [];

        foreach ($lines as $index => $line) {
            $payments[] = $this->receive($fee, '10000.00', [
                'installment' => $line->getKey(),
                'on' => '2026-0'.($index + 2).'-10',
            ])->payment;
        }

        $entries = CollaboratorCommissionLedgerEntry::query()->earnings()->get();

        $this->assertCount(3, $entries);
        $this->assertSame(['1000.00', '1000.00', '1000.00'],
            $entries->map(fn ($e) => (string) $e->amount)->all());

        $this->assertCount(3, $entries->pluck('student_fee_payment_id')->unique(),
            'Each commission points at its own receipt.');

        $entitlements = CollaboratorCommissionEntitlement::query()->get();
        $this->assertCount(1, $entitlements, 'One promise per document, three releases against it.');
        $this->assertSame('30000.00', (string) $entitlements->first()->collected_amount);

        $this->assertSame('3000.00', (string) $this->walletOf($partner)?->pending_balance);
        $this->assertWalletMatchesLedger($partner);
    }

    /** FT-05 — §120.5 */
    #[Test]
    public function a_student_refund_creates_a_negative_reversal_and_preserves_the_original(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;

        $original = $this->entryFor($payment);
        $before = $original->only(['amount', 'commission_rate', 'base_amount', 'gross_amount']);
        $snapshotBefore = $original->rule_snapshot;

        $reversal = app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '10000.00',
            reason: 'Student withdrew',
            type: ReversalType::FullRefund,
        ));

        $debit = CollaboratorCommissionLedgerEntry::query()
            ->where('payment_reversal_id', $reversal->getKey())
            ->first();

        $this->assertNotNull($debit);
        $this->assertSame(LedgerEntryType::Debit, $debit->entry_type);
        $this->assertSame(LedgerEntryPurpose::Reversal, $debit->purpose);
        $this->assertSame('1000.00', (string) $debit->amount);
        $this->assertSame('-1000.00', (string) $debit->signed_amount);
        $this->assertSame((int) $original->getKey(), (int) $debit->reverses_entry_id);
        $this->assertSame((string) $original->commission_rate, (string) $debit->commission_rate,
            'The reversal quotes the rule that produced what it undoes.');

        $original->refresh();
        $this->assertSame($before, $original->only(['amount', 'commission_rate', 'base_amount', 'gross_amount']),
            'INV-4: the original is byte-identical on every money column, for ever.');
        $this->assertSame($snapshotBefore, $original->rule_snapshot);

        $this->assertSame(CommissionStatus::Reversed, $original->status);
        $this->assertSame(CommissionStatus::Reversed, $debit->refresh()->status,
            'INV-15: the reversal rows move with the entry they undo.');
        $this->assertNotNull($original->reversed_at);

        $wallet = $this->walletOf($partner);
        $this->assertSame('0.00', (string) $wallet?->pending_balance);
        $this->assertSame('0.00', (string) $wallet?->lifetime_earned);
        $this->assertSame('1000.00', (string) $wallet?->total_reversed);

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | §11.2 — money, rounding, installments
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_percentage_uses_bcmath_half_up(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '10000.00');

        $payment = $this->receive($fee, '3333.33')->payment;

        $this->assertSame('333.33', (string) $this->entryFor($payment)?->amount,
            '10 % of 3,333.33 is 333.333, which is 333.33 at the paisa — half-up, never a float.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** [D-FS-10] — the binding reason branch C is the default. */
    #[Test]
    public function a_fixed_commission_prorates_across_installments_to_the_exact_total(): void
    {
        $partner = $this->partner(fixedAmount: '2000.00', rule: [
            'release' => FixedCommissionRelease::Prorated,
            'base' => CommissionBase::NetAfterDiscount,
        ]);

        $fee = $this->charge($partner, '30000.00');

        $slices = [];

        foreach (['10000.00', '10000.00', '10000.00'] as $index => $amount) {
            $payment = $this->receive($fee, $amount, ['on' => '2026-0'.($index + 2).'-10'])->payment;
            $slices[] = (string) $this->entryFor($payment)?->amount;
        }

        $this->assertSame(['666.67', '666.66', '666.67'], $slices,
            'The cumulative target, with the final receipt absorbing the residual.');

        $this->assertSame('2000.00', Money::sum(...$slices),
            'A fixed commission sums to exactly what was promised — never 1,999.99 and never 2,000.01.');

        $this->assertSame('2000.00', (string) $this->walletOf($partner)?->pending_balance);
        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_document_base_cannot_over_release(): void
    {
        $partner = $this->partner(fixedAmount: '2000.00', rule: [
            'release' => FixedCommissionRelease::Prorated,
            'base' => CommissionBase::NetAfterDiscount,
        ]);

        $fee = $this->charge($partner, '30000.00');

        foreach (['10000.00', '10000.00', '10000.00'] as $index => $amount) {
            $this->receive($fee, $amount, ['on' => '2026-0'.($index + 2).'-10']);
        }

        // A fourth receipt against an exhausted promise.
        $fourth = $this->receive($fee, '10000.00', ['on' => '2026-05-10'])->payment;

        $this->assertNull($this->entryFor($fourth), 'No row at all — not a zero row (INV-2).');
        $this->assertContains($fourth->refresh()->commission_skip_reason, [
            CommissionSkipReason::EntitlementCapReached,
            CommissionSkipReason::OverpaymentOnly,
        ]);

        $this->assertSame('2000.00', (string) $this->walletOf($partner)?->pending_balance);
        $this->assertWalletMatchesLedger($partner);
    }

    /** §43 — the overpayment case, where the gap has to stay visible. */
    #[Test]
    public function an_overpayment_caps_the_commissionable_amount(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00', ['discount_amount' => '5000.00']);

        $payment = $this->receive($fee, '35000.00')->payment;
        $entry = $this->entryFor($payment);

        $this->assertSame('2500.00', (string) $entry?->amount, '10 % of the 25,000 collectible.');
        $this->assertSame('25000.00', (string) $entry?->base_amount);
        $this->assertSame('35000.00', (string) $entry?->gross_amount,
            'The receipt still shows what actually arrived, so the gap is visible.');

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_commission_that_rounds_to_zero_creates_no_row(): void
    {
        $partner = $this->partner('0.0100');
        $fee = $this->charge($partner, '30000.00');

        $payment = $this->receive($fee, '0.01')->payment;

        $this->assertNull($this->entryFor($payment));
        $this->assertSame(CommissionProcessingState::Skipped, $payment->refresh()->commission_state);
        $this->assertSame(CommissionSkipReason::RoundsToZero, $payment->commission_skip_reason);

        $this->assertWalletMatchesLedger($partner);
    }

    /** §6.6 — three partial refunds that must sum exactly. */
    #[Test]
    public function partial_refunds_never_over_reverse(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;
        $original = $this->entryFor($payment);

        $payments = app(PaymentService::class);
        $slices = [];

        foreach (['3333.00', '3333.00', '3334.00'] as $index => $amount) {
            $reversal = $payments->refund($payment->refresh(), new RefundData(
                amount: $amount,
                reason: 'Partial refund '.($index + 1),
                type: ReversalType::PartialRefund,
            ));

            $slices[] = (string) CollaboratorCommissionLedgerEntry::query()
                ->where('payment_reversal_id', $reversal->getKey())->value('amount');
        }

        $this->assertSame(['333.30', '333.30', '333.40'], $slices,
            'Cumulative targets: each pass undoes only the difference, so three roundings cannot drift.');

        $this->assertSame('1000.00', Money::sum(...$slices));
        $this->assertSame('1000.00', (string) $original->refresh()->reversed_amount);
        $this->assertSame('1000.00', (string) $original->amount, 'The original never moves.');

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_refund_larger_than_the_payment_is_rejected(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;

        $this->expectExceptionMessageMatches('/still refundable/');

        app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '10000.01',
            reason: 'One paisa too many',
            type: ReversalType::PartialRefund,
        ));
    }

    /** §6.6 row 20 — a late skip must not consume the promise. */
    #[Test]
    public function a_late_skip_leaves_the_entitlement_untouched(): void
    {
        $this->setting('collaborator.commission_min_entry_amount', '100.00');

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');

        $tiny = $this->receive($fee, '500.00', ['on' => '2026-02-10'])->payment;

        $this->assertSame(CommissionSkipReason::BelowMinimumCommission, $tiny->refresh()->commission_skip_reason);

        $entitlement = CollaboratorCommissionEntitlement::query()->first();
        $this->assertNotNull($entitlement, 'The promise was opened and stays open.');
        $this->assertSame('0.00', (string) $entitlement->collected_amount,
            'A receipt that earned nothing does not consume the promise, so a later one can still earn.');
        $this->assertSame('0.00', (string) $entitlement->released_amount);

        $real = $this->receive($fee, '10000.00', ['on' => '2026-03-10'])->payment;
        $this->assertSame('1000.00', (string) $this->entryFor($real)?->amount);

        $this->assertWalletMatchesLedger($partner);
    }

    /** [D-IMP-5] — one receipt, one key, whichever source type it is reported under. */
    #[Test]
    public function an_installment_receipt_uses_one_dedupe_key_regardless_of_source_type(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');
        $line = $this->installment($fee, 1, '10000.00');

        $payment = $this->receive($fee, '10000.00', ['installment' => $line->getKey()])->payment;
        $entry = $this->entryFor($payment);

        $this->assertSame(CommissionSourceType::StudentInstallmentPayment, $entry?->source_type,
            '§51 reports an installment receipt separately.');

        $this->assertStringStartsWith('student_fee_payment:', (string) $entry?->dedupe_key,
            'But the key is keyed on the table, so the same receipt cannot produce two rows under two '
            .'source types.');

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | §11.3 — rules and attribution
    |--------------------------------------------------------------------------
    */

    /** INV-16 — the value date decides, never `now()`. */
    #[Test]
    public function a_rule_is_resolved_on_the_value_date(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');

        app(CommissionRuleService::class)->createVersion(
            $partner,
            CommissionScope::Student,
            new RuleData(
                scope: CommissionScope::Student,
                calculationType: CommissionCalculationType::Percentage,
                effectiveFrom: Carbon::parse('2026-06-01'),
                rate: '20.0000',
            ),
            'Raised for the second half',
        );

        $before = $this->receive($fee, '10000.00', ['on' => '2026-03-10'])->payment;
        $after = $this->receive($fee, '10000.00', ['on' => '2026-07-10'])->payment;

        $this->assertSame('1000.00', (string) $this->entryFor($before)?->amount,
            'A receipt dated in the old window earns at the old rate, whenever it is entered.');
        $this->assertSame('2000.00', (string) $this->entryFor($after)?->amount);

        $this->assertWalletMatchesLedger($partner);
    }

    /** §120 — the guard that keeps money away from a partner nobody configured ([D-FS-9]). */
    #[Test]
    public function a_partner_with_no_rule_earns_nothing_and_the_report_says_why(): void
    {
        $this->fixtureSequence++;
        $partner = new Collaborator;
        $partner->forceFill([
            'collaborator_code' => 'COL-NR01',
            'referral_code' => 'norule1',
            'name' => 'Partner With No Rule',
            'collaboration_type' => CollaborationType::ReferralPartner->value,
            'status' => CollaboratorStatus::Active->value,
        ])->save();

        $fee = $this->charge($partner->refresh(), '30000.00');
        $payment = $this->receive($fee, '10000.00')->payment;

        $this->assertNull($this->entryFor($payment));
        $this->assertSame(CommissionSkipReason::NoEffectiveRule, $payment->refresh()->commission_skip_reason);
        $this->assertStringContainsString('no global fallback', (string) $payment->commission_skip_detail,
            'There is deliberately no fallback to a global default rate, and the report says so.');
    }

    #[Test]
    public function a_suspended_collaborator_earns_nothing_and_is_not_backfilled(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);

        $partner->forceFill([
            'status' => CollaboratorStatus::Suspended->value,
            'status_changed_at' => Carbon::parse('2026-03-04'),
        ])->save();

        $payment = $this->receive($fee, '10000.00')->payment;

        $this->assertNull($this->entryFor($payment));
        $this->assertSame(CommissionSkipReason::CollaboratorInactive, $payment->refresh()->commission_skip_reason);
        $this->assertStringContainsString($partner->collaborator_code, (string) $payment->commission_skip_detail,
            'The skip names the partner, or the report cannot be acted on.');

        // Reinstating does not backfill: silent retro-creation is how duplicate money happens.
        $partner->forceFill(['status' => CollaboratorStatus::Active->value])->save();

        $this->assertSame(0, CollaboratorCommissionLedgerEntry::query()->count());
        $this->assertSame(CommissionProcessingState::Skipped, $payment->refresh()->commission_state);
    }

    /** §120 — commission follows money, never an event that is not money. */
    #[Test]
    public function an_unpaid_installment_plan_produces_no_commission(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');

        $this->installment($fee, 1, '10000.00');
        $this->installment($fee, 2, '10000.00');
        $this->installment($fee, 3, '10000.00');

        // A plan is a promise to pay, not a payment: three lines and no receipts earn nothing.
        // (`assertDatabaseCount()`'s third argument is a connection name, not a message.)
        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_fee_type_outside_the_commissionable_set_earns_nothing(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '10000.00', ['fee_type' => StudentFeeType::AdmissionFee->value]);

        $payment = $this->receive($fee, '10000.00')->payment;

        $this->assertNull($this->entryFor($payment));
        $this->assertSame(CommissionSkipReason::FeeTypeNotCommissionable, $payment->refresh()->commission_skip_reason);

        // Flip the institute's own setting and the same receipt earns.
        $this->setting('collaborator.commission_on_admission_fee', true);

        $second = $this->charge($this->partner('10.0000'), '10000.00', ['fee_type' => StudentFeeType::AdmissionFee->value]);
        $earning = $this->receive($second, '10000.00')->payment;

        $this->assertSame('1000.00', (string) $this->entryFor($earning)?->amount);
    }
}
