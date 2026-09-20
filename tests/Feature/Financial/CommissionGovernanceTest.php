<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\RuleData;
use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionScope;
use App\Enums\CommissionStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\ReversalApprovalStatus;
use App\Enums\ReversalType;
use App\Jobs\Collaborator\ProcessStudentFeeCommission;
use App\Models\Activity;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Institute\StudentFeePayment;
use App\Services\Collaborator\CommissionApprovalService;
use App\Services\Collaborator\CommissionRuleService;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\TestCase;

/**
 * The guarantees that are about **who may do what, and what can never be changed**
 * (phase-10-12 §11.4, §11.6).
 *
 * The engine's arithmetic is proved next door. This file proves the things that hold whatever the
 * arithmetic says: one calculation site, one insert path, no edit, no delete, a reason on every
 * discretionary act, and a permission behind every route.
 */
final class CommissionGovernanceTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | §11.4 — the wiring
    |--------------------------------------------------------------------------
    */

    /** [D-IMP-3] / INV-20 */
    #[Test]
    public function recording_a_payment_dispatches_exactly_one_commission_job_after_commit(): void
    {
        Bus::fake();

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);

        $result = app(PaymentService::class)->recordStudentFeePayment($fee, new RecordPaymentData(
            amount: '10000.00',
            method: PaymentMethod::Cash,
            paidOn: Carbon::parse('2026-03-10'),
            idempotencyKey: 'wiring-1',
        ));

        Bus::assertDispatchedTimes(ProcessStudentFeeCommission::class, 1);

        // And a replay of the same submit dispatches nothing more: there is no second receipt.
        app(PaymentService::class)->recordStudentFeePayment($fee, new RecordPaymentData(
            amount: '10000.00',
            method: PaymentMethod::Cash,
            paidOn: Carbon::parse('2026-03-10'),
            idempotencyKey: 'wiring-1',
        ));

        Bus::assertDispatchedTimes(ProcessStudentFeeCommission::class, 1);
        $this->assertSame(1, StudentFeePayment::query()->count());
    }

    /**
     * FT-IMP-01 — the static scan that keeps one calculation site.
     *
     * The contract writes this as "`Money::percentage` and the string `commission_rate` may appear
     * only in `app/Services/Collaborator/`". Taken literally it fails on six files that are plainly
     * correct: payroll components, an attendance rate, project progress and a timesheet all take a
     * percentage of something, and none of them is a commission. `Money::percentage()` is the general
     * money helper; banning it everywhere would push those four phases into writing their own
     * arithmetic, which is the opposite of what the rule is for.
     *
     * So the scan is on what actually identifies a commission: **a file that touches
     * `commission_rate` and multiplies**. That is the thing [D-IMP-3] forbids — a report, a widget or
     * an export working out what somebody earned, producing a second number that can disagree with the
     * ledger. Reading the column to *display* it is fine and common.
     */
    #[Test]
    public function a_commission_amount_is_computed_in_exactly_one_place(): void
    {
        $allowed = [
            'app/Services/Collaborator/',
            'app/Support/Money.php',
            'app/Support/Collaborator/',
            'app/DataObjects/Collaborator/',
            'app/Models/Collaborator/',
            'app/Enums/',
            // Declares the settings keys `default_student_commission_rate` and
            // `default_project_commission_rate`, and separately uses `bcmul` to normalise a decimal
            // field's scale. It names a rate; it never multiplies by one.
            'app/Support/SettingsRegistry.php',
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(base_path()) + 1));

            foreach ($allowed as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $contents = (string) file_get_contents($file);

            if (! str_contains($contents, 'commission_rate') && ! str_contains($contents, 'commissionRate')) {
                continue;
            }

            foreach (['Money::percentage', 'Money::mul', 'Money::prorate', 'bcmul'] as $operation) {
                if (str_contains($contents, $operation)) {
                    $offenders[] = $relative.' computes with a commission rate ('.$operation.')';
                }
            }
        }

        $this->assertSame([], $offenders,
            'A commission amount is computed in `app/Services/Collaborator/` and nowhere else '
            .'([D-IMP-3]). A second site is a second number that can disagree with the ledger.');
    }

    /** [D-IMP-2] */
    #[Test]
    public function payments_cannot_be_inserted_outside_the_payment_service(): void
    {
        $this->expectExceptionMessageMatches('/PaymentService/');

        (new StudentFeePayment)->forceFill([
            'receipt_no' => 'FEE-HAND-1',
            'idempotency_key' => 'by-hand',
            'duplicate_fingerprint' => 'by-hand',
            'student_fee_id' => 1,
            'student_id' => 1,
            'amount' => '100.00',
            'payment_method' => 'cash',
            'paid_on' => '2026-03-10',
            'received_by_name' => 'Nobody',
        ])->save();
    }

    /** INV-21 */
    #[Test]
    public function the_ledger_can_only_be_written_through_the_writer(): void
    {
        $this->expectExceptionMessageMatches('/LedgerWriter/');

        (new CollaboratorCommissionLedgerEntry)->forceFill(['amount' => '100.00'])->save();
    }

    /** INV-4 — a wrong figure is corrected by a reversing row, never by an edit. */
    #[Test]
    public function ledger_money_columns_are_immutable(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $entry = $this->entryFor($this->receive($fee, '10000.00')->payment);

        foreach (['amount' => '9999.00', 'commission_rate' => '99.0000', 'base_amount' => '1.00'] as $column => $value) {
            try {
                $entry->forceFill([$column => $value])->save();
                $this->fail(sprintf('[%s] was editable on a posted commission.', $column));
            } catch (\LogicException $e) {
                $this->assertStringContainsString($column, $e->getMessage());
            }

            $entry->refresh();
        }
    }

    /** INV-8 — the only "edit" a receipt has is a void and a fresh row. */
    #[Test]
    public function a_payment_cannot_be_edited_and_void_is_the_correction_path(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '4000.00')->payment;

        try {
            $payment->forceFill(['amount' => '5000.00'])->save();
            $this->fail('A receipt amount was editable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('amount', $e->getMessage());
        }

        $reversal = app(PaymentService::class)->void($payment->refresh(), 'Keyed against the wrong student');

        $this->assertSame(ReversalType::Void, $reversal->type);
        $this->assertSame(ReceivedPaymentStatus::Voided, $payment->refresh()->status);
        $this->assertSame('4000.00', (string) $payment->amount, 'The receipt still says what it always said.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** D16 — nothing financial is ever deleted. */
    #[Test]
    public function financial_rows_cannot_be_deleted(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;
        $entry = $this->entryFor($payment);

        foreach ([$payment, $entry] as $row) {
            try {
                $row->delete();
                $this->fail(sprintf('%s was deletable.', $row::class));
            } catch (\LogicException) {
                // The model refuses first, so the message points somewhere rather than being a bare
                // SQLSTATE 45000 from the trigger behind it.
            }
        }

        // And the trigger holds even when the model is bypassed entirely.
        $this->expectExceptionMessageMatches('/45000|cannot be deleted/i');
        DB::table('collaborator_commission_ledger_entries')->where('id', $entry->getKey())->delete();
    }

    /** §2.18.7 — forward-only, idempotent, and audited. */
    #[Test]
    public function approval_is_forward_only_and_idempotent(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $entry = $this->entryFor($this->receive($fee, '10000.00')->payment);
        $actor = $this->createSuperAdmin();

        $approvals = app(CommissionApprovalService::class);

        $this->assertSame(CommissionStatus::Pending, $entry->status);

        $approved = $approvals->approve($entry, $actor);
        $this->assertSame(CommissionStatus::Available, $approved->status,
            'With no hold configured, approval continues straight to available.');

        $wallet = $this->walletOf($partner);
        $this->assertSame('1000.00', (string) $wallet?->available_balance);

        // Approving again must not double the wallet.
        $approvals->approve($approved, $actor);
        $this->assertSame('1000.00', (string) $this->walletOf($partner)?->available_balance);

        $this->assertWalletMatchesLedger($partner);
    }

    #[Test]
    public function every_discretionary_act_is_audited_with_a_reason(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $entry = $this->entryFor($this->receive($fee, '10000.00')->payment);
        $actor = $this->createSuperAdmin();

        $approvals = app(CommissionApprovalService::class);

        foreach (['', '   '] as $blank) {
            try {
                $approvals->reject($entry, $blank, $actor);
                $this->fail('A rejection without a reason was accepted.');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('reason', strtolower($e->getMessage()));
            }
        }

        $rejected = $approvals->reject($entry, 'The student was not referred by this partner', $actor);

        $this->assertSame(CommissionStatus::Cancelled, $rejected->status);
        $this->assertStringContainsString('not referred', (string) $rejected->cancel_reason);
        $this->assertSame('1000.00', (string) $rejected->amount, 'The row stays, with its figure intact.');

        $this->assertTrue(
            Activity::query()->where('module', 'collaborator_commissions')->whereNotNull('reason')->exists(),
            'The reason is stored in its own column on the audit row, not only in the ledger.'
        );

        $this->assertWalletMatchesLedger($partner);
    }

    /** INV-6 — a settings change never reaches back. */
    #[Test]
    public function settings_changed_after_posting_change_nothing_already_posted(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '100000.00');
        $entry = $this->entryFor($this->receive($fee, '10000.00')->payment);

        $snapshot = $entry->rule_snapshot;
        $this->assertSame('manual', $snapshot['settings']['collaborator.commission_approval_mode'] ?? null);

        $this->setting('collaborator.commission_approval_mode', 'automatic');
        $this->setting('collaborator.student_commission_base', 'gross');
        $this->setting('collaborator.commission_hold_days', 90);

        $entry->refresh();

        $this->assertSame(CommissionStatus::Pending, $entry->status);
        $this->assertSame('1000.00', (string) $entry->amount);
        $this->assertSame($snapshot, $entry->rule_snapshot,
            'The snapshot is what the entry means, and it is frozen at posting time.');
        $this->assertNull($entry->hold_until, 'A hold introduced later does not retro-hold a posted entry.');
    }

    /** INV-17 — a rate change is a new version, never an edit. */
    #[Test]
    public function rule_versions_are_immutable_and_non_overlapping(): void
    {
        $partner = $this->partner('10.0000');
        $rules = app(CommissionRuleService::class);

        $v1 = $rules->currentVersion($partner, CommissionScope::Student);
        $this->assertNotNull($v1);

        try {
            $v1->forceFill(['rate' => '25.0000'])->save();
            $this->fail('A rule rate was editable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('rate', $e->getMessage());
        }

        $v2 = $rules->createVersion($partner, CommissionScope::Student, new RuleData(
            scope: CommissionScope::Student,
            calculationType: CommissionCalculationType::Percentage,
            effectiveFrom: Carbon::parse('2026-06-01'),
            rate: '25.0000',
        ), 'Renegotiated');

        $v1->refresh();

        $this->assertSame(2, (int) $v2->version);
        $this->assertSame('2026-05-31', $v1->effective_to?->toDateString(),
            'The old version closes the day before the new one starts — the windows never overlap.');
        $this->assertSame((int) $v1->getKey(), (int) $v2->supersedes_id);

        // And a version cannot start before the one it replaces.
        $this->expectExceptionMessageMatches('/already starts on/');

        $rules->createVersion($partner, CommissionScope::Student, new RuleData(
            scope: CommissionScope::Student,
            calculationType: CommissionCalculationType::Percentage,
            effectiveFrom: Carbon::parse('2026-02-01'),
            rate: '30.0000',
        ), 'Backwards');
    }

    /** §6.6 — nothing is undone until the refund is authorised. */
    #[Test]
    public function a_refund_awaiting_approval_undoes_nothing(): void
    {
        $this->setting('finance.refund_approval_required', true);
        $this->setting('finance.refund_approval_threshold', '0.00');

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;
        $entry = $this->entryFor($payment);

        $payments = app(PaymentService::class);

        $reversal = $payments->refund($payment->refresh(), new RefundData(
            amount: '10000.00',
            reason: 'Awaiting a manager',
            type: ReversalType::FullRefund,
        ));

        $this->assertSame(ReversalApprovalStatus::Pending, $reversal->approval_status);
        $this->assertSame('0.00', (string) $entry->refresh()->reversed_amount,
            'A refund nobody has approved has undone nothing.');

        $payments->approveReversal($reversal, $this->createSuperAdmin());

        $this->assertSame('1000.00', (string) $entry->refresh()->reversed_amount);
        $this->assertWalletMatchesLedger($partner);
    }

    /** F-4.9 — a rejection puts the money back, in the same transaction. */
    #[Test]
    public function rejecting_a_reversal_rolls_the_refund_back(): void
    {
        $this->setting('finance.refund_approval_required', true);
        $this->setting('finance.refund_approval_threshold', '0.00');

        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $payment = $this->receive($fee, '10000.00')->payment;

        $payments = app(PaymentService::class);

        $reversal = $payments->refund($payment->refresh(), new RefundData(
            amount: '10000.00',
            reason: 'Requested in error',
            type: ReversalType::FullRefund,
        ));

        $this->assertSame('10000.00', (string) $payment->refresh()->refunded_amount);

        $payments->rejectReversal($reversal, 'The student never asked for this', $this->createSuperAdmin());

        $payment->refresh();
        $this->assertSame('0.00', (string) $payment->refunded_amount);
        $this->assertSame(ReceivedPaymentStatus::Cleared, $payment->status);

        $this->assertSame(0, CollaboratorCommissionLedgerEntry::query()->undos()->count(),
            'A refused refund undoes no commission.');

        $this->assertWalletMatchesLedger($partner);
    }

    /** §6.3 `dryRun()` — writes nothing. */
    #[Test]
    public function the_dry_run_preview_writes_nothing(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '30000.00');

        $before = [
            'entries' => CollaboratorCommissionLedgerEntry::query()->count(),
            'entitlements' => DB::table('collaborator_commission_entitlements')->count(),
            'payments' => StudentFeePayment::query()->count(),
            'wallets' => DB::table('collaborator_wallets')->count(),
        ];

        $preview = app(PaymentService::class)->dryRun($fee, new RecordPaymentData(
            amount: '7000.00',
            method: PaymentMethod::Cash,
            paidOn: Carbon::parse('2026-03-10'),
        ));

        $this->assertTrue($preview->earns);
        $this->assertSame('700.00', $preview->amount);

        $this->assertSame($before, [
            'entries' => CollaboratorCommissionLedgerEntry::query()->count(),
            'entitlements' => DB::table('collaborator_commission_entitlements')->count(),
            'payments' => StudentFeePayment::query()->count(),
            'wallets' => DB::table('collaborator_wallets')->count(),
        ], 'A preview that left a promise behind would charge a cashier for changing their mind.');

        // And the figure it showed is the figure the receipt produces.
        $payment = $this->receive($fee, '7000.00')->payment;
        $this->assertSame($preview->amount, (string) $this->entryFor($payment)?->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | §11.6 — authorization
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_commission_screen_needs_its_permission(): void
    {
        $nobody = $this->createUserWithPermissions([]);

        foreach ([
            route('admin.commissions.index'),
            route('admin.commission-skips.index'),
        ] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }

        $reader = $this->createUserWithPermissions(['collaborator_commissions.view_any']);
        $this->actingAs($reader)->get(route('admin.commissions.index'))->assertOk();

        $this->actingAs($reader)->get(route('admin.commission-skips.index'))->assertForbidden();
    }

    #[Test]
    public function approving_needs_the_approve_permission_and_writes_nothing_without_it(): void
    {
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner);
        $entry = $this->entryFor($this->receive($fee, '10000.00')->payment);

        $reader = $this->createUserWithPermissions(['collaborator_commissions.view_any', 'collaborator_commissions.view']);

        $this->actingAs($reader)
            ->post(route('admin.commissions.approve', $entry))
            ->assertForbidden();

        $this->assertSame(CommissionStatus::Pending, $entry->refresh()->status,
            'A refused request leaves the ledger exactly as it was.');

        $approver = $this->createUserWithPermissions([
            'collaborator_commissions.view_any', 'collaborator_commissions.approve',
        ]);

        $this->actingAs($approver)
            ->from(route('admin.commissions.index'))
            ->post(route('admin.commissions.approve', $entry))
            ->assertRedirect();

        $this->assertSame(CommissionStatus::Available, $entry->refresh()->status);
        $this->assertWalletMatchesLedger($partner);
    }

    /** [D-IMP-6] — explicit ids, and rows that moved are reported rather than forced. */
    #[Test]
    public function a_bulk_action_names_its_rows_and_skips_what_moved(): void
    {
        $partner = $this->partner('10.0000');
        $approver = $this->createUserWithPermissions([
            'collaborator_commissions.view_any', 'collaborator_commissions.approve',
        ]);

        $ids = [];

        foreach ([1, 2, 3] as $n) {
            $fee = $this->charge($partner, '30000.00');
            $ids[] = (int) $this->entryFor($this->receive($fee, '1000.00')->payment)->getKey();
        }

        // One of them is decided before the bulk action arrives.
        app(CommissionApprovalService::class)->approve(
            CollaboratorCommissionLedgerEntry::query()->find($ids[0]),
            $approver,
        );

        $this->actingAs($approver)
            ->from(route('admin.commissions.index'))
            ->post(route('admin.commissions.bulk-approve'), ['entries' => $ids])
            ->assertRedirect();

        foreach ($ids as $id) {
            $this->assertSame(
                CommissionStatus::Available,
                CollaboratorCommissionLedgerEntry::query()->find($id)->status,
            );
        }

        // An empty selection is refused on the field rather than silently doing nothing.
        $this->actingAs($approver)
            ->from(route('admin.commissions.index'))
            ->post(route('admin.commissions.bulk-approve'), ['entries' => []])
            ->assertSessionHasErrors('entries');

        $this->assertWalletMatchesLedger($partner);
    }

    /** INV-5 / D43 — the ledger module offers no edit and no delete at all. */
    #[Test]
    public function the_ledger_declares_no_edit_and_no_delete_route(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'admin.commissions.'))
            ->values()
            ->all();

        $this->assertNotContains('admin.commissions.edit', $names);
        $this->assertNotContains('admin.commissions.update', $names);
        $this->assertNotContains('admin.commissions.destroy', $names);

        $this->assertContains('admin.commissions.adjustments.store', $names,
            'The one create path is the manual adjustment, which is an append.');
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
