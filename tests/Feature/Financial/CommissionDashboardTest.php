<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Dashboard\Widgets\Collaborator\CollaboratorLeaderboardWidget;
use App\Dashboard\Widgets\Collaborator\CommissionBySourceChartWidget;
use App\Dashboard\Widgets\Collaborator\CommissionPaidThisMonthWidget;
use App\Dashboard\Widgets\Collaborator\CommissionPendingApprovalWidget;
use App\Dashboard\Widgets\Collaborator\PayoutQueueWidget;
use App\Dashboard\Widgets\Collaborator\WalletDriftWidget;
use App\Dashboard\Widgets\Collaborator\WalletLiabilityWidget;
use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\DataObjects\Finance\RefundData;
use App\Enums\LedgerEntryPurpose;
use App\Enums\PayoutMethod;
use App\Enums\ReversalType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Services\Collaborator\PayoutService;
use App\Services\Finance\PaymentService;
use App\Support\DashboardRegistry;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The eight §8.12 widgets, and the discrepancy queue (phase-10-12 §8.8, §8.12, §11.6).
 *
 * **The point of every assertion here is INV-26.** A widget must produce the figure the service
 * produces, not a figure of its own that happens to agree today — so each one is checked against the
 * service call it is supposed to be reading through. A widget that drifted from its service would be a
 * second definition of a balance, which is the one thing this module is built to prevent.
 */
