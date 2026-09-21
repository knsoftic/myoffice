<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CommissionProcessingState;
use App\Enums\CommissionStatus;
use App\Enums\PayoutMethod;
use App\Enums\ReconciliationStatus;
use App\Enums\ReversalType;
use App\Events\Collaborator\WalletDriftDetected;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorWalletReconciliation;
use App\Services\Collaborator\CommissionApprovalService;
use App\Services\Collaborator\CommissionReconciliationService;
use App\Services\Collaborator\PayoutService;
use App\Services\Finance\PaymentService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The eight checks of spine §6.5.3, each proved to fire (phase-10-12 §11.6).
 *
 * The rest of the suite asserts that the reconciler stays quiet, because every money test ends with
 * `assertWalletMatchesLedger()`. That is only worth something if the checks can also **fail**: a
 * reconciler that returns "clean" unconditionally would pass all seventy-nine of those tests and prove
 * nothing at all. So each test here breaks exactly one thing with raw SQL — going around every service,
 * which is the point — and asserts that the right check catches it and that the others do not.
 *
 * The severities are asserted too, because they decide what happens next (§6.5.4). R1 and R8 are drift,
 * repairable by recomputing the cache. R2–R7 are structural: the ledger disagrees with itself, and no
 * amount of recomputing a cache derived *from* that ledger will fix it.
 */
