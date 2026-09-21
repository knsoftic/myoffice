<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Collaborator\MarkPaidData;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\DataObjects\Finance\RefundData;
use App\Enums\FinanceContext;
use App\Enums\FinanceReportType;
use App\Enums\PayoutMethod;
use App\Enums\ReversalType;
use App\Services\Collaborator\CollaboratorStatementService;
use App\Services\Collaborator\CollaboratorWalletService;
use App\Services\Collaborator\PayoutService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\IncomeService;
use App\Services\Finance\InvoiceService;
use App\Support\DateRange;
use App\Support\FinanceVisibility;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsFinancialFixtures;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * The four reports (§99, phase-13 §6.7).
 *
 * Every assertion here is about a figure being right *for the reason it is supposed to be right*. The
 * basis is cash, so an unpaid invoice is worth nothing in the income report and everything in the aging
 * one. Only approved expenses count, so a pending claim does not move a profit figure. And collaborator
 * commission reaches the profit-and-loss statement **only** through the spine's own company-wide call —
 * which the last test proves by checking the P&L figure against that call directly.
 */
final class FinanceReportTest extends TestCase
{
    use BuildsFinancialFixtures;
    use BuildsInvoiceFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    private DateRange $range;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('finance.expense_approval_required', true);
        $this->setting('finance.expense_approval_threshold', '0.00');
        $this->setting('finance.reports_include_institute', true);
        $this->setting('finance.backdate_limit_days', 3650);
        $this->setting('collaborator.referral_system_enabled', true);
        $this->setting('collaborator.automatic_commission_enabled', true);
        $this->setting('collaborator.commission_approval_mode', 'automatic');
        $this->setting('collaborator.student_commission_base', 'paid');
        $this->setting('collaborator.commission_hold_days', 0);
        $this->setting('collaborator.minimum_payout', '0.00');
        $this->setting('finance.payout_reference_required', true);

