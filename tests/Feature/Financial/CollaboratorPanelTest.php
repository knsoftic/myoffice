<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\PayoutRequestData;
use App\Enums\PanelType;
use App\Enums\PayoutMethod;
use App\Enums\PayoutStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Collaborator\CollaboratorPayoutAccount;
use App\Models\User;
use App\Services\Collaborator\PayoutService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The collaborator panel's money screens (phase-10-12 §7.5, §8.10, §11.6).
 *
 * **Data isolation is the whole test file.** Every query in this panel is scoped through the session,
 * so the thing worth proving is that a second partner exists and is invisible: not merely absent from
 * a list, but a **404** on a direct id — telling somebody "that payout exists but is not yours" leaks
 * that it exists, and an id walk would reveal how many payouts the business makes.
 *
 * The second thing proved is that a partner cannot reach a staff decision. There is no approve route
 * in this panel, and withdrawing stops being theirs the moment somebody has acted on the request.
 */
final class CollaboratorPanelTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Every ability this panel declares for money. */
    private const PORTAL = [
        'collaborator_portal.dashboard',
        'collaborator_portal.wallet',
        'collaborator_portal.payouts',
        'collaborator_portal.payout_request',
        'collaborator_portal.statement_download',
        'collaborator_portal.student_commission',
        'collaborator_portal.projects',
    ];

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
    | What a partner sees
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_wallet_shows_the_derived_balance_and_explains_the_buckets(): void
    {
        [$partner, $user] = $this->partnerWithLogin();

        $this->actingAs($user)
            ->get(route('collaborator.wallet.index'))
            ->assertOk()
            ->assertSee(Money::format('4000.00'))
            // The buckets are explained in words: "pending" and "available" are the same money to the
            // person waiting for it, and the difference is most of what this screen exists to prevent.
            ->assertSee('Waiting for approval')
            ->assertSee('Available');
    }

    #[Test]
    public function the_commission_list_and_statement_render(): void
    {
        [$partner, $user] = $this->partnerWithLogin();

        $entry = CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $partner->getKey())
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('collaborator.commissions.index', ['from' => '2026-01-01', 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee($entry->reference);

        $this->actingAs($user)
            ->get(route('collaborator.commissions.show', $entry))
            ->assertOk()
            ->assertSee('How this was worked out');

        $this->actingAs($user)
            ->get(route('collaborator.statement.index', ['from' => '2026-01-01', 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('It balances:');
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation
    |--------------------------------------------------------------------------
    */

    /** Another partner's commission is a 404, not a 403. */
    #[Test]
    public function one_partners_commission_is_invisible_to_another(): void
    {
        [, $user] = $this->partnerWithLogin();
        [$stranger] = $this->partnerWithLogin('7.5000');

        $theirs = CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $stranger->getKey())
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('collaborator.commissions.show', $theirs))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('collaborator.commissions.index', ['from' => '2026-01-01', 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertDontSee($theirs->reference);
    }

    #[Test]
    public function one_partners_payout_is_invisible_to_another(): void
    {
        [, $user] = $this->partnerWithLogin();
        [$stranger] = $this->partnerWithLogin('7.5000');

        $theirs = app(PayoutService::class)->createFor($stranger, new PayoutRequestData(
            requestedAmount: '1000.00', method: PayoutMethod::BankTransfer,
        ), $this->createSuperAdmin());

        $this->actingAs($user)->get(route('collaborator.payouts.show', $theirs))->assertNotFound();
        $this->actingAs($user)->post(route('collaborator.payouts.cancel', $theirs), [
            'reason' => 'Trying my luck here',
        ])->assertNotFound();

        $this->assertSame(PayoutStatus::Pending, $theirs->refresh()->status);
    }

    /** A destination that belongs to somebody else cannot be named in a request. */
    #[Test]
    public function a_partner_cannot_send_their_payout_to_another_partners_account(): void
    {
        [, $user] = $this->partnerWithLogin();
        [$stranger] = $this->partnerWithLogin('7.5000');

        $theirAccount = $this->account($stranger);

        $this->actingAs($user)
            ->from(route('collaborator.payouts.create'))
            ->post(route('collaborator.payouts.store'), [
                'amount' => '1000.00',
                'method' => PayoutMethod::BankTransfer->value,
                'payout_account_id' => $theirAccount->getKey(),
            ])
            ->assertSessionHasErrors('payout_account_id');

        $this->assertSame(0, CollaboratorPayout::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Asking to be paid
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_partner_can_ask_to_be_paid_and_withdraw_it_again(): void
    {
        [$partner, $user] = $this->partnerWithLogin();
        $account = $this->account($partner);

        $this->actingAs($user)->get(route('collaborator.payouts.create'))->assertOk();

        $this->actingAs($user)
            ->post(route('collaborator.payouts.store'), [
                'amount' => '2,500.00',
                'method' => PayoutMethod::BankTransfer->value,
                'payout_account_id' => $account->getKey(),
            ])
            ->assertRedirect();

        $payout = CollaboratorPayout::query()->latest('id')->firstOrFail();

        $this->assertSame(PayoutStatus::Requested, $payout->status,
            'Asking is not approving: there is no approve route in this panel at all.');
        $this->assertSame('2500.00', (string) $payout->amount);
        $this->assertSame('2500.00', (string) $this->walletOf($partner)?->reserved_balance);

        $this->actingAs($user)->get(route('collaborator.payouts.show', $payout))
            ->assertOk()
            ->assertSee('What this covers');

        $this->actingAs($user)
            ->post(route('collaborator.payouts.cancel', $payout), ['reason' => 'Asked for the wrong amount'])
            ->assertRedirect(route('collaborator.payouts.index'));

        $this->assertSame(PayoutStatus::Cancelled, $payout->refresh()->status);
        $this->assertSame('4000.00', (string) $this->walletOf($partner)?->available_balance);
        $this->assertWalletMatchesLedger($partner);
    }

    /** Once staff have acted, withdrawing is no longer the partner's to do. */
    #[Test]
    public function an_approved_payout_can_no_longer_be_withdrawn_by_the_partner(): void
    {
        [$partner, $user] = $this->partnerWithLogin();

        $payout = app(PayoutService::class)->createFor($partner, new PayoutRequestData(
            requestedAmount: '2500.00', method: PayoutMethod::BankTransfer,
        ), $this->createSuperAdmin());

        app(PayoutService::class)->approve($payout, $this->createSuperAdmin());

        $this->actingAs($user)
            ->from(route('collaborator.payouts.show', $payout))
            ->post(route('collaborator.payouts.cancel', $payout->refresh()), ['reason' => 'Changed my mind'])
            ->assertRedirect()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error');

        $this->assertSame(PayoutStatus::Approved, $payout->refresh()->status);
    }

    /** The switch that closes the request path without closing the panel. */
    #[Test]
    public function requests_are_refused_when_the_business_turns_them_off(): void
    {
        $this->setting('collaborator.payout_request_enabled', false);

        [$partner, $user] = $this->partnerWithLogin();

        $this->actingAs($user)
            ->post(route('collaborator.payouts.store'), [
                'amount' => '1000.00',
                'method' => PayoutMethod::BankTransfer->value,
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, CollaboratorPayout::query()->count());

        // The register itself stays open: a partner who may not ask still has a history.
        $this->actingAs($user)->get(route('collaborator.payouts.index'))->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Destinations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_account_a_partner_adds_is_never_self_verified(): void
    {
        [$partner, $user] = $this->partnerWithLogin();

        $this->actingAs($user)
            ->post(route('collaborator.payout-accounts.store'), [
                'label' => 'Main account',
                'method' => PayoutMethod::BankTransfer->value,
                'account_title' => 'A Partner',
                'bank_name' => 'Habib Bank',
                'account_number' => 'PK36 HABB 0000 0011 2345 6702',
            ])
            ->assertRedirect();

        $account = CollaboratorPayoutAccount::query()->latest('id')->firstOrFail();

        $this->assertFalse((bool) $account->is_verified,
            '§55: the partner supplies the destination, somebody else checks it.');
        $this->assertTrue((bool) $account->is_default, 'The first account becomes the default.');
        $this->assertSame('6702', $account->account_last4);

        $this->actingAs($user)
            ->get(route('collaborator.payout-accounts.index'))
            ->assertOk()
            ->assertSee('6702')
            ->assertDontSee('PK36HABB0000001123456702')
            ->assertSee('Waiting to be checked');
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function each_screen_needs_its_own_portal_permission(): void
    {
        [, $user] = $this->partnerWithLogin(permissions: ['collaborator_portal.dashboard']);

        foreach ([
            route('collaborator.wallet.index'),
            route('collaborator.statement.index'),
            route('collaborator.payouts.index'),
            route('collaborator.payouts.create'),
            route('collaborator.payout-accounts.index'),
            route('collaborator.projects.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /** Seeing a payout history is not the same right as asking for more. */
    #[Test]
    public function reading_payouts_and_requesting_one_are_separate_rights(): void
    {
        [$partner, $user] = $this->partnerWithLogin(permissions: [
            'collaborator_portal.dashboard', 'collaborator_portal.payouts',
        ]);

        $this->actingAs($user)->get(route('collaborator.payouts.index'))->assertOk();
        $this->actingAs($user)->get(route('collaborator.payouts.create'))->assertForbidden();
        $this->actingAs($user)->post(route('collaborator.payouts.store'), [
            'amount' => '100.00', 'method' => PayoutMethod::BankTransfer->value,
        ])->assertForbidden();

        $this->assertSame(0, CollaboratorPayout::query()->count());
    }

    /** An account with no partner row behind it is a 404, not a crash. */
    #[Test]
    public function a_login_with_no_collaborator_profile_gets_a_404(): void
    {
        $user = $this->createUserWithPermissions(self::PORTAL, PanelType::Collaborator);

        $this->actingAs($user)->get(route('collaborator.wallet.index'))->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * A partner with 4,000.00 available and a login that can reach this panel.
     *
     * @param  list<string>|null  $permissions
     * @return array{0: Collaborator, 1: User}
     */
    private function partnerWithLogin(string $rate = '10.0000', ?array $permissions = null): array
    {
        $partner = $this->partner($rate);
        $this->receive($this->charge($partner, '200000.00'), '40000.00', ['on' => '2026-02-10']);

        $user = $this->createUserWithPermissions(
            $permissions ?? self::PORTAL,
            PanelType::Collaborator,
        );

        DB::table('collaborators')->where('id', $partner->getKey())->update(['user_id' => $user->getKey()]);

        return [$partner->refresh(), $user];
    }

    private function account(Collaborator $collaborator): CollaboratorPayoutAccount
    {
        $row = new CollaboratorPayoutAccount;

        $row->forceFill([
            'collaborator_id' => $collaborator->getKey(),
            'label' => 'Bank',
            'method' => PayoutMethod::BankTransfer->value,
            'account_title' => 'A Partner',
            'bank_name' => 'Habib Bank',
            'details_encrypted' => ['account_number' => '0000001123456702'],
            'account_last4' => '6702',
            'is_default' => true,
            'is_verified' => true,
            'status' => 'active',
        ])->save();

        return $row->refresh();
    }
}
