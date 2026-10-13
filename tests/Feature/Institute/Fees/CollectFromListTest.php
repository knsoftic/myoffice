<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Fees;

use App\Enums\PaymentMethod;
use App\Models\Institute\StudentFeePayment;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Fees\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * "Collect payment" on the student-fees list (`_quick-collect-modal.blade.php`).
 *
 * The row's button opens one dialog for the page that posts to the same `admin.fee-payments.store`
 * as the charge screen, so these tests cover only what the list adds: who sees the button, which
 * rows get it, that each charge carries its own action and idempotency key, and that a refusal comes
 * back to the list with the dialog reopened on the right charge.
 */
final class CollectFromListTest extends TestCase
{
    use BuildsFees;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function an_open_charge_gets_the_button_and_the_dialog_posts_to_the_existing_route(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $response = $this->actingAs($actor)->get(route('admin.student-fees.index'));

        $response->assertOk();
        $response->assertSee("name: 'quick-collect', fee: ".$charge->getKey(), false);
        $response->assertSee('name="idempotency_key"', false);

        $dialog = $this->dialogCharges($response->getContent());
        $this->assertSame(route('admin.fee-payments.store', $charge), $dialog[$charge->getKey()]['action']);
        // Prefilled with what is outstanding, like the charge screen.
        $this->assertSame(0, Money::compare((string) $charge->balance_amount, $dialog[$charge->getKey()]['fields']['amount']));

        // The charge screen's own button is untouched.
        $this->actingAs($actor)->get(route('admin.student-fees.show', $charge))
            ->assertSee("open-modal', 'collect-payment", false);
    }

    #[Test]
    public function a_planned_charge_offers_its_unpaid_installments(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(gross: '30000.00', actor: $actor);
        $lines = $this->plan($charge, count: 3, actor: $actor);
        $this->receive($charge, (string) $lines->first()->amount, $lines->first());

        $dialog = $this->dialogCharges(
            $this->actingAs($actor)->get(route('admin.student-fees.index'))->assertOk()->getContent(),
        );

        // The paid first line is left out; the other two are offered, in order.
        $this->assertSame(
            $lines->slice(1)->map(static fn ($line): string => (string) $line->getKey())->values()->all(),
            array_column($dialog[$charge->getKey()]['installments'], 'id'),
        );
    }

    #[Test]
    public function a_paid_charge_gets_no_button(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(gross: '1000.00', actor: $actor);
        $this->receive($charge, '1000.00');

        $this->actingAs($actor)->get(route('admin.student-fees.index'))
            ->assertOk()
            ->assertSee($charge->fee_number)
            ->assertDontSee("name: 'quick-collect'", false)
            ->assertDontSee('charges: JSON.parse', false);
    }

    #[Test]
    public function the_front_desk_sees_the_button(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);
        $receptionist = $this->createUserWithRole('Receptionist');

        $this->actingAs($receptionist)->get(route('admin.student-fees.index'))
            ->assertOk()
            ->assertSee("name: 'quick-collect', fee: ".$charge->getKey(), false);
    }

    #[Test]
    public function somebody_who_cannot_take_money_sees_no_button_and_no_dialog(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);
        $viewer = $this->createUserWithPermissions(['student_fees.view', 'student_fees.view_any']);

        $this->actingAs($viewer)->get(route('admin.student-fees.index'))
            ->assertOk()
            ->assertSee($charge->fee_number)
            ->assertDontSee("name: 'quick-collect'", false)
            ->assertDontSee('charges: JSON.parse', false);
    }

    #[Test]
    public function each_charge_carries_its_own_idempotency_key(): void
    {
        $actor = $this->createSuperAdmin();
        $this->charge(actor: $actor);
        $this->charge(actor: $actor);

        $html = $this->actingAs($actor)->get(route('admin.student-fees.index'))->assertOk()->getContent();
        $keys = array_map(static fn (array $charge): string => $charge['fields']['idempotency_key'], $this->dialogCharges($html));

        $this->assertCount(2, $keys, 'Each open charge should carry one key.');
        $this->assertCount(2, array_unique($keys), 'Two charges must never share a key.');
    }

    #[Test]
    public function a_receipt_taken_from_the_list_lands_on_the_charge(): void
    {
        $actor = $this->createSuperAdmin();
        $charge = $this->charge(actor: $actor);

        $this->actingAs($actor)
            ->from(route('admin.student-fees.index'))
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '2500.00',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
                'idempotency_key' => (string) Str::ulid(),
                'collect_fee_id' => (string) $charge->getKey(),
            ])
            ->assertRedirect();

        $this->assertSame(1, StudentFeePayment::query()->where('student_fee_id', $charge->getKey())->count());
        $this->assertSame(0, Money::compare('2500.00', (string) $charge->refresh()->paid_amount));
    }

    #[Test]
    public function a_refusal_comes_back_to_the_list_with_the_dialog_open_on_that_charge(): void
    {
        $actor = $this->createSuperAdmin();
        $other = $this->charge(actor: $actor);
        $charge = $this->charge(actor: $actor);
        $key = (string) Str::ulid();

        $html = $this->actingAs($actor)
            ->from(route('admin.student-fees.index'))
            ->followingRedirects()
            ->post(route('admin.fee-payments.store', $charge), [
                'amount' => '0',
                'payment_method' => PaymentMethod::Cash->value,
                'paid_on' => now()->toDateString(),
                'idempotency_key' => $key,
                'collect_fee_id' => (string) $charge->getKey(),
            ])
            ->assertOk()
            ->getContent();

        $this->assertSame(0, StudentFeePayment::query()->count());

        // Reopened on the refused charge, with the request's message and the duplicate override.
        $this->assertStringContainsString('retryId: '.$charge->getKey().',', $html);
        $this->assertStringContainsString('A receipt records money that arrived', $html);
        $this->assertStringContainsString('name="confirm_duplicate"', $html);

        // The same key and what was typed come back on that charge only.
        $dialog = $this->dialogCharges($html);
        $this->assertTrue($dialog[$charge->getKey()]['retry']);
        $this->assertSame($key, $dialog[$charge->getKey()]['fields']['idempotency_key']);
        $this->assertSame('0', $dialog[$charge->getKey()]['fields']['amount']);

        $this->assertFalse($dialog[$other->getKey()]['retry']);
        $this->assertNotSame($key, $dialog[$other->getKey()]['fields']['idempotency_key']);
    }

    /**
     * The dialog's per-charge data, decoded from the `@js(...)` in its `x-data`. `@js` encodes the
     * JSON a second time inside `JSON.parse('...')`, so it is decoded twice.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dialogCharges(string $html): array
    {
        $this->assertSame(1, preg_match("/charges: JSON\\.parse\\('([^']*)'\\)/", $html, $match), 'The dialog data is missing.');

        return json_decode((string) json_decode('"'.$match[1].'"'), true, flags: JSON_THROW_ON_ERROR);
    }
}
