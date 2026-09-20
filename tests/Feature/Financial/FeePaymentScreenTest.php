<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Enums\CommissionStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReversalType;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Institute\StudentFeePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The money routes, through the real HTTP kernel (phase-10-12 §7.1, §8.1, §8.2, §11.6).
 *
 * The engine is proved elsewhere. What this file proves is that the **screens** cannot be used to
 * get around it: no edit route exists, a double submit takes the money once, a refund needs the
 * reversal permission rather than the receipt's, and a refused request leaves nothing behind.
 */
final class FeePaymentScreenTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'manual');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.refund_approval_required', false);
    }

    #[Test]
    public function the_register_needs_its_permission(): void
    {
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('admin.fee-payments.index'))
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions(['student_fee_payments.view_any']))
            ->get(route('admin.fee-payments.index'))
            ->assertOk();
    }

    /** §8.1 — the idempotency key is the whole of the double-post guard. */
    #[Test]
    public function a_double_submit_takes_the_money_once(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');
        $cashier = $this->createUserWithPermissions([
            'student_fee_payments.view_any', 'student_fee_payments.view', 'student_fee_payments.create',
        ]);

        $payload = [
            'amount' => '10000.00',
            'payment_method' => PaymentMethod::Cash->value,
            'paid_on' => '2026-03-10',
            'idempotency_key' => (string) Str::ulid(),
            'confirm_duplicate' => true,
        ];

        $this->actingAs($cashier)
            ->post(route('admin.fee-payments.store', $fee), $payload)
            ->assertRedirect();

        // The same form, submitted again — a wedged client, a double click, a retried request.
        $this->actingAs($cashier)
            ->post(route('admin.fee-payments.store', $fee), $payload)
            ->assertRedirect();

        $this->assertSame(1, StudentFeePayment::query()->count(),
            'One key, one receipt. The second submit returns the first one.');

        $this->assertSame(1, CollaboratorCommissionLedgerEntry::query()->count());
        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function the_form_refuses_a_submission_with_no_idempotency_key(): void
    {
        $fee = $this->charge($this->partner('10.0000'));
        $cashier = $this->createUserWithPermissions(['student_fee_payments.create', 'student_fee_payments.view_any']);

        $this->actingAs($cashier)
            ->from(route('admin.fee-payments.index'))
            ->post(route('admin.fee-payments.store', $fee), [
                'amount' => '1000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => '2026-03-10',
            ])
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, StudentFeePayment::query()->count(),
            'Without the guard nothing is written — a retry would otherwise take the money twice.');
    }

    #[Test]
    public function a_future_dated_receipt_is_refused(): void
    {
        $fee = $this->charge($this->partner('10.0000'));
        $cashier = $this->createUserWithPermissions(['student_fee_payments.create', 'student_fee_payments.view_any']);

        $this->actingAs($cashier)
            ->from(route('admin.fee-payments.index'))
            ->post(route('admin.fee-payments.store', $fee), [
                'amount' => '1000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => Carbon::now()->addWeek()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertSessionHasErrors('paid_on');

        $this->assertSame(0, StudentFeePayment::query()->count());
    }

    /** §8.1 step 4 — and it writes nothing. */
    #[Test]
    public function the_preview_endpoint_shows_the_commission_and_writes_nothing(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');

        $response = $this->actingAs($this->createUserWithPermissions(['student_fee_payments.create']))
            ->getJson(route('admin.fee-payments.preview', $fee).'?amount=10000&paid_on=2026-03-10');

        $response->assertOk()
            ->assertJsonPath('earns', true)
            ->assertJsonPath('amount', '1000.00')
            ->assertJsonPath('collaborator.code', $partner->collaborator_code);

        $this->assertSame(0, StudentFeePayment::query()->count());
        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);
        $this->assertDatabaseCount('collaborator_commission_entitlements', 0);
    }

    #[Test]
    public function the_preview_names_the_reason_when_nothing_would_be_earned(): void
    {
        $fee = $this->charge(null, '30000.00');

        $this->actingAs($this->createUserWithPermissions(['student_fee_payments.create']))
            ->getJson(route('admin.fee-payments.preview', $fee).'?amount=10000&paid_on=2026-03-10')
            ->assertOk()
            ->assertJsonPath('earns', false)
            ->assertJsonPath('skip_reason', 'no_referral');
    }

    /** A refund is a `payment_reversals` row, so it carries that module's permission. */
    #[Test]
    public function refunding_needs_the_reversal_permission_not_the_receipts(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;

        $payload = [
            'amount' => '10000.00',
            'reason' => 'Student withdrew',
            'type' => ReversalType::FullRefund->value,
            'idempotency_key' => (string) Str::ulid(),
        ];

        $cashier = $this->createUserWithPermissions([
            'student_fee_payments.view_any', 'student_fee_payments.view', 'student_fee_payments.create',
        ]);

        $this->actingAs($cashier)
            ->post(route('admin.fee-payments.refund', $payment), $payload)
            ->assertForbidden();

        $this->assertSame('0.00', (string) $payment->refresh()->refunded_amount,
            'A refused request leaves the receipt exactly as it was.');

        $refunder = $this->createUserWithPermissions([
            'student_fee_payments.view_any', 'student_fee_payments.view', 'payment_reversals.create',
        ]);

        $this->actingAs($refunder)
            ->from(route('admin.fee-payments.show', $payment))
            ->post(route('admin.fee-payments.refund', $payment), $payload)
            ->assertRedirect();

        $this->assertSame('10000.00', (string) $payment->refresh()->refunded_amount);
        $this->assertSame(ReceivedPaymentStatus::Refunded, $payment->status);

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_refund_needs_a_reason(): void
    {
        $fee = $this->charge($this->partner('10.0000'));
        $payment = $this->receive($fee, '10000.00')->payment;

        $this->actingAs($this->createUserWithPermissions([
            'student_fee_payments.view_any', 'student_fee_payments.view', 'payment_reversals.create',
        ]))
            ->from(route('admin.fee-payments.show', $payment))
            ->post(route('admin.fee-payments.refund', $payment), [
                'amount' => '1000.00',
                'type' => ReversalType::PartialRefund->value,
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame('0.00', (string) $payment->refresh()->refunded_amount);
    }

    /** INV-8 — voiding is the only correction, and it needs a reason. */
    #[Test]
    public function voiding_is_the_correction_path_and_needs_a_reason(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '4000.00')->payment;

        $actor = $this->createUserWithPermissions([
            'student_fee_payments.view_any', 'student_fee_payments.view', 'student_fee_payments.change_status',
        ]);

        $this->actingAs($actor)
            ->from(route('admin.fee-payments.show', $payment))
            ->post(route('admin.fee-payments.void', $payment), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ReceivedPaymentStatus::Cleared, $payment->refresh()->status);

        $this->actingAs($actor)
            ->from(route('admin.fee-payments.show', $payment))
            ->post(route('admin.fee-payments.void', $payment), ['reason' => 'Keyed against the wrong student'])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(ReceivedPaymentStatus::Voided, $payment->status);
        $this->assertSame('4000.00', (string) $payment->amount, 'The receipt still says what it always said.');

        $entry = CollaboratorCommissionLedgerEntry::query()->earnings()->first();
        $this->assertSame(CommissionStatus::Reversed, $entry->refresh()->status,
            'Voiding the receipt undoes the commission it earned.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** INV-8 — there is no route that could edit or delete a receipt. */
    #[Test]
    public function no_route_can_edit_or_delete_a_receipt(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'admin.fee-payments.'))
            ->values()
            ->all();

        foreach (['edit', 'update', 'destroy'] as $forbidden) {
            $this->assertNotContains('admin.fee-payments.'.$forbidden, $names,
                sprintf('A receipt has no %s route, and never will (INV-8).', $forbidden));
        }

        $this->assertContains('admin.fee-payments.void', $names);
        $this->assertContains('admin.fee-payments.refund', $names);
    }

    #[Test]
    public function the_register_totals_describe_the_filtered_set(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '50000.00');

        $this->receive($fee, '10000.00', ['on' => '2026-03-10']);
        $this->receive($fee, '5000.00', ['on' => '2026-03-11']);

        $reader = $this->createUserWithPermissions(['student_fee_payments.view_any']);

        $this->actingAs($reader)
            ->get(route('admin.fee-payments.index', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk()
            ->assertSee('2 receipts')
            ->assertSeeText('Rs 15,000.00');

        // A range that excludes one of them changes the total, not just the rows.
        $this->actingAs($reader)
            ->get(route('admin.fee-payments.index', ['from' => '2026-03-11', 'to' => '2026-03-31']))
            ->assertOk()
            ->assertSee('1 receipt')
            ->assertSeeText('Rs 5,000.00');
    }
}