final class CommissionDashboardTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private DateRange $range;

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
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.payout_reference_required', true);

        // A window wide enough to hold the fixtures and today's payouts, without tripping the
        // custom-range width cap.
        $this->range = DateRange::custom(now()->subMonths(6)->toDateString(), now()->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Every widget reads through its service
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_paid_out_widget_equals_the_wallet_services_figure(): void
    {
        $partner = $this->earner();
        $this->payAndSettle($partner, '2500.00');

        $data = app(CommissionPaidThisMonthWidget::class)->data($this->range);

        $this->assertTrue($data['available']);
        $this->assertSame(
            app(CollaboratorWalletService::class)->payoutsPaidTotal(null, $this->range),
            $data['current'],
            'INV-26: the widget reads the service, it does not sum the payouts table itself.',
        );
        $this->assertSame('2500.00', $data['current']);
    }

    #[Test]
    public function the_liability_widget_equals_the_sum_of_the_partner_balances(): void
    {
        $first = $this->earner();
        $second = $this->earner('5.0000');

        $data = app(WalletLiabilityWidget::class)->data($this->range);

        $wallets = app(CollaboratorWalletService::class);

        $expected = Money::sum(
            $wallets->derive($first)->bucketSum(),
            $wallets->derive($second)->bucketSum(),
        );

        $this->assertSame($expected, $data['owed'],
            'The company figure is one query, so it equals the sum of the per-partner figures rather '
            .'than merely resembling it.');

        $this->assertSame('6000.00', $data['owed']);
        $this->assertSame('0.00', $data['reserved']);
    }

    /** The approval queue is about *now*, so it deliberately ignores the range. */
    #[Test]
    public function the_pending_widget_counts_what_is_waiting_regardless_of_the_range(): void
    {
        $this->setting('collaborator.commission_approval_mode', 'manual');

        $partner = $this->partner('10.0000');
        $this->receive($this->charge($partner, '200000.00'), '40000.00', ['on' => '2026-02-10']);

        // A range that contains none of it.
        $data = app(CommissionPendingApprovalWidget::class)
            ->data(DateRange::custom('2020-01-01', '2020-01-31'));

        $this->assertSame(1, $data['count'], 'A queue is about what is waiting, not about a window.');
        $this->assertSame('4000.00', $data['total']);
        $this->assertNotNull($data['oldest']);
    }

    #[Test]
    public function the_payout_queue_splits_approving_from_paying(): void
    {
        $partner = $this->earner();
        $actor = $this->createSuperAdmin();
        $payouts = app(PayoutService::class);

        $toApprove = $payouts->createFor($partner, new PayoutRequestData(
            requestedAmount: '1000.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $second = $this->earner('5.0000');

        $toPay = $payouts->createFor($second, new PayoutRequestData(
            requestedAmount: '1500.00', method: PayoutMethod::BankTransfer,
        ), $actor);

        $payouts->approve($toPay, $actor);

        $data = app(PayoutQueueWidget::class)->data($this->range);

        $this->assertSame(1, $data['to_approve']['count']);
        $this->assertSame('1000.00', $data['to_approve']['total']);
        $this->assertSame(1, $data['to_pay']['count']);
        $this->assertSame('1500.00', $data['to_pay']['total']);
        $this->assertSame('2500.00', $data['total']);

        $this->assertSame($toApprove->getKey(), $toApprove->refresh()->getKey());
    }

    #[Test]
    public function the_source_chart_slices_add_up_to_the_published_total(): void
    {
        $partner = $this->earner();

        $payment = StudentFeePayment::query()
            ->where('collaborator_id', $partner->getKey())
            ->firstOrFail();

        app(PaymentService::class)->refund($payment, new RefundData(
            amount: '10000.00',
            reason: 'Partial refund',
            type: ReversalType::PartialRefund,
        ));

        $data = app(CommissionBySourceChartWidget::class)->data($this->range);
        $statements = app(CollaboratorStatementService::class);

        $this->assertTrue($data['available']);
        $this->assertSame($statements->commissionAccruedTotal(null, $this->range), $data['net']);
        $this->assertSame('3000.00', $data['net'], '4,000 earned less 1,000 returned.');

        // The reversal is its own slice, not netted into the source it came from: a month with more
        // earned and more refunded is a different month from a quieter one with the same net.
        $labels = array_column($data['slices'], 'label');

        $this->assertContains(LedgerEntryPurpose::StudentCommission->label(), $labels);
        $this->assertContains(LedgerEntryPurpose::Reversal->label(), $labels);
    }

    #[Test]
    public function the_leaderboard_ranks_by_the_same_arithmetic_a_statement_balances_to(): void
    {
        $small = $this->earner('5.0000');
        $large = $this->earner('10.0000');

        $data = app(CollaboratorLeaderboardWidget::class)->data($this->range);

        $this->assertCount(2, $data['leaders']);
        $this->assertSame(1, $data['leaders'][0]['position']);
        $this->assertSame('4000.00', $data['leaders'][0]['total']);
        $this->assertSame('2000.00', $data['leaders'][1]['total']);
        $this->assertSame(100.0, $data['leaders'][0]['share']);

        $statement = app(CollaboratorStatementService::class)->build($large, $this->range);

        $this->assertSame($statement->credits, $data['leaders'][0]['total'],
            'A partner’s place on the board and the credits on their own statement are one figure.');

        $this->assertNotSame($small->getKey(), $large->getKey());
    }

    #[Test]
    public function the_drift_widget_is_silent_when_every_wallet_matches(): void
    {
        $this->earner();

        $data = app(WalletDriftWidget::class)->data($this->range);

        $this->assertSame(0, $data['count']);
        $this->assertNull($data['last_run'], 'It says nothing has been checked rather than implying all is well.');

        $this->artisan('collaborators:reconcile-wallets --run-type=manual')->assertSuccessful();

        $this->assertNotNull(app(WalletDriftWidget::class)->data($this->range)['last_run']);
    }

    /** Phase 2's gating applies unchanged: each widget declares its module and its permission. */
    #[Test]
    public function every_widget_declares_a_module_and_a_permission(): void
    {
        DashboardRegistry::flushCache();

        $mine = DashboardRegistry::all()
            ->filter(fn ($widget): bool => str_contains($widget::class, 'Widgets\\Collaborator'));

        $this->assertCount(8, $mine, 'The eight widgets of §8.12.');

        foreach ($mine as $widget) {
            $this->assertNotNull($widget->module(), $widget->key().' must name the module that closes it.');
            $this->assertNotNull($widget->permission(), $widget->key().' must name the permission that gates it.');
            $this->assertTrue(view()->exists($widget->view()), $widget->key().' has no view at '.$widget->view());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The discrepancy queue (§8.8)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function accepting_a_discrepancy_moves_no_money_and_needs_a_reason(): void
    {
        $partner = $this->earner();

        $entitlement = CollaboratorCommissionEntitlement::query()
            ->where('collaborator_id', $partner->getKey())
            ->firstOrFail();

        // A post-payment discount would leave exactly this: a promise now worth less than it released.
        DB::table('collaborator_commission_entitlements')
            ->where('id', $entitlement->getKey())
            ->update(['over_released_amount' => '750.00']);

        $reader = $this->createUserWithPermissions(['collaborator_commissions.view_any']);

        $this->actingAs($reader)
            ->get(route('admin.commission-discrepancies.index'))
            ->assertOk()
            ->assertSee(Money::format('750.00'));

        $this->actingAs($reader)
            ->post(route('admin.commission-discrepancies.accept', $entitlement))
            ->assertForbidden();

        $approver = $this->createUserWithPermissions([
            'collaborator_commissions.view_any', 'collaborator_commissions.approve',
        ]);

        $this->actingAs($approver)
            ->from(route('admin.commission-discrepancies.index'))
            ->post(route('admin.commission-discrepancies.accept', $entitlement), ['reason' => 'too short'])
            ->assertSessionHasErrors('reason');

        $ledgerBefore = CollaboratorCommissionLedgerEntry::query()->count();

        $this->actingAs($approver)
            ->post(route('admin.commission-discrepancies.accept', $entitlement), [
                'reason' => 'Discount agreed with the partner before it was applied',
            ])
            ->assertRedirect();

        $this->assertNotNull($entitlement->refresh()->closed_on);
        $this->assertSame('750.00', (string) $entitlement->over_released_amount,
            'The figure stays: it is a fact about what happened, and `closed_on` is what takes the row off the list.');
        $this->assertSame($ledgerBefore, CollaboratorCommissionLedgerEntry::query()->count(),
            'Accepting writes a note, never a money row.');

        // Off the open queue. The toast still names the figure that was accepted, which is why this
        // asserts the empty state rather than the absence of a number the page is right to mention.
        $this->actingAs($reader)
            ->get(route('admin.commission-discrepancies.index'))
            ->assertOk()
            ->assertSee('No discrepancies');

        // And still findable, because "what did we decide about this one" is a real question.
        $this->actingAs($reader)
            ->get(route('admin.commission-discrepancies.index', ['resolved' => 1]))
            ->assertOk()
            ->assertSee(Money::format('750.00'))
            ->assertSee('Discount agreed with the partner');

        $this->assertWalletMatchesLedger($partner);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function earner(string $rate = '10.0000'): Collaborator
    {
        $partner = $this->partner($rate);
        $this->receive($this->charge($partner, '200000.00'), '40000.00', ['on' => now()->subMonth()->toDateString()]);

        return $partner;
    }

    private function payAndSettle(Collaborator $partner, string $amount): void
    {
        $actor = $this->createSuperAdmin();
        $payouts = app(PayoutService::class);

        $payout = $payouts->createFor($partner, new PayoutRequestData(
            requestedAmount: $amount, method: PayoutMethod::BankTransfer,
        ), $actor);

        $payouts->approve($payout, $actor);
        $payouts->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-DASH-'.$partner->getKey()), $actor);
    }
}