        $this->range = DateRange::custom(now()->subMonths(2)->toDateString(), now()->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Income
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unpaid_invoice_is_worth_nothing_in_the_income_report(): void
    {
        $actor = $this->createSuperAdmin();

        app(InvoiceService::class)->markSent(
            $this->draftInvoice(lines: [$this->line('250000.00')]), ['buyer@example.test'], $actor,
        );

        $report = app(FinanceReportService::class)->report(FinanceReportType::Income, $this->range);

        $this->assertSame('0.00', $report->totals['amount'],
            'The basis is cash. An invoice is a claim, and claiming is not receiving.');
        $this->assertSame('cash', $report->meta['basis']);
        $this->assertStringContainsString('never here', $report->meta['note']);
    }

    #[Test]
    public function other_income_counts_net_of_its_refunds(): void
    {
        $actor = $this->createSuperAdmin();
        $income = $this->receiveOther('40000.00');

        app(IncomeService::class)->refund($income, new RefundData(
            amount: '15000.00', reason: 'Partly returned to the payer', type: ReversalType::PartialRefund,
        ), $actor);

        $report = app(FinanceReportService::class)->report(FinanceReportType::Income, $this->range);

        $this->assertSame('25000.00', $report->totals['amount'],
            '`net_amount` is generated, so the report cannot forget the refund.');
    }

    /** A reader who may not see a source is told it is missing, not quietly shown a smaller total. */
    #[Test]
    public function a_source_the_reader_cannot_see_is_named_rather_than_dropped(): void
    {
        $this->receiveOther('10000.00');

        $partial = $this->createUserWithPermissions([
            'income.view_reports', 'income.view_financial',
        ]);

        $report = app(FinanceReportService::class)->report(FinanceReportType::Income, $this->range, $partial);

        $this->assertSame('10000.00', $report->totals['amount']);
        $this->assertContains('Project payments', $report->omittedSources());
        $this->assertContains('Student fees', $report->omittedSources());
    }

    /*
    |--------------------------------------------------------------------------
    | Expenses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function only_an_approved_expense_counts_and_the_pending_ones_are_shown_separately(): void
    {
        $approver = $this->createSuperAdmin();

        app(ExpenseService::class)->approve($this->spend('60000.00', ['key' => 'approved']), $approver);
        $this->spend('41000.00', ['key' => 'pending']);

        $report = app(FinanceReportService::class)->report(FinanceReportType::Expenses, $this->range);

        $this->assertSame('60000.00', $report->totals['amount']);
        $this->assertSame(1, $report->meta['pending_count']);
        $this->assertSame('41000.00', $report->meta['pending_amount'],
            'Money awaiting a decision is visible, and never silently counted.');
    }

    #[Test]
    public function the_institute_switch_takes_its_rows_out_of_both_sides(): void
    {
        $approver = $this->createSuperAdmin();

        app(ExpenseService::class)->approve(
            $this->spend('30000.00', ['context' => FinanceContext::SoftwareHouse, 'key' => 'sh']), $approver,
        );
        app(ExpenseService::class)->approve(
            $this->spend('20000.00', ['context' => FinanceContext::Institute, 'key' => 'inst']), $approver,
        );

        $this->assertSame('50000.00',
            app(FinanceReportService::class)->report(FinanceReportType::Expenses, $this->range)->totals['amount']);

        $this->setting('finance.reports_include_institute', false);

        $report = app(FinanceReportService::class)->report(FinanceReportType::Expenses, $this->range);

        $this->assertSame('30000.00', $report->totals['amount']);
        $this->assertFalse($report->meta['includes_institute'],
            'The report says which business it is showing, so a figure is never ambiguous about what it covers.');
    }

    /*
    |--------------------------------------------------------------------------
    | Profit and loss
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_profit_and_loss_reaches_commission_only_through_the_spine(): void
    {
        $approver = $this->createSuperAdmin();

        // Some real money in, through the spine's own path.
        $partner = $this->partner('10.0000');
        $fee = $this->charge($partner, '500000.00');
        $this->receive($fee, '200000.00', ['on' => now()->subDays(20)->toDateString()]);

        app(ExpenseService::class)->approve($this->spend('50000.00'), $approver);

        // And a payout, so block C is not zero.
        $payouts = app(PayoutService::class);
        $payout = $payouts->createFor($partner, new PayoutRequestData(
            requestedAmount: '12000.00', method: PayoutMethod::BankTransfer,
        ), $approver);

        $payouts->approve($payout, $approver);
        $payouts->markPaid($payout->refresh(), new MarkPaidData(transactionId: 'BANK-PL-1'), $approver);

        $report = app(FinanceReportService::class)->report(FinanceReportType::ProfitLoss, $this->range);

        $this->assertSame('200000.00', $report->totals['income']);
        $this->assertSame('50000.00', $report->totals['expenses']);
        $this->assertSame('150000.00', $report->totals['before_commission']);

        // The figure in block C **is** the spine's company-wide call, not a sum taken here.
        $this->assertSame(
            app(CollaboratorWalletService::class)->payoutsPaidTotal(null, $this->range),
            $report->totals['commission_paid'],
        );
        $this->assertSame('12000.00', $report->totals['commission_paid']);
        $this->assertSame('138000.00', $report->totals['net_profit']);

        // Accrued commission is a memo, deliberately in neither line: what the business became liable
        // for is a different question from what it paid out.
        $this->assertSame(
            app(CollaboratorStatementService::class)->commissionAccruedTotal(null, $this->range),
            $report->meta['commission_accrued_memo'],
        );
        $this->assertSame('20000.00', $report->meta['commission_accrued_memo']);

        $this->assertStringContainsString('do not also record them as expenses',
            strtolower($report->meta['note']));
    }

    /** The salary line is one category, and it is the one payroll posts into. */
    #[Test]
    public function the_salary_line_comes_from_the_reserved_category(): void
    {
        $approver = $this->createSuperAdmin();

        app(ExpenseService::class)->approve($this->spend('400000.00', [
            'category' => $this->expenseCategory('salaries'), 'title' => 'Payroll for April', 'key' => 'pay',
        ]), $approver);

        app(ExpenseService::class)->approve($this->spend('9000.00', ['key' => 'other']), $approver);

        $report = app(FinanceReportService::class)->report(FinanceReportType::ProfitLoss, $this->range);

        $salaries = collect($report->rows)->firstWhere('label', '… of which salaries');

        $this->assertSame('400000.00', $salaries['amount']);
        $this->assertTrue($salaries['is_memo'], 'A memo line under block B, never a second subtraction.');
        $this->assertSame('409000.00', $report->totals['expenses']);
    }

    /*
    |--------------------------------------------------------------------------
    | Receivables aging
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_aging_report_buckets_by_how_late_a_claim_is(): void
    {
        $actor = $this->createSuperAdmin();
        $client = $this->billingClient('Late Payer Ltd');

        foreach ([['on' => 5, 'amount' => '10000.00'], ['on' => 45, 'amount' => '20000.00'], ['on' => 200, 'amount' => '30000.00']] as $spec) {
            $invoice = $this->draftInvoice($client, [$this->line($spec['amount'])], [
                'issue_date' => now()->subDays($spec['on'] + 14)->toDateString(),
                'due_date' => now()->subDays($spec['on'])->toDateString(),
            ]);

            app(InvoiceService::class)->markSent($invoice, ['late@example.test'], $actor);
        }

        $report = app(FinanceReportService::class)->report(FinanceReportType::ReceivablesAging, $this->range);

        $this->assertCount(1, $report->rows);

        $row = $report->rows[0];

        $this->assertSame('Late Payer Ltd', $row['client']);
        $this->assertSame('60000.00', $row['outstanding']);
        $this->assertSame('10000.00', $row['d1_30']);
        $this->assertSame('20000.00', $row['d31_60']);
        $this->assertSame('30000.00', $row['d90_plus']);
        $this->assertSame(3, $row['invoices']);
    }

    /** Showing the credits is what stops the report overstating what a client owes. */
    #[Test]
    public function an_unapplied_advance_reduces_the_net_exposure(): void
    {
        $actor = $this->createSuperAdmin();
        $client = $this->billingClient('Prepaying Ltd');

        app(InvoiceService::class)->markSent(
            $this->draftInvoice($client, [$this->line('50000.00')], [
                'issue_date' => now()->subDays(20)->toDateString(),
                'due_date' => now()->subDays(6)->toDateString(),
            ]),
            ['prepay@example.test'], $actor,
        );

        // An advance the client paid before the invoice existed: a receipt with no invoice attached.
        // Written directly because the spine's own recorder would fire the commission engine, and this
        // test is about the aging report rather than about a referral.
        $projectId = DB::table('projects')->insertGetId([
            'code' => 'PRJ-AGE-1',
            'name' => 'Prepaid work',
            'client_id' => $client->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('project_payments')->insert([
            'payment_no' => 'PP-ADV-1',
            'idempotency_key' => 'adv-1',
            'duplicate_fingerprint' => 'adv-1-fp',
            'project_id' => $projectId,
            'client_id' => $client->getKey(),
            'amount' => '18000.00',
            'refunded_amount' => '0.00',
            'payment_method' => 'bank_transfer',
            'status' => 'cleared',
            'paid_on' => now()->subDays(30)->toDateString(),
            'recorded_at' => now(),
            'is_advance' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = app(FinanceReportService::class)
            ->report(FinanceReportType::ReceivablesAging, $this->range)->rows[0];

        $this->assertSame('50000.00', $row['outstanding']);
        $this->assertSame('18000.00', $row['unapplied_credits']);
        $this->assertSame('32000.00', $row['net_exposure'],
            'Exposure is what is owed less what the business is already holding.');
    }

    #[Test]
    public function a_draft_or_cancelled_invoice_is_never_in_the_aging_report(): void
    {
        $actor = $this->createSuperAdmin();
        $client = $this->billingClient('Quiet Ltd');

        // A draft: never sent, so nobody owes anything.
        $this->draftInvoice($client, [$this->line('90000.00')]);

        $cancelled = app(InvoiceService::class)->markSent(
            $this->draftInvoice($client, [$this->line('70000.00')]), ['quiet@example.test'], $actor,
        );

        app(InvoiceService::class)->cancel($cancelled, 'Client withdrew the order', $actor);

        $report = app(FinanceReportService::class)->report(FinanceReportType::ReceivablesAging, $this->range);

        $this->assertSame([], $report->rows);
        $this->assertSame('0.00', $report->totals['outstanding']);
    }

    /*
    |--------------------------------------------------------------------------
    | What a reader may see
    |--------------------------------------------------------------------------
    */

    /** A withheld money column is absent from the list, never a blank cell. */
    #[Test]
    public function money_columns_disappear_without_the_financial_permission(): void
    {
        $reader = $this->createUserWithPermissions(['invoices.view_any']);
        $accountant = $this->createUserWithPermissions(['invoices.view_any', 'invoices.view_financial']);

        $withheld = FinanceVisibility::for($reader, 'invoices');
        $full = FinanceVisibility::for($accountant, 'invoices');

        $this->assertFalse($withheld->seesMoney);
        $this->assertFalse($withheld->may('total_amount'));
        $this->assertNotContains('total_amount', $withheld->columns());
        $this->assertContains('invoice_number', $withheld->columns(), 'The register itself still opens.');

        $this->assertTrue($full->may('total_amount'));
        $this->assertContains('balance_amount', $full->moneyColumns());

        $row = ['invoice_number' => 'INV-000001', 'client' => 'Acme', 'total_amount' => '10000.00'];

        $this->assertArrayNotHasKey('total_amount', $withheld->filter($row));
        $this->assertArrayHasKey('total_amount', $full->filter($row));
    }

    #[Test]
    public function every_finance_module_declares_a_visibility_shape(): void
    {
        foreach (FinanceVisibility::modules() as $module) {
            $set = FinanceVisibility::for(null, $module);

            $this->assertNotSame([], $set->columns(), $module.' declares no columns.');
            $this->assertNotSame([], $set->money, $module.' declares no money columns to withhold.');
        }
    }

    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
