<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Enums\ReconciliationStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorWalletReconciliation;
use App\Services\Collaborator\PayoutService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The wallet, payout, reconciliation and statement screens (phase-10-12 §7.4, §8.5–§8.9, §11.6).
 *
 * Two things are proved here that the service tests cannot. **Every route is gated on the backend** —
 * hiding a button is not security, so each screen is opened twice, once by somebody holding nothing.
 * And **the four presenters of a statement agree to the paisa** (FT-44, [D-IMP-7]): screen, print, PDF
 * and CSV all render one `StatementData`, so the only way they could disagree is if one of them
 * recomputed, which is exactly what the shared object exists to prevent.
 */
final class WalletScreenTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_screen_is_gated_on_the_backend(): void
    {
        $partner = $this->earner();
        $nobody = $this->createUserWithPermissions([]);

        foreach ([
            route('admin.wallets.index'),
            route('admin.wallets.show', $partner),
            route('admin.wallet-reconciliations.index'),
            route('admin.payouts.index'),
            route('admin.payouts.create'),
            route('admin.statements.show', $partner),
        ] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function the_wallet_screens_render_for_somebody_who_may_see_them(): void
    {
        $partner = $this->earner();

        $reader = $this->createUserWithPermissions([
            'collaborator_wallets.view_any', 'collaborator_wallets.view',
        ]);

        $this->actingAs($reader)->get(route('admin.wallets.index'))
            ->assertOk()
            ->assertSee($partner->collaborator_code);

        $this->actingAs($reader)->get(route('admin.wallets.show', $partner))
            ->assertOk()
            // The derivation is the point of the screen: it shows the ledger's figure, not only the cache.
            ->assertSee('The derivation')
            ->assertSee(Money::format('4000.00'));
    }

    /** A recalculate button that anybody could press would be a repair with no accountability. */
    #[Test]
    public function recalculating_needs_the_reconciliation_permission(): void
    {
        $partner = $this->earner();

        DB::table('collaborator_wallets')
            ->where('collaborator_id', $partner->getKey())
            ->update(['available_balance' => '1.00']);

        $reader = $this->createUserWithPermissions(['collaborator_wallets.view']);

        $this->actingAs($reader)
            ->post(route('admin.wallets.recalculate', $partner))
            ->assertForbidden();

        $this->assertSame('1.00', (string) $this->walletOf($partner)?->available_balance);

        $repairer = $this->createUserWithPermissions([
            'collaborator_wallets.view', 'wallet_reconciliation.change_status',
        ]);

        $this->actingAs($repairer)
            ->post(route('admin.wallets.recalculate', $partner))
            ->assertRedirect();

        $this->assertSame('4000.00', (string) $this->walletOf($partner)?->available_balance);
    }

    /** §6.5.4: a structural failure is not repairable by any button on any screen. */
    #[Test]
    public function recalculating_refuses_when_the_ledger_disagrees_with_itself(): void
    {
        $partner = $this->earner();

        DB::table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $partner->getKey())
            ->update(['reversed_amount' => '100.00']);

        $actor = $this->createUserWithPermissions([
            'collaborator_wallets.view', 'wallet_reconciliation.change_status',
        ]);

        $this->actingAs($actor)
            ->post(route('admin.wallets.recalculate', $partner))
            ->assertRedirect()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error'
                && str_contains($toast['message'], 'R6'));
    }

    /** Freezing stops payment, not earning — and it is refused without a reason. */
    #[Test]
    public function freezing_a_wallet_demands_a_reason(): void
    {
        $partner = $this->earner();

        $actor = $this->createUserWithPermissions([
            'collaborator_wallets.view', 'collaborator_payouts.change_status',
        ]);

        $this->actingAs($actor)
            ->from(route('admin.wallets.show', $partner))
            ->post(route('admin.wallets.freeze', $partner), ['frozen' => 1])
            ->assertSessionHasErrors('reason');

        $this->assertFalse((bool) $this->walletOf($partner)?->is_frozen);

        $this->actingAs($actor)
            ->post(route('admin.wallets.freeze', $partner), [
                'frozen' => 1,
                'reason' => 'Under review after a disputed referral',
            ])
            ->assertRedirect();

        $wallet = $this->walletOf($partner);

        $this->assertTrue((bool) $wallet?->is_frozen);
        $this->assertStringContainsString('disputed referral', (string) $wallet?->frozen_reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_reconciliation_screens_render_and_the_run_is_permissioned(): void
    {
        $partner = $this->earner();

        $reader = $this->createUserWithPermissions([
            'wallet_reconciliation.view_any', 'wallet_reconciliation.view',
        ]);

        $this->actingAs($reader)
            ->post(route('admin.wallet-reconciliations.run'))
            ->assertForbidden();

        $this->assertSame(0, CollaboratorWalletReconciliation::query()->count());

        $runner = $this->createUserWithPermissions([
            'wallet_reconciliation.view_any', 'wallet_reconciliation.view', 'wallet_reconciliation.change_status',
        ]);

        $this->actingAs($runner)
            ->post(route('admin.wallet-reconciliations.run'), ['collaborator_id' => $partner->getKey()])
            ->assertRedirect()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success');

        $row = CollaboratorWalletReconciliation::query()->latest('id')->firstOrFail();

        $this->assertSame(ReconciliationStatus::Ok, $row->status);

        $this->actingAs($reader)->get(route('admin.wallet-reconciliations.index'))->assertOk();
        $this->actingAs($reader)->get(route('admin.wallet-reconciliations.show', $row))
            ->assertOk()
            ->assertSee('Bucket by bucket');
    }

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_wizard_previews_an_allocation_without_claiming_anything(): void
    {
        $partner = $this->earner();

        $actor = $this->createUserWithPermissions(['collaborator_payouts.create']);

        $this->actingAs($actor)
            ->get(route('admin.payouts.plan', ['collaborator_id' => $partner->getKey(), 'amount' => '2500.00']))
            ->assertOk()
            ->assertJsonPath('satisfiable', true)
            ->assertJsonPath('allocatable', '2500.00')
            ->assertJsonPath('entry_count', 1);

        $this->assertSame('0.00',
            (string) CollaboratorCommissionLedgerEntry::query()->earnings()->first()?->allocated_amount,
            'A preview claims nothing: it is a GET for exactly that reason.');
    }

    #[Test]
    public function raising_a_payout_derives_its_amount_from_what_it_claims(): void
    {
        $partner = $this->earner();

        $actor = $this->createUserWithPermissions([
            'collaborator_payouts.create', 'collaborator_payouts.view', 'collaborator_payouts.view_any',
        ]);

        $this->actingAs($actor)
            ->post(route('admin.payouts.store'), [
                'collaborator_id' => $partner->getKey(),
                'amount' => '2,500.00',
                'method' => PayoutMethod::BankTransfer->value,
                'notes' => 'First settlement',
            ])
            ->assertRedirect();

        $payout = CollaboratorPayout::query()->latest('id')->firstOrFail();

        $this->assertSame('2500.00', (string) $payout->amount, 'The comma was stripped, not rejected.');
        $this->assertSame(1, $payout->entry_count);

        $this->actingAs($actor)->get(route('admin.payouts.index'))->assertOk()->assertSee($payout->payout_no);
        $this->actingAs($actor)->get(route('admin.payouts.show', $payout))
            ->assertOk()
            ->assertSee('What this settles');
    }

    /** A destination that belongs to somebody else is refused before any money moves. */
    #[Test]
    public function a_payout_cannot_be_sent_to_another_partners_account(): void
    {
        $partner = $this->earner();
        $stranger = $this->partner('5.0000');

        $account = DB::table('collaborator_payout_accounts')->insertGetId([
            'collaborator_id' => $stranger->getKey(),
            'label' => 'Their bank',
            'method' => PayoutMethod::BankTransfer->value,
            'account_title' => 'Somebody Else',
            'bank_name' => 'Other Bank',
            'details_encrypted' => 'x',
            'account_last4' => '9999',
            'is_default' => 1,
            'is_verified' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->createUserWithPermissions(['collaborator_payouts.create']);

        $this->actingAs($actor)
            ->from(route('admin.payouts.create'))
            ->post(route('admin.payouts.store'), [
                'collaborator_id' => $partner->getKey(),
                'amount' => '1000.00',
                'method' => PayoutMethod::BankTransfer->value,
                'payout_account_id' => $account,
            ])
            ->assertSessionHasErrors('payout_account_id');

        $this->assertSame(0, CollaboratorPayout::query()->count());
    }

    #[Test]
    public function marking_paid_needs_the_reference_when_the_setting_demands_one(): void
    {
        $partner = $this->earner();
        $payout = $this->payoutFor($partner, '2500.00');

        $approver = $this->createUserWithPermissions([
            'collaborator_payouts.view', 'collaborator_payouts.approve', 'collaborator_payouts.change_status',
        ]);

        $this->actingAs($approver)->post(route('admin.payouts.approve', $payout))->assertRedirect();

        $this->actingAs($approver)
            ->from(route('admin.payouts.show', $payout))
            ->post(route('admin.payouts.mark-paid', $payout), [])
            ->assertSessionHasErrors('transaction_id');

        $this->assertSame(PayoutStatus::Approved, $payout->refresh()->status);

        $this->actingAs($approver)
            ->post(route('admin.payouts.mark-paid', $payout), ['transaction_id' => 'BANK-SCREEN-1'])
            ->assertRedirect();

        $this->assertSame(PayoutStatus::Paid, $payout->refresh()->status);
        $this->assertWalletMatchesLedger($partner);
    }

    /** Money that has not left yet has not been paid. */
    #[Test]
    public function a_future_value_date_is_refused(): void
    {
        $partner = $this->earner();
        $payout = $this->payoutFor($partner, '2500.00');

        $actor = $this->createUserWithPermissions([
            'collaborator_payouts.view', 'collaborator_payouts.approve', 'collaborator_payouts.change_status',
        ]);

        $this->actingAs($actor)->post(route('admin.payouts.approve', $payout))->assertRedirect();

        $this->actingAs($actor)
            ->from(route('admin.payouts.show', $payout))
            ->post(route('admin.payouts.mark-paid', $payout), [
                'transaction_id' => 'BANK-FUTURE',
                'paid_on' => now()->addDays(3)->toDateString(),
            ])
            ->assertSessionHasErrors('paid_on');
    }

    /** Spine R-7: the one backward money transition needs both permissions and a reason. */
    #[Test]
    public function returning_a_settled_payout_needs_two_permissions_and_a_reason(): void
    {
        $partner = $this->earner();
        $payout = $this->payoutFor($partner, '2500.00');

        $admin = $this->createSuperAdmin();

        app(PayoutService::class)->approve($payout, $admin);
        app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-R7'), $admin);

        // `change_status` alone is not enough.
        $halfArmed = $this->createUserWithPermissions([
            'collaborator_payouts.view', 'collaborator_payouts.change_status',
        ]);

        $this->actingAs($halfArmed)
            ->post(route('admin.payouts.cancel-after-payment', $payout), ['reason' => 'The bank rejected it'])
            ->assertForbidden();

        $this->assertSame(PayoutStatus::Paid, $payout->refresh()->status);

        $armed = $this->createUserWithPermissions([
            'collaborator_payouts.view', 'collaborator_payouts.change_status', 'collaborator_payouts.approve',
        ]);

        $this->actingAs($armed)
            ->from(route('admin.payouts.show', $payout))
            ->post(route('admin.payouts.cancel-after-payment', $payout), ['reason' => 'short'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($armed)
            ->post(route('admin.payouts.cancel-after-payment', $payout), [
                'reason' => 'The bank rejected the transfer: the account number was wrong',
            ])
            ->assertRedirect();

        $this->assertSame(PayoutStatus::Cancelled, $payout->refresh()->status);
        $this->assertSame('4000.00', (string) $this->walletOf($partner)?->available_balance);
        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function the_voucher_needs_the_print_permission(): void
    {
        $partner = $this->earner();
        $payout = $this->payoutFor($partner, '2500.00');

        $this->actingAs($this->createUserWithPermissions(['collaborator_payouts.view']))
            ->get(route('admin.payouts.voucher', $payout))
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions([
            'collaborator_payouts.view', 'collaborator_payouts.print',
        ]))
            ->get(route('admin.payouts.voucher', $payout))
            ->assertOk()
            ->assertSee('Payout voucher')
            ->assertSee($payout->payout_no);
    }

    /*
    |--------------------------------------------------------------------------
    | Payout destinations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_destination_is_shown_masked_and_verified_by_somebody_else(): void
    {
        $partner = $this->earner();

        $accountId = DB::table('collaborator_payout_accounts')->insertGetId([
            'collaborator_id' => $partner->getKey(),
            'label' => 'Main account',
            'method' => PayoutMethod::BankTransfer->value,
            'account_title' => 'A Partner',
            'bank_name' => 'Habib Bank',
            'details_encrypted' => 'PK36HABB0000001123456702',
            'account_last4' => '6702',
            'is_default' => 1,
            'is_verified' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reader = $this->createUserWithPermissions(['collaborator_payouts.view']);

        $this->actingAs($reader)
            ->get(route('admin.payout-accounts.index', $partner))
            ->assertOk()
            ->assertSee('6702')
            ->assertDontSee('PK36HABB0000001123456702');

        $this->actingAs($reader)
            ->post(route('admin.payout-accounts.verify', $accountId))
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions([
            'collaborator_payouts.view', 'collaborator_payouts.approve',
        ]))
            ->post(route('admin.payout-accounts.verify', $accountId))
            ->assertRedirect();

        $this->assertSame(1, (int) DB::table('collaborator_payout_accounts')->where('id', $accountId)->value('is_verified'));
    }

    /*
    |--------------------------------------------------------------------------
    | The statement, four ways (FT-44)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_screen_the_print_view_and_the_csv_agree_to_the_paisa(): void
    {
        $partner = $this->earner();
        $payout = $this->payoutFor($partner, '1500.00');

        $admin = $this->createSuperAdmin();
        app(PayoutService::class)->approve($payout, $admin);
        app(PayoutService::class)->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-FT44'), $admin);

        $reader = $this->createUserWithPermissions([
            'collaborator_commissions.view_financial', 'collaborator_commissions.export',
        ]);

        // A real window: `DateRange::custom()` caps how wide a custom range may be, so a decade-long
        // one silently becomes its last N days and the statement would open after everything in it.
        $window = ['from' => '2026-01-01', 'to' => now()->toDateString()];

        $screen = $this->actingAs($reader)
            ->get(route('admin.statements.show', ['collaborator' => $partner] + $window))
            ->assertOk();

        $print = $this->actingAs($reader)
            ->get(route('admin.statements.export', ['collaborator' => $partner, 'format' => 'print'] + $window))
            ->assertOk();

        $csv = $this->actingAs($reader)
            ->get(route('admin.statements.export', ['collaborator' => $partner, 'format' => 'csv'] + $window));

        $csv->assertOk();

        // 4,000 earned, 1,500 paid out: the closing balance is 2,500 and every presenter says so.
        $closing = Money::format('2500.00');

        $screen->assertSee($closing);
        $print->assertSee($closing);

        $body = $csv->streamedContent();

        $this->assertStringContainsString('2500.00', $body);
        $this->assertStringContainsString('Closing balance', $body);
        $this->assertStringContainsString('opening', $body, 'The proof line travels with the export.');
        $this->assertStringContainsString($payout->payout_no, $body);
    }

    #[Test]
    public function exporting_a_statement_is_a_different_permission_from_reading_one(): void
    {
        $partner = $this->earner();

        $this->actingAs($this->createUserWithPermissions(['collaborator_commissions.view_financial']))
            ->get(route('admin.statements.export', ['collaborator' => $partner, 'format' => 'csv']))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /** A partner with exactly 4,000.00 available. */
    private function earner(): Collaborator
    {
        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '200000.00'), '40000.00', ['on' => '2026-02-10']);

        return $partner;
    }

    private function payoutFor(Collaborator $partner, string $amount): CollaboratorPayout
    {
        return app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: $amount,
            method: PayoutMethod::BankTransfer,
        ), $this->createSuperAdmin());
    }
}
