<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\Enums\PaymentMethod;
use App\Http\Requests\Admin\Finance\RecordFeePaymentRequest;
use App\Models\Institute\StudentFeePayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * The dialog that takes the money, and the three things that were broken around it.
 *
 * `admin/student-fees/show` has rendered a **"Collect payment"** button since it shipped, firing
 * `$dispatch('open-modal', 'collect-payment')`. Nothing listened: `grep -rn "collect-payment"
 * resources/` matched that one line. Everything behind it was finished — eight routes, a Form
 * Request, five ordered guards, a unique-indexed idempotency column, a BEFORE DELETE trigger, a
 * passing service test — and `admin.fee-payments.store` had zero callers outside `tests/`. You could
 * refund a receipt and void a receipt; you could not take one.
 *
 * That is why a student could not be activated at all through the admin panel:
 * `institute.require_fee_before_activation` defaults to `any_payment`, and no screen could produce
 * the payment it asks for.
 *
 * Two more defects on the same page, both silent:
 *
 *   - the **Refund** form posted `amount` and `reason` while `refund()` also requires `type` and
 *     `idempotency_key`. Neither field existed in the DOM, so the 422 came back keyed on fields the
 *     page does not render — the page reloaded and said nothing;
 *   - three `catch (UniqueConstraintViolationException)` blocks in `PaymentService` were **dead**,
 *     because the class was never imported and the bare name resolved inside
 *     `App\Services\Finance`. The sequential replay is caught earlier by the idempotency pre-read,
 *     which is what the existing tests exercise, so the suite passed and the hole stayed invisible.
 */
final class CollectPaymentDialogTest extends TestCase
{
    use BuildsFees;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function the_charge_screen_renders_a_dialog_for_the_button_that_opens_it(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $response = $this->actingAs($actor)->get(route('admin.student-fees.show', $charge));

        $response->assertOk();

        // The trigger, and something that answers it. Asserted together because the bug was that the
        // first existed without the second for the life of the screen.
        $response->assertSee("open-modal', 'collect-payment", false);
        $response->assertSee(route('admin.fee-payments.store', $charge), false);
        $response->assertSee('name="idempotency_key"', false);
    }

    #[Test]
    public function the_dialog_records_a_receipt_and_moves_the_money_onto_the_charge(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $this->actingAs($actor)
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '5000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertRedirect();

        $this->assertSame(1, StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count());

        $this->assertSame(
            0,
            Money::compare('5000.00', (string) $charge->refresh()->paid_amount),
            'The receipt was written but the charge cache did not follow.',
        );
    }

    /**
     * The guard the hidden field exists for.
     *
     * `RecordPaymentData::key()` substitutes a fresh ULID when the field is blank, so a dialog that
     * forgot to mint one would fail **open** — taking the money twice and writing two commission
     * ledger rows for one payment.
     */
    #[Test]
    public function the_same_idempotency_key_never_takes_the_money_twice(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);
        $key = (string) Str::ulid();

        $payload = [
            'amount' => '5000.00',
            'payment_method' => PaymentMethod::Cash->value,
            'paid_on' => now()->toDateString(),
            'idempotency_key' => $key,
        ];

        $this->actingAs($actor)->post(route('admin.fee-payments.store', $charge), $payload)->assertRedirect();
        $this->actingAs($actor)->post(route('admin.fee-payments.store', $charge), $payload)->assertRedirect();

        $this->assertSame(
            1,
            StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count(),
            'A replayed submit took the money a second time.',
        );

