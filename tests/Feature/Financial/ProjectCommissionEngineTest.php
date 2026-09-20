<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Finance\PaymentResult;
use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionScope;
use App\Enums\CommissionSkipReason;
use App\Enums\CommissionSourceType;
use App\Enums\LedgerEntryPurpose;
use App\Enums\PaymentMethod;
use App\Enums\ReferralSource;
use App\Enums\ReferralSubject;
use App\Enums\ReversalType;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionEntitlement;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Crm\Client;
use App\Models\Finance\ProjectPayment;
use App\Models\Project\Project;
use App\Services\Collaborator\ReferralService;
use App\Services\Finance\PaymentService;
use App\Services\Project\ProjectValueService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The project commission engine (phase-11, §11.1 FT-06 to FT-08, §120.6 and §120.7).
 *
 * It shares the whole guard sequence with the student side, so what is proved here is the four things
 * that genuinely differ: the attribution is on the **project**, a per-project override exists, the
 * milestone guard, and the two extra base modes.
 */
final class ProjectCommissionEngineTest extends TestCase
{
    use BuildsFinancialFixtures;
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
        $this->setting('collaborator.commission_on_overpayment', false);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('finance.refund_approval_required', false);
    }

    /** FT-06 — §120.6 */
    #[Test]
    public function a_project_without_a_collaborator_creates_no_commission(): void
    {
        $project = $this->project();

        $payment = $this->pay($project, '100000.00')->payment;

        $this->assertDatabaseCount('collaborator_commission_ledger_entries', 0);
        $this->assertSame(CommissionSkipReason::NoReferral, $payment->refresh()->commission_skip_reason);
    }

    /** FT-07 — §120.7, first half */
    #[Test]
    public function a_project_payment_creates_a_commission_at_the_configured_rate(): void
    {
        $partner = $this->projectPartner('15.0000');
        $project = $this->project($partner, '200000.00');

        $payment = $this->pay($project, '100000.00')->payment;
        $entry = $this->projectEntryFor($payment);

        $this->assertNotNull($entry);
        $this->assertSame('15000.00', (string) $entry->amount);
        $this->assertSame(LedgerEntryPurpose::ProjectCommission, $entry->purpose);
        $this->assertSame(CommissionSourceType::ProjectPayment, $entry->source_type);
        $this->assertSame((int) $project->getKey(), (int) $entry->project_id);

        $this->assertWalletMatchesLedger($partner);
    }

    /** FT-07 — §120.7, the `total_value` variant that must not over-release. */
    #[Test]
    public function the_total_value_base_releases_the_promise_and_no_more(): void
    {
        $partner = $this->projectPartner('15.0000', CommissionBase::TotalValue);
        $project = $this->project($partner, '200000.00');

        $first = $this->pay($project, '100000.00', ['on' => '2026-03-10'])->payment;
        $second = $this->pay($project, '100000.00', ['on' => '2026-04-10'])->payment;

        $entitlement = CollaboratorCommissionEntitlement::query()->first();
        $this->assertSame('30000.00', (string) $entitlement?->entitlement_amount,
            '15 % of the 200,000 project value, promised once.');

        $this->assertSame('15000.00', (string) $this->projectEntryFor($first)?->amount);
        $this->assertSame('15000.00', (string) $this->projectEntryFor($second)?->amount,
            'The second payment releases exactly the residual.');

        $this->assertSame('30000.00', (string) $entitlement->refresh()->released_amount);

        // A third payment against an exhausted promise earns nothing at all.
        $third = $this->pay($project, '50000.00', ['on' => '2026-05-10'])->payment;

        $this->assertNull($this->projectEntryFor($third));
        $this->assertSame('30000.00', (string) $this->walletOf($partner)?->pending_balance);

        $this->assertWalletMatchesLedger($partner);
    }

    /** FT-08 — §120.8 */
    #[Test]
    public function a_project_refund_creates_a_reversal_and_preserves_the_original(): void
    {
        $partner = $this->projectPartner('15.0000');
        $project = $this->project($partner, '200000.00');
        $payment = $this->pay($project, '100000.00')->payment;

        $original = $this->projectEntryFor($payment);
        $before = (string) $original->amount;

        $reversal = app(PaymentService::class)->refund($payment->refresh(), new RefundData(
            amount: '100000.00',
            reason: 'Engagement cancelled',
            type: ReversalType::FullRefund,
        ));

        $debit = CollaboratorCommissionLedgerEntry::query()
            ->where('payment_reversal_id', $reversal->getKey())
            ->first();

        $this->assertNotNull($debit);
        $this->assertSame('15000.00', (string) $debit->amount);
        $this->assertSame('-15000.00', (string) $debit->signed_amount);
        $this->assertSame((int) $original->getKey(), (int) $debit->reverses_entry_id);

        $this->assertSame($before, (string) $original->refresh()->amount,
            'The original is preserved on every money column.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** §45 — the per-project override authorises commission on its own. */
    #[Test]
    public function a_per_project_override_sets_the_rate_for_that_engagement(): void
    {
        $partner = $this->projectPartner('5.0000');
        $project = $this->project($partner, '100000.00');

        $this->override($project, '20.0000');

        $payment = $this->pay($project->refresh(), '50000.00')->payment;
        $entry = $this->projectEntryFor($payment);

        $this->assertSame('10000.00', (string) $entry?->amount,
            '20 % from the project override, not the 5 % on the partner rule.');

        $this->assertSame(CommissionRuleSource::ProjectOverride, $entry?->rule_source);

        $this->assertWalletMatchesLedger($partner);
    }

    /** §6.1.1 — an explicit "no" outranks an implicit "yes". */
    #[Test]
    public function a_disabled_partner_rule_beats_a_project_override(): void
    {
        $partner = $this->projectPartner('5.0000', enabled: false);
        $project = $this->project($partner, '100000.00');

        $this->override($project, '20.0000');

        $payment = $this->pay($project->refresh(), '50000.00')->payment;

        $this->assertNull($this->projectEntryFor($payment));
        $this->assertSame(CommissionSkipReason::CommissionDisabled, $payment->refresh()->commission_skip_reason,
            'Commission switched off for the partner wins over a rate somebody put on the project.');
    }

    /** G10, project form. */
    #[Test]
    public function a_milestone_rule_needs_the_payment_to_name_a_milestone(): void
    {
        $partner = $this->projectPartner('10.0000', CommissionBase::Milestone);
        $project = $this->project($partner, '100000.00');

        $payment = $this->pay($project, '25000.00')->payment;

        $this->assertNull($this->projectEntryFor($payment));
        $this->assertSame(CommissionSkipReason::MilestoneNotCommissionable, $payment->refresh()->commission_skip_reason);
        $this->assertStringContainsString('not recorded against one', (string) $payment->commission_skip_detail);
    }

    /** The attribution is on the project, not on the client. */
    #[Test]
    public function a_client_referral_does_not_earn_on_every_project_that_client_commissions(): void
    {
        $partner = $this->projectPartner('10.0000');
        $client = $this->client();

        // The partner introduced the client, but not this engagement.
        app(ReferralService::class)->attachSubject(
            ReferralSubject::Client,
            (int) $client->getKey(),
            $partner,
            ReferralSource::ManualSelection,
            null,
            Carbon::parse('2020-01-01'),
        );

        $project = $this->project(null, '100000.00', $client);
        $payment = $this->pay($project, '50000.00')->payment;

        $this->assertNull($this->projectEntryFor($payment));
        $this->assertSame(CommissionSkipReason::NoReferral, $payment->refresh()->commission_skip_reason,
            'Who brought the client in is a different fact from who brought in this job.');
    }

    #[Test]
    public function a_project_payment_is_idempotent_on_its_key(): void
    {
        $partner = $this->projectPartner('15.0000');
        $project = $this->project($partner, '200000.00');

        $first = $this->pay($project, '100000.00', ['key' => 'proj-dup-1']);
        $second = $this->pay($project, '100000.00', ['key' => 'proj-dup-1']);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame((int) $first->payment->getKey(), (int) $second->payment->getKey());
        $this->assertSame(1, ProjectPayment::query()->count());
        $this->assertSame(1, CollaboratorCommissionLedgerEntry::query()->earnings()->count());

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function a_payment_with_no_invoice_is_recorded_as_an_advance(): void
    {
        $project = $this->project($this->projectPartner('10.0000'), '100000.00');

        $payment = $this->pay($project, '25000.00')->payment;

        $this->assertTrue((bool) $payment->is_advance,
            'An advance is derived from the absence of an invoice, not from a box somebody ticks.');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function projectPartner(
        string $rate = '10.0000',
        ?CommissionBase $base = null,
        bool $enabled = true,
    ): Collaborator {
        $partner = $this->partner($rate, rule: [
            'scope' => CommissionScope::Project,
            'base' => $base,
            'enabled' => $enabled,
        ]);

        return $partner;
    }

    private function client(): Client
    {
        $this->projectSequence++;

        $client = new Client;
        $client->forceFill([
            'client_code' => 'CLI-P'.str_pad((string) $this->projectSequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Project Client '.$this->projectSequence,
        ])->save();

        return $client->refresh();
    }

    private function project(?Collaborator $partner = null, string $value = '100000.00', ?Client $client = null): Project
    {
        $this->projectSequence++;
        $client ??= $this->client();

        $project = new Project;
        $project->forceFill([
            'code' => 'PRJ-T'.str_pad((string) $this->projectSequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Acceptance Project '.$this->projectSequence,
            'client_id' => $client->getKey(),
            'project_value' => Money::of($value),
            'discount_amount' => '0.00',
            // `net_value` is a STORED generated column (value minus discount) — writing it is a 1906.
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

    private function pay(Project $project, string $amount, array $options = []): PaymentResult
    {
        $this->projectSequence++;

        return app(PaymentService::class)->recordProjectPayment($project, new RecordPaymentData(
            amount: $amount,
            method: $options['method'] ?? PaymentMethod::BankTransfer,
            paidOn: Carbon::parse($options['on'] ?? '2026-03-10'),
            milestoneId: $options['milestone'] ?? null,
            invoiceId: $options['invoice'] ?? null,
            idempotencyKey: $options['key'] ?? 'proj-'.$this->projectSequence,
        ));
    }

    /**
     * Set a per-project commission override the way the system does.
     *
     * `projects.commission_type` and `commission_rate` are guarded (phase-06 INV-P1): only
     * `ProjectValueService::revise()` may write them, and it records a `project_value_revisions` row
     * with the reason. A test that forced the columns would be testing a path that cannot happen.
     */
    private function override(Project $project, string $rate): void
    {
        app(ProjectValueService::class)->revise(
            $project,
            [
                'commission_type' => CommissionCalculationType::Percentage->value,
                'commission_rate' => $rate,
            ],
            'Per-project commission agreed for this engagement',
        );
    }

    private function projectEntryFor(ProjectPayment $payment): ?CollaboratorCommissionLedgerEntry
    {
        return CollaboratorCommissionLedgerEntry::query()
            ->where('project_payment_id', $payment->getKey())
            ->earnings()
            ->first();
    }
}
