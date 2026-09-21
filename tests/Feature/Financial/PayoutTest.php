<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CommissionStatus;
use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Enums\ReversalType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorPayoutAllocation;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Services\Collaborator\CommissionApprovalService;
use App\Services\Collaborator\CommissionReversalService;
use App\Services\Collaborator\PayoutService;
use App\Services\Finance\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * Paying a partner (phase-10-12 §11.5, spine §6.4, §120.9).
 *
 * The arithmetic that matters here is §120.9's: a 20,000 payout against one 50,000 entry leaves
 * available 30,000, paid 20,000, reserved 0, lifetime 50,000 — with the entry **still `available`**
 * carrying `allocated_amount = 20,000`, because "paid commission" comes from allocations and never
 * from a status somebody flipped (INV-23).
 */
final class PayoutTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'automatic');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('collaborator.minimum_payout', '0.00');
        $this->setting('collaborator.payout_auto_approve_below', '0.00');
        $this->setting('collaborator.payout_single_inflight', true);
        $this->setting('collaborator.payout_request_enabled', true);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.payout_reference_required', true);
    }

    /** FT-09 — §120.9, the arithmetic the requirement states in rupees. */
    #[Test]
    public function a_payout_of_20000_against_a_50000_wallet(): void
    {
        $partner = $this->earning('500000.00', '50000.00');

        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '20000.00',
            method: PayoutMethod::BankTransfer,
        ), $this->createSuperAdmin());

        $this->assertSame('20000.00', (string) $payout->amount, 'Derived from the allocations (INV-22).');
        $this->assertSame(1, (int) $payout->entry_count);

        app(PayoutService::class)->approve($payout, $this->createSuperAdmin());
        app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData(
            transactionId: 'BANK-REF-1',
        ), $this->createSuperAdmin());

        $wallet = $this->walletOf($partner);

        $this->assertSame('30000.00', (string) $wallet?->available_balance);
        $this->assertSame('20000.00', (string) $wallet?->paid_balance);
        $this->assertSame('0.00', (string) $wallet?->reserved_balance);
        $this->assertSame('50000.00', (string) $wallet?->lifetime_earned);

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->first();

        $this->assertSame(CommissionStatus::Available, $entry->refresh()->status,
            'INV-23: a partially settled entry is not `paid`.');
        $this->assertSame('20000.00', (string) $entry->allocated_amount);

        // Payout history is preserved: the row and its allocation both survive.
        $this->assertSame(1, CollaboratorPayout::query()->count());
        $this->assertSame(1, CollaboratorPayoutAllocation::query()->where('is_released', 0)->count());

        $this->assertWalletMatchesLedger($partner);
    }

    /** [D-FS-11] — the plan names the entries, and writes nothing. */
    #[Test]
    public function the_plan_previews_the_exact_entries_and_writes_nothing(): void
    {
        $partner = $this->earning('100000.00', '10000.00');
        $this->earnMore($partner, '50000.00', '5000.00');

        $plan = app(PayoutService::class)->plan($partner, '12000.00');

        $this->assertTrue($plan->isSatisfiable());
        $this->assertSame(2, $plan->entryCount(), 'FIFO: the oldest entry first, then part of the next.');
        $this->assertSame('10000.00', $plan->slices[0]['slice']);
        $this->assertSame('2000.00', $plan->slices[1]['slice']);
        $this->assertSame('12000.00', $plan->allocatable);

        $this->assertSame(0, CollaboratorPayout::query()->count());
        $this->assertSame(0, CollaboratorPayoutAllocation::query()->count());

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_plan_reports_a_shortfall_rather_than_silently_paying_less(): void
    {
        $partner = $this->earning('100000.00', '10000.00');

        $plan = app(PayoutService::class)->plan($partner, '15000.00');

        $this->assertFalse($plan->isSatisfiable());
        $this->assertSame('5000.00', $plan->shortfall);

        // And the service refuses rather than creating a smaller payout.
        try {
            app(PayoutService::class)->createFor($partner, new PayoutRequestData(
                requestedAmount: '15000.00',
                method: PayoutMethod::BankTransfer,
            ), $this->createSuperAdmin());

            $this->fail('A payout larger than the available balance was accepted.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('available', strtolower($e->getMessage()));
        }

        $this->assertSame(0, CollaboratorPayout::query()->count(), 'Nothing was written.');
        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function requesting_reserves_the_money_and_rejecting_gives_it_back(): void
    {
        $partner = $this->earning('100000.00', '10000.00');
        $actor = $this->createSuperAdmin();

        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '6000.00',
            method: PayoutMethod::BankTransfer,
        ), $actor);

        $wallet = $this->walletOf($partner);
        $this->assertSame('6000.00', (string) $wallet?->reserved_balance);
        $this->assertSame('4000.00', (string) $wallet?->available_balance);
        $this->assertWalletMatchesLedger($partner);

        app(PayoutService::class)->reject($payout->refresh(), 'Bank details could not be verified', $actor);

        $wallet = $this->walletOf($partner);
        $this->assertSame('0.00', (string) $wallet?->reserved_balance);
        $this->assertSame('10000.00', (string) $wallet?->available_balance,
            'The money goes back to available; the entry never left `available`.');

        $this->assertSame(PayoutStatus::Rejected, $payout->refresh()->status);
        $this->assertStringContainsString('could not be verified', (string) $payout->rejection_reason);

        // The allocation row survives, released — it is the evidence that it once counted.
        $this->assertSame(1, CollaboratorPayoutAllocation::query()->where('is_released', 1)->count());

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_frozen_wallet_blocks_a_payout_without_blocking_earning(): void
    {
        $partner = $this->earning('100000.00', '10000.00');

        app(CollaboratorWalletService::class)
            ->freeze($partner, true, 'Under review after a client complaint');

        try {
            app(PayoutService::class)->createFor($partner, new PayoutRequestData(
                requestedAmount: '5000.00',
                method: PayoutMethod::BankTransfer,
            ), $this->createSuperAdmin());

            $this->fail('A frozen wallet paid out.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('frozen', strtolower($e->getMessage()));
        }

        // Earning continues: freezing stops payment, not accrual.
        $this->earnMore($partner, '50000.00', '5000.00');

        $this->assertSame('15000.00', (string) $this->walletOf($partner)?->available_balance);
        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_negative_balance_blocks_a_payout(): void
    {
        $partner = $this->earning('100000.00', '10000.00');

        app(CommissionReversalService::class)->adjust($partner, '-12000.00', 'Correcting an overpayment');

        $this->assertTrue(Money::isNegative((string) $this->walletOf($partner)?->available_balance));

        try {
            app(PayoutService::class)->createFor($partner, new PayoutRequestData(
                requestedAmount: '1000.00',
                method: PayoutMethod::BankTransfer,
            ), $this->createSuperAdmin());

            $this->fail('A negative balance paid out.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('negative', strtolower($e->getMessage()));
        }

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function only_one_payout_may_be_in_flight(): void
    {
        $partner = $this->earning('100000.00', '10000.00');
        $actor = $this->createSuperAdmin();

        app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '3000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        try {
            app(PayoutService::class)->createFor($partner, new PayoutRequestData(
                requestedAmount: '3000.00', method: PayoutMethod::BankTransfer,
            ), $actor);

            $this->fail('Two payouts were in flight at once.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('already a payout in flight', $e->getMessage());
        }

        $this->assertSame(1, CollaboratorPayout::query()->count());
        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function marking_paid_needs_the_bank_reference(): void
    {
        $partner = $this->earning('100000.00', '10000.00');
        $actor = $this->createSuperAdmin();

        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '5000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        app(PayoutService::class)->approve($payout, $actor);

        try {
            app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData, $actor);
            $this->fail('A payout was marked paid with no bank reference.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('bank reference', $e->getMessage());
        }

        $this->assertSame(PayoutStatus::Approved, $payout->refresh()->status);
        $this->assertWalletMatchesLedger($partner);
    }

    /** §6.4.5 — the single backward money transition, and it is audited. */
    #[Test]
    public function a_returned_transfer_puts_settled_money_back_into_available(): void
    {
        $partner = $this->earning('100000.00', '10000.00');
        $actor = $this->createSuperAdmin();

        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '10000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        app(PayoutService::class)->approve($payout, $actor);
        app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-RET-1'), $actor);

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->first();
        $this->assertSame(CommissionStatus::Paid, $entry->refresh()->status,
            'Claimed in full, so this one does become `paid`.');
        $this->assertSame('10000.00', (string) $this->walletOf($partner)?->paid_balance);

        app(PayoutService::class)->cancelAfterPayment($payout->refresh(), 'The bank returned the transfer', $actor);

        $wallet = $this->walletOf($partner);
        $this->assertSame('0.00', (string) $wallet?->paid_balance);
        $this->assertSame('10000.00', (string) $wallet?->available_balance);
        $this->assertSame(CommissionStatus::Available, $entry->refresh()->status);
        $this->assertSame(PayoutStatus::Cancelled, $payout->refresh()->status);

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function returning_a_payment_needs_a_reason(): void
    {
        $partner = $this->earning('100000.00', '10000.00');
        $actor = $this->createSuperAdmin();

        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '10000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        app(PayoutService::class)->approve($payout, $actor);
        app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-RET-2'), $actor);

        try {
            app(PayoutService::class)->cancelAfterPayment($payout->refresh(), '  ', $actor);
            $this->fail('A settled payout was returned with no reason.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('reason', strtolower($e->getMessage()));
        }

        $this->assertSame(PayoutStatus::Paid, $payout->refresh()->status);
        $this->assertWalletMatchesLedger($partner);
    }

    /** INV-22 — a payout never touches a pending or approved entry. */
    #[Test]
    public function only_available_credits_are_candidates(): void
    {
        $this->setting('collaborator.commission_approval_mode', 'manual');

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');
        $this->receive($fee, '10000.00');

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->first();
        $this->assertSame(CommissionStatus::Pending, $entry->status);

        $plan = app(PayoutService::class)->plan($partner, '1000.00');

        $this->assertSame(0, $plan->entryCount(),
            'A pending entry is structurally unreachable: the WHERE clause is the guarantee.');
        $this->assertSame('1000.00', $plan->shortfall);

        // Approve it and it becomes a candidate.
        app(CommissionApprovalService::class)->approve($entry, $this->createSuperAdmin());

        $this->assertSame(1, app(PayoutService::class)->plan($partner, '1000.00')->entryCount());

        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * §6.6's headline row: a refund after the commission was already paid out.
     *
     * The money has left the company, so it cannot be "reversed" — it is **clawed back**. The debit
     * lands in `available`, the wallet legitimately goes negative, and new payouts are refused while
     * it is. This is the case the two-column `uq_cle_reversal_pair` made impossible until migration 22
     * widened it: one reversal posting a `reversal` debit *and* a `clawback` debit against one
     * original.
     */
    #[Test]
    public function a_refund_after_a_payout_claws_back_and_the_balance_goes_negative(): void
    {
        $this->setting('collaborator.clawback_on_paid_commission', 'offset_future');

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '200000.00');
        $payment = $this->receive($fee, '100000.00', ['on' => '2026-02-10'])->payment;

        $entry = $this->entryFor($payment);
        $this->assertSame('10000.00', (string) $entry->amount);

        $actor = $this->createSuperAdmin();

        // Pay out 6,000 of it, leaving 4,000 unpaid.
        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '6000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        app(PayoutService::class)->approve($payout, $actor);
        app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-CB-1'), $actor);

        $this->assertSame('6000.00', (string) $this->walletOf($partner)?->paid_balance);

        // Now the whole receipt is refunded: 10,000 of commission has to come back, but 6,000 of it
        // has already left.
        $reversal = app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '100000.00',
            reason: 'Client withdrew after the first module',
            type: ReversalType::FullRefund,
        ));

        $debits = CollaboratorCommissionLedgerEntry::query()
            ->where('payment_reversal_id', $reversal->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $debits,
            'One reversal, two debits: the unpaid part reversed and the paid part clawed back.');

        $byPurpose = $debits->keyBy(fn ($d) => $d->purpose->value);

        $this->assertSame('4000.00', (string) $byPurpose['reversal']?->amount,
            'The part the partner still held is simply taken back.');
        $this->assertSame('6000.00', (string) $byPurpose['clawback']?->amount,
            'The part that already left the company is owed back.');

        $this->assertSame('10000.00', (string) $entry->refresh()->amount, 'The original never moves.');

        $wallet = $this->walletOf($partner);
        $this->assertTrue(Money::isNegative((string) $wallet?->available_balance),
            'The wallet legitimately goes negative: the business is owed money it already paid.');

        // And a new payout is refused while it is.
        try {
            app(PayoutService::class)->createFor($partner, new PayoutRequestData(
                requestedAmount: '100.00', method: PayoutMethod::BankTransfer,
            ), $actor);

            $this->fail('A payout was allowed against a negative balance.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('negative', strtolower($e->getMessage()));
        }

        $this->assertWalletMatchesLedger($partner);
    }

    /** §6.6 — a refund is never blocked by a pending withdrawal. */
    #[Test]
    public function a_refund_releases_an_in_flight_payout_rather_than_being_blocked_by_it(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '200000.00');
        $payment = $this->receive($fee, '100000.00', ['on' => '2026-02-10'])->payment;

        $entry = $this->entryFor($payment);
        $actor = $this->createSuperAdmin();

        // A payout is requested but not yet paid — the money is reserved, not gone.
        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '10000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $this->assertSame('10000.00', (string) $this->walletOf($partner)?->reserved_balance);

        app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '100000.00',
            reason: 'Refunded while a payout was in flight',
            type: ReversalType::FullRefund,
        ));

        $this->assertSame(PayoutStatus::Cancelled, $payout->refresh()->status,
            'A payout left with no live allocations is cancelled — an empty payout is not a payout.');

        $this->assertSame('0.00', (string) $this->walletOf($partner)?->reserved_balance);

        $debits = CollaboratorCommissionLedgerEntry::query()->undos()->get();

        $this->assertCount(1, $debits, 'Nothing was paid out, so it is all a plain reversal.');
        $this->assertSame('10000.00', (string) $debits->first()->amount);
        $this->assertSame('10000.00', (string) $entry->refresh()->reversed_amount);

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * A partner with one available commission of the given size.
     */
    private function earning(string $charge, string $expected): Collaborator
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, $charge);

        $this->receive($fee, Money::mul($expected, '10'), ['on' => '2026-02-10']);

        $this->assertSame($expected, (string) $this->walletOf($partner)?->available_balance);

        return $partner;
    }

    /**
     * A second, later commission for the same partner — on its own charge and its own student, because
     * `charge()` already attaches the referral and a subject may have only one active attribution.
     */
    private function earnMore(Collaborator $partner, string $charge, string $expected): void
    {
        $fee = $this->charge($partner, $charge);

        $this->receive($fee, Money::mul($expected, '10'), ['on' => '2026-03-10']);
    }
}
