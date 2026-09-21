<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Finance\RecordPaymentData;
use App\Enums\CommissionScope;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralSubject;
use App\Enums\ReversalType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Crm\Client;
use App\Models\Finance\ProjectPayment;
use App\Models\Project\Project;
use App\Services\Collaborator\ReferralService;
use App\Services\Finance\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The project payment routes, through the real HTTP kernel (phase-11 §7.2, §8.2, §11.6).
 *
 * The student side's twin. What is proved separately is the thing that genuinely differs: every route
 * carries `module:project_payments` rather than the `payments` umbrella (F-6.1), so phase-13's
 * cross-source register can be switched off without taking this one with it.
 */
final class ProjectPaymentScreenTest extends TestCase
{
    use BuildsFinancialFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private int $projectSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'manual');
        $this->setting('collaborator.project_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.refund_approval_required', false);
    }

    #[Test]
    public function the_register_needs_its_permission(): void
    {
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('admin.project-payments.index'))
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions(['project_payments.view_any']))
            ->get(route('admin.project-payments.index'))
            ->assertOk();
    }

    #[Test]
    public function the_detail_and_printable_copy_render(): void
    {
        $partner = $this->projectPartner();
        $project = $this->project($partner);
        $payment = $this->pay($project, '50000.00');

        $reader = $this->createUserWithPermissions([
            'project_payments.view_any', 'project_payments.view', 'project_payments.print',
        ]);

        $this->actingAs($reader)
            ->get(route('admin.project-payments.show', $payment))
            ->assertOk()
            ->assertSeeText((string) $payment->payment_no);

        $this->actingAs($reader)
            ->get(route('admin.project-payments.receipt', $payment))
            ->assertOk();
    }

    #[Test]
    public function a_double_submit_records_the_money_once(): void
    {
        $partner = $this->projectPartner();
        $project = $this->project($partner);

        $payload = [
            'amount' => '50000.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'paid_on' => '2026-03-10',
            'idempotency_key' => (string) Str::ulid(),
        ];

        $cashier = $this->createUserWithPermissions([
            'project_payments.view_any', 'project_payments.view', 'project_payments.create',
        ]);

        $this->actingAs($cashier)->post(route('admin.project-payments.store', $project), $payload)->assertRedirect();
        $this->actingAs($cashier)->post(route('admin.project-payments.store', $project), $payload)->assertRedirect();

        $this->assertSame(1, ProjectPayment::query()->count());
        $this->assertSame(1, CollaboratorCommissionLedgerEntry::query()->earnings()->count());

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function refunding_needs_the_reversal_permission_not_the_payments(): void
    {
        $partner = $this->projectPartner();
        $project = $this->project($partner);
        $payment = $this->pay($project, '50000.00');

        $payload = [
            'amount' => '50000.00',
            'reason' => 'Engagement cancelled before kick-off',
            'type' => ReversalType::FullRefund->value,
            'idempotency_key' => (string) Str::ulid(),
        ];

        $this->actingAs($this->createUserWithPermissions([
            'project_payments.view_any', 'project_payments.view', 'project_payments.create',
        ]))
            ->post(route('admin.project-payments.refund', $payment), $payload)
            ->assertForbidden();

        $this->assertSame('0.00', (string) $payment->refresh()->refunded_amount,
            'A refused request leaves the payment exactly as it was.');

        $this->actingAs($this->createUserWithPermissions([
            'project_payments.view_any', 'project_payments.view', 'payment_reversals.create',
        ]))
            ->from(route('admin.project-payments.show', $payment))
            ->post(route('admin.project-payments.refund', $payment), $payload)
            ->assertRedirect();

        $this->assertSame('50000.00', (string) $payment->refresh()->refunded_amount);
        $this->assertSame(ReceivedPaymentStatus::Refunded, $payment->status);

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function voiding_needs_a_reason_and_undoes_the_commission(): void
    {
        $partner = $this->projectPartner();
        $project = $this->project($partner);
        $payment = $this->pay($project, '50000.00');

        $actor = $this->createUserWithPermissions([
            'project_payments.view_any', 'project_payments.view', 'project_payments.change_status',
        ]);

        $this->actingAs($actor)
            ->from(route('admin.project-payments.show', $payment))
            ->post(route('admin.project-payments.void', $payment), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ReceivedPaymentStatus::Cleared, $payment->refresh()->status);

        $this->actingAs($actor)
            ->from(route('admin.project-payments.show', $payment))
            ->post(route('admin.project-payments.void', $payment), ['reason' => 'Recorded against the wrong project'])
            ->assertRedirect();

        $this->assertSame(ReceivedPaymentStatus::Voided, $payment->refresh()->status);
        $this->assertSame('50000.00', (string) $payment->amount, 'The payment still says what it always said.');

        $this->assertWalletMatchesLedger($partner);
    }

    /**
     * §8.2's trail on the project detail page, and the distinction it has to keep.
     *
     * Somebody who may read a project is not thereby entitled to read every receipt against it, so the
     * card is absent rather than empty for them — "nothing yet" and "not yours to see" are different
     * facts and a page that rendered both as an empty list would be answering a question it was not
     * asked.
     */
    #[Test]
    public function the_project_detail_shows_a_payment_trail_only_to_whoever_may_read_receipts(): void
    {
        $partner = $this->projectPartner();
        $project = $this->project($partner);
        $payment = $this->pay($project, '50000.00');

        $withoutReceipts = $this->createUserWithPermissions(['projects.view_any', 'projects.view']);

        $this->actingAs($withoutReceipts)
            ->get(route('admin.projects.show', $project))
            ->assertOk()
            ->assertDontSee('Payments received');

        $withReceipts = $this->createUserWithPermissions([
            'projects.view_any', 'projects.view',
            'project_payments.view_any', 'project_payments.view',
        ]);

        $this->actingAs($withReceipts)
            ->get(route('admin.projects.show', $project))
            ->assertOk()
            ->assertSee('Payments received')
            ->assertSeeText((string) $payment->payment_no);
    }

    /** F-6.1 — the slug is `project_payments`, never the `payments` umbrella. */
    #[Test]
    public function every_project_payment_route_carries_its_own_module_slug(): void
    {
        $slugs = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.project-payments.')) {
                continue;
            }

            $modules = array_values(array_filter(
                $route->gatherMiddleware(),
                static fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'module:'),
            ));

            $slugs[(string) $route->getName()] = $modules;
        }

        $this->assertNotEmpty($slugs);

        foreach ($slugs as $name => $modules) {
            $this->assertContains('module:project_payments', $modules,
                sprintf('%s must carry its own slug, not phase-13\'s `payments` umbrella (F-6.1).', $name));

            $this->assertNotContains('module:payments', $modules);
        }
    }

    #[Test]
    public function no_route_can_edit_or_delete_a_payment(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'admin.project-payments.'))
            ->values()
            ->all();

        foreach (['edit', 'update', 'destroy'] as $forbidden) {
            $this->assertNotContains('admin.project-payments.'.$forbidden, $names,
                sprintf('A payment has no %s route, and never will (INV-8).', $forbidden));
        }

        $this->assertContains('admin.project-payments.void', $names);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function projectPartner(string $rate = '10.0000'): Collaborator
    {
        return $this->partner($rate, rule: ['scope' => CommissionScope::Project]);
    }

    private function project(?Collaborator $partner = null, string $value = '200000.00'): Project
    {
        $this->projectSequence++;

        $client = new Client;
        $client->forceFill([
            'client_code' => 'CLI-S'.str_pad((string) $this->projectSequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Screen Client '.$this->projectSequence,
        ])->save();

        $project = new Project;
        $project->forceFill([
            'code' => 'PRJ-S'.str_pad((string) $this->projectSequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Screen Project '.$this->projectSequence,
            'client_id' => $client->refresh()->getKey(),
            'project_value' => Money::of($value),
            'discount_amount' => '0.00',
            'status' => 'in_progress',
        ])->save();

        $project->refresh();

        if ($partner !== null) {
            app(ReferralService::class)->attachSubject(
                ReferralSubject::Project,
                (int) $project->getKey(),
                $partner,
                ReferralSource::ManualSelection,
                null,
                Carbon::parse('2020-01-01'),
            );
        }

        return $project;
    }

    private function pay(Project $project, string $amount): ProjectPayment
    {
        $this->projectSequence++;

        return app(PaymentService::class)->recordProjectPayment($project, new RecordPaymentData(
            amount: $amount,
            method: PaymentMethod::BankTransfer,
            paidOn: Carbon::parse('2026-03-10'),
            idempotencyKey: 'screen-'.$this->projectSequence,
        ))->payment;
    }
}