        $this->assertSame(0, Money::compare('5000.00', (string) $charge->refresh()->paid_amount));
    }

    #[Test]
    public function a_receipt_without_an_idempotency_key_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $this->actingAs($actor)
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '5000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('idempotency_key', null, RecordFeePaymentRequest::ERROR_BAG);

        $this->assertSame(0, StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count());
    }

    /**
     * Refusals land in the dialog's own bag, not in the page's shared one.
     *
     * `admin.student-fees.show` renders four money forms with a field called `amount` — this dialog,
     * the discount modal, a waive modal per open installment and a refund modal per receipt — and
     * `x-ui.form.input` reads its error state from the bag by field NAME alone. Without a bag of its
     * own, a refusal here lights up the amount field on three forms the operator never touched.
     */
    #[Test]
    public function a_refusal_lands_in_the_dialogs_own_error_bag(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $response = $this->actingAs($actor)->post(route('admin.fee-payments.store', $charge), [
            'amount' => '0',
            'payment_method' => PaymentMethod::Cash->value,
            'paid_on' => now()->toDateString(),
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $response->assertSessionHasErrors('amount', null, RecordFeePaymentRequest::ERROR_BAG);

        // And NOT in the shared bag, which is the whole point: `x-ui.form.input` reads its error state
        // from the bag by field name alone, so an `amount` error in `default` would light up the
        // discount modal and every waive and refund modal on the same page.
        $response->assertSessionDoesntHaveErrors('amount', null, 'default');
    }

    /** A receipt dated forward is not a receipt. */
    #[Test]
    public function a_future_dated_receipt_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $this->actingAs($actor)
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '1000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->addDay()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertSessionHasErrors('paid_on', null, RecordFeePaymentRequest::ERROR_BAG);

        $this->assertSame(0, StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count());
    }

    /**
     * Over-payment is legal, and the dialog must not be built as though it were not.
     *
     * The surplus lands on the charge as an advance — `deriveStatus()` has an `Overpaid` arm and the
     * collection screen has a tab for it. A `max` on the amount input tied to the balance would make
     * that state unreachable from the till.
     */
    #[Test]
    public function paying_more_than_the_balance_is_accepted_and_becomes_an_advance(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $over = Money::add((string) $charge->net_amount, '1000.00');

        $this->actingAs($actor)
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => $over,
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertRedirect();

        $this->assertTrue(
            Money::isNegative((string) $charge->refresh()->balance_amount),
            'An overpayment did not leave the charge in advance.',
        );
    }

    /**
     * A cashier who may take money but may not read the register still gets somewhere useful.
     *
     * `store()` redirected unconditionally to `admin.fee-payments.show`, which is gated on
     * `student_fee_payments.view`. A user holding only `.create` took the money and was then 403'd by
     * the redirect, with no way to tell whether the receipt had been written.
     */
    #[Test]
    public function a_cashier_without_the_register_permission_is_not_redirected_into_a_403(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $cashier = $this->createUserWithPermissions([
            'student_fees.view', 'student_fees.view_any', 'student_fee_payments.create',
        ]);

        // Back to the page the dialog is on -- not to the register, which they may not read, and not
        // to the charge screen by name either, which has a policy of its own that could refuse them.
        $this->actingAs($cashier)
            ->from(route('admin.student-fees.show', $charge))
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '1000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertRedirect(route('admin.student-fees.show', $charge));

        $this->assertSame(1, StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count());
    }

    #[Test]
    public function a_user_without_the_create_permission_cannot_take_money(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $outsider = $this->createUserWithPermissions(['student_fees.view', 'student_fees.view_any']);

        $this->actingAs($outsider)
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '1000.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
            ])
            ->assertForbidden();

        $this->assertSame(0, StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count());
    }

    /**
     * The refund form on the same page posts what the controller requires.
     *
     * It did not: `type` and `idempotency_key` were both required and both absent from the DOM, so
     * every click was a 422 keyed on fields the page does not render — a reload that said nothing.
     */
    #[Test]
    public function the_refund_form_posts_every_field_the_controller_requires(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $this->actingAs($actor)->post(route('admin.fee-payments.store', $charge), [
            'amount' => '5000.00',
            'payment_method' => PaymentMethod::Cash->value,
            'paid_on' => now()->toDateString(),
            'idempotency_key' => (string) Str::ulid(),
        ])->assertRedirect();

        $response = $this->actingAs($actor)->get(route('admin.student-fees.show', $charge->refresh()));

        $response->assertOk();
        $response->assertSee('name="type"', false);
        $response->assertSee('name="idempotency_key"', false);
    }
}