final class WalletReconciliationTest extends TestCase
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
        $this->setting('finance.wallet_reconcile_enabled', true);
    }

    /*
    |--------------------------------------------------------------------------
    | The quiet case
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_wallet_built_only_through_the_services_passes_every_check(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');
        $this->receive($fee, '40000.00', ['on' => '2026-02-10']);
        $this->receive($fee, '30000.00', ['on' => '2026-03-10', 'key' => 'second']);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertTrue($report->passed(), $report->summary());
        $this->assertSame(ReconciliationStatus::Ok, $report->status());
        $this->assertSame('0.00', $report->driftTotal);
        $this->assertSame(2, $report->snapshot->ledgerEntryCount);
    }

    /** §6.5.4: a row every run, pass or fail — the history of being correct is the evidence. */
    #[Test]
    public function every_run_writes_its_proof_even_when_nothing_is_wrong(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        app(CommissionReconciliationService::class)->run($partner, 'manual');

        $row = CollaboratorWalletReconciliation::query()->latest('id')->first();

        $this->assertNotNull($row, 'A clean run still records that it was clean.');
        $this->assertSame(ReconciliationStatus::Ok, $row->status);
        $this->assertTrue($row->matchesLedger());
        $this->assertSame('4000.00', (string) $row->expected_available);
        $this->assertSame('4000.00', (string) $row->stored_available);
        $this->assertSame('manual', $row->run_type);

        $this->assertSame(ReconciliationStatus::Ok,
            $this->walletOf($partner)?->reconciliation_status,
            'The verdict is mirrored onto the wallet, so a screen need not join to the last run.');
    }

    /*
    |--------------------------------------------------------------------------
    | R1 — the cache fell behind
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function r1_catches_a_cache_that_no_longer_equals_the_ledger(): void
    {
        Event::fake([WalletDriftDetected::class]);

        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        // Straight into the table: exactly the kind of write the reconciler exists to notice.
        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['available_balance' => '9999.00']);

        $report = app(CommissionReconciliationService::class)->run($partner, 'manual')[0];

        $this->assertSame(ReconciliationStatus::Drift, $report->status());
        $this->assertSame('5999.00', $report->driftTotal);
        $this->assertTrue($report->isRepairable(), 'A stale cache is the one thing that may be repaired.');
        $this->assertStringContainsString('R1', $report->summary());

        Event::assertDispatched(WalletDriftDetected::class,
            fn (WalletDriftDetected $e): bool => ! $e->isStructural());
    }

    #[Test]
    public function repairing_rewrites_the_cache_and_never_the_ledger(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->firstOrFail();

        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['available_balance' => '9999.00', 'lifetime_earned' => '9999.00']);

        $report = app(CommissionReconciliationService::class)->run($partner, 'manual', repair: true)[0];

        $this->assertSame(ReconciliationStatus::Repaired, $report->status());
        $this->assertTrue($report->repaired);
        $this->assertSame('4000.00', (string) $this->walletOf($partner)?->available_balance);
        $this->assertSame('4000.00', (string) $entry->refresh()->amount,
            'The ledger row is untouched: a repair moves the copy, never the original.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** A wallet that drifted and was never repaired keeps saying so, on the wallet row itself. */
    #[Test]
    public function an_unrepaired_drift_is_recorded_on_the_wallet_for_the_banner(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['total_student_commission' => '1.00']);

        app(CommissionReconciliationService::class)->run($partner, 'scheduled');

        $wallet = $this->walletOf($partner);

        $this->assertSame(ReconciliationStatus::Drift, $wallet?->reconciliation_status);
        $this->assertSame('3999.00', (string) $wallet?->drift_amount);
        $this->assertNotNull($wallet?->last_reconciled_at);
    }

    /*
    |--------------------------------------------------------------------------
    | R2–R7 — the ledger disagreeing with itself
    |--------------------------------------------------------------------------
    */

    /** R3: two tables computed from different rows must agree on what was paid. */
    #[Test]
    public function r3_catches_a_payout_whose_amount_no_longer_matches_its_allocations(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $actor = $this->createSuperAdmin();
        $payouts = app(PayoutService::class);

        $payout = $payouts->createFor($partner, new PayoutRequestData(
            requestedAmount: '4000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $payouts->approve($payout, $actor);
        $payouts->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-R3'), $actor);

        DB::table('collaborator_payouts')->where('id', $payout->getKey())->update(['amount' => '3000.00']);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertSame(ReconciliationStatus::Failed, $report->status());
        $this->assertFalse($report->isRepairable(),
            'Recomputing a cache derived from a ledger cannot fix the ledger.');
        $this->assertSame('1000.00', $report->payoutCrossCheck);
        $this->assertStringContainsString('R3', $report->summary());
    }

    /** R4: INV-22 — a payout is worth the sum of what it actually claimed. */
    #[Test]
    public function r4_catches_an_entry_whose_allocated_amount_lies(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $actor = $this->createSuperAdmin();
        app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '1000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->firstOrFail();

        DB::table('collaborator_commission_ledger_entries')
            ->where('id', $entry->getKey())
            ->update(['allocated_amount' => '2500.00']);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertSame(ReconciliationStatus::Failed, $report->status());
        $this->assertSame(1, $report->allocationMismatches);
        $this->assertStringContainsString('R4', $report->summary());
    }

    /** R4 again, the other half: a live claim on an entry nobody can pay. */
    #[Test]
    public function r4_catches_a_live_allocation_on_an_entry_that_cannot_be_paid(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $actor = $this->createSuperAdmin();
        app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '1000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->firstOrFail();

        DB::table('collaborator_commission_ledger_entries')
            ->where('id', $entry->getKey())
            ->update(['status' => CommissionStatus::Cancelled->value]);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertSame(1, $report->orphanAllocations);
        $this->assertSame(ReconciliationStatus::Failed, $report->status());
    }

    /** R5: INV-15 — a credit and its debit never end up in different buckets. */
    #[Test]
    public function r5_catches_a_reversal_that_drifted_out_of_its_pair(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');
        $payment = $this->receive($fee, '40000.00')->payment;

        app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '40000.00',
            reason: 'Refunded in full',
            type: ReversalType::FullRefund,
        ));

        // Both rows are `reversed` and net to zero. Move only the debit out of the pair.
        $debit = CollaboratorCommissionLedgerEntry::query()->undos()->firstOrFail();

        DB::table('collaborator_commission_ledger_entries')
            ->where('id', $debit->getKey())
            ->update(['status' => CommissionStatus::Available->value]);

        $report = app(CommissionReconciliationService::class)->check($partner);

        // The credit still sits in `reversed` at +4,000; its debit left. Excluding the reversed
        // bucket therefore *changes* the total, which is the whole point of the check.
        $this->assertSame('4000.00', $report->bucketCrossfootDiff);
        $this->assertSame(ReconciliationStatus::Failed, $report->status());
        $this->assertStringContainsString('R5', $report->summary());
    }

    /** R6: an entry's own undone total equals the debits pointing at it, and never exceeds it. */
    #[Test]
    public function r6_catches_an_entry_claiming_more_undone_than_its_debits(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->firstOrFail();

        DB::table('collaborator_commission_ledger_entries')
            ->where('id', $entry->getKey())
            ->update(['reversed_amount' => '500.00']);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertSame(1, $report->reversalGroupMismatches);
        $this->assertSame(ReconciliationStatus::Failed, $report->status());
        $this->assertStringContainsString('R6', $report->summary());
    }

    /** R7: a promise released exactly what its entries say. */
    #[Test]
    public function r7_catches_an_entitlement_whose_released_total_does_not_match_its_entries(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        DB::table('collaborator_commission_entitlements')
            ->where('collaborator_id', $partner->getKey())
            ->update(['released_amount' => '2500.00']);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertSame(ReconciliationStatus::Failed, $report->status());
        $this->assertStringContainsString('R7', $report->summary());
        $this->assertStringContainsString('2500.00', $report->summary());
    }

    /**
     * R7's other half is a backstop, and this says why it reads like one.
     *
     * `released_amount > entitlement_amount` cannot be reached through any service, and it cannot be
     * reached through raw SQL either: `chk_cce_cap` refuses the row. So the check exists for the one
     * case the CHECK cannot cover — a dump restored onto a server where it was lost, which is exactly
     * what `financial:verify-constraints` watches for. Asserting the database refuses it is a stronger
     * statement than simulating a breach that cannot happen.
     */
    #[Test]
    public function releasing_more_than_was_promised_is_refused_by_the_database_itself(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $this->expectException(QueryException::class);

        DB::table('collaborator_commission_entitlements')
            ->where('collaborator_id', $partner->getKey())
            ->update(['entitlement_amount' => '1000.00', 'released_amount' => '4000.00']);
    }

    /*
    |--------------------------------------------------------------------------
    | R8 — no silent skips
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function r8_catches_a_payment_processed_with_neither_a_commission_nor_a_reason(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');

        $this->setting('collaborator.automatic_commission_enabled', false);

        $payment = $this->receive($fee, '40000.00')->payment;

        $this->assertNull($this->entryFor($payment), 'The engine is off, so nothing was posted.');

        // A skip the engine recorded honestly, with its reason stripped: the payment now claims to
        // have been processed and there is nothing anywhere saying what happened to the commission.
        // Nothing about the money is wrong; what is missing is the explanation.
        DB::table('student_fee_payments')->where('id', $payment->getKey())->update([
            'collaborator_id' => $partner->getKey(),
            'commission_state' => CommissionProcessingState::Processed->value,
            'commission_skip_reason' => null,
        ]);

        $report = app(CommissionReconciliationService::class)->check($partner);

        $this->assertStringContainsString('R8', $report->summary());
        $this->assertSame(ReconciliationStatus::Drift, $report->status(),
            'A missing explanation is reported, not treated as a broken ledger.');
    }

    /*
    |--------------------------------------------------------------------------
    | What the reconciler found in the engine itself
    |--------------------------------------------------------------------------
    */

    /**
     * A rejected commission gives its promise back.
     *
     * R7 found this: a rejection cancelled the entry but left `released_amount` standing, so a PKR
     * 2,000 fixed commission whose first instalment was rejected could afterwards only ever reach PKR
     * 1,333 — money quietly lost to an act that was supposed to cost nothing but that instalment.
     */
    #[Test]
    public function rejecting_a_commission_returns_what_it_had_claimed_against_the_promise(): void
    {
        $this->setting('collaborator.commission_approval_mode', 'manual');

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');
        $payment = $this->receive($fee, '40000.00')->payment;

        $entry = $this->entryFor($payment);
        $this->assertSame(CommissionStatus::Pending, $entry?->status);

        $entitlement = $entry->entitlement;
        $this->assertSame('4000.00', (string) $entitlement?->released_amount);

        app(CommissionApprovalService::class)->reject($entry, 'The student was not referred by this partner', $this->createSuperAdmin());

        $this->assertSame('0.00', (string) $entitlement->refresh()->released_amount,
            'A cancelled commission was never released, so the promise has its headroom back.');
        $this->assertSame('0.00', (string) $entitlement->collected_amount);
        $this->assertSame('0.00', (string) $entitlement->reversed_amount,
            'And nothing claims to have been reversed: a rejection writes no debit.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** Whatever the reconciler reports, it never writes to the ledger. */
    #[Test]
    public function a_run_is_a_pure_reader_of_everything_that_holds_money(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $before = [
            'entries' => DB::table('collaborator_commission_ledger_entries')->orderBy('id')->get()->toJson(),
            'entitlements' => DB::table('collaborator_commission_entitlements')->orderBy('id')->get()->toJson(),
        ];

        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['available_balance' => '1.00']);

        app(CommissionReconciliationService::class)->run($partner, 'manual', repair: true);

        $this->assertSame($before['entries'],
            DB::table('collaborator_commission_ledger_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($before['entitlements'],
            DB::table('collaborator_commission_entitlements')->orderBy('id')->get()->toJson());
    }

    /** The command is the same code path, and says what it found. */
    #[Test]
    public function the_scheduled_command_reports_drift_without_repairing_it(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['available_balance' => '9999.00']);

        $this->artisan('collaborators:reconcile-wallets --run-type=scheduled')
            ->assertSuccessful();

        $this->assertSame('9999.00', (string) $this->walletOf($partner)?->available_balance,
            'The scheduled run reports; it does not quietly rewrite the number and hide the cause.');

        $this->artisan('collaborators:reconcile-wallets --repair --run-type=manual')
            ->assertSuccessful();

        $this->assertSame('4000.00', (string) $this->walletOf($partner)?->available_balance);
    }

    /** A structural failure makes the command fail, because no schedule should swallow one. */
    #[Test]
    public function the_command_fails_when_the_ledger_disagrees_with_itself(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        DB::table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $partner->getKey())
            ->update(['reversed_amount' => '250.00']);

        $this->artisan('collaborators:reconcile-wallets --repair --run-type=manual')
            ->assertFailed();

        $row = CollaboratorWalletReconciliation::query()->latest('id')->firstOrFail();

        $this->assertSame(ReconciliationStatus::Failed, $row->status);
        $this->assertFalse((bool) $row->repaired,
            'A structural failure is never repaired, even when --repair was passed.');
    }

    /** The switch exists so the job can be turned off without editing the schedule. */
    #[Test]
    public function the_schedule_is_skipped_when_the_setting_is_off(): void
    {
        $this->setting('finance.wallet_reconcile_enabled', false);

        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        $this->artisan('collaborators:reconcile-wallets')->assertSuccessful();

        $this->assertSame(0, CollaboratorWalletReconciliation::query()->count());

        $this->artisan('collaborators:reconcile-wallets --force')->assertSuccessful();

        $this->assertGreaterThan(0, CollaboratorWalletReconciliation::query()->count(),
            '--force is somebody at a prompt who has already decided.');
    }

    /** Money::format is what the command prints, so the figure reads as money and not as a float. */
    #[Test]
    public function the_report_names_what_disagreed_rather_than_merely_that_something_did(): void
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '100000.00'), '40000.00');

        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['reserved_balance' => '77.00']);

        $report = app(CommissionReconciliationService::class)->check($partner);
        $details = $report->details();

        $this->assertSame('R1', $details['findings'][0]['check']);
        $this->assertArrayHasKey('reserved_balance', $details['findings'][0]['details']['columns']);
        $this->assertSame(
            ['stored' => '77.00', 'derived' => '0.00', 'difference' => '-77.00'],
            $details['findings'][0]['details']['columns']['reserved_balance'],
            'The drift screen links to the offending figures rather than sending somebody looking.',
        );

        $this->assertSame(Money::of('77.00'), Money::abs('-77.00'));
    }
}
