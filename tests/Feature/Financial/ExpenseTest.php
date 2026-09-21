<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\DataObjects\Finance\ExpenseData;
use App\DataObjects\Finance\RefundData;
use App\Enums\ExpenseStatus;
use App\Enums\FinanceContext;
use App\Enums\IncomeStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReversalType;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceReversal;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\IncomeService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * Money the business spends and the money in that is not a payment (§29, §30, phase-13 §6.4).
 *
 * The rules worth proving are the ones a report depends on. **Only an approved expense counts**, so a
 * pending claim never moves a profit figure. **The approval decision is snapshotted**, so changing the
 * threshold next month does not silently re-open what was already decided. **Nothing is deleted** once
 * it has been approved — the corrections are a void and a `finance_reversals` row, both of which leave
 * the original visible. And **`net_amount` is generated**, so no report can forget a refund.
 */
final class ExpenseTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('finance.expense_approval_required', true);
        $this->setting('finance.expense_approval_threshold', '0.00');
        $this->setting('finance.expense_self_approval_allowed', false);
        $this->setting('finance.backdate_limit_days', 30);
        $this->setting('finance.expense_prefix', 'EXP-');
        $this->setting('finance.income_prefix', 'INC-');
    }

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_expense_is_numbered_and_starts_pending_when_approval_is_on(): void
    {
        $expense = $this->spend('50000.00');

        $this->assertSame('EXP-000001', $expense->expense_no);
        $this->assertSame(ExpenseStatus::Pending, $expense->status);
        $this->assertTrue((bool) $expense->approval_required);
        $this->assertSame('50000.00', (string) $expense->net_amount);
    }

    /** The threshold decides, and the decision is recorded on the row rather than re-read later. */
    #[Test]
    public function the_approval_decision_is_snapshotted_at_creation(): void
    {
        $this->setting('finance.expense_approval_threshold', '10000.00');

        $small = $this->spend('5000.00', ['key' => 'small']);
        $large = $this->spend('25000.00', ['key' => 'large']);

        $this->assertSame(ExpenseStatus::Approved, $small->status);
        $this->assertFalse((bool) $small->approval_required);
        $this->assertSame(ExpenseStatus::Pending, $large->status);

        // Lowering the threshold must not reach back and make the small one look like it skipped a step.
        $this->setting('finance.expense_approval_threshold', '100.00');

        $this->assertSame(ExpenseStatus::Approved, $small->fresh()->status);
        $this->assertFalse((bool) $small->fresh()->approval_required);
    }

    #[Test]
    public function a_replayed_form_records_one_expense(): void
    {
        $first = $this->spend('1000.00', ['key' => 'same-click']);
        $second = $this->spend('1000.00', ['key' => 'same-click']);

        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame(1, Expense::query()->count());
    }

    #[Test]
    public function an_income_category_on_an_expense_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not an expense category/');

        app(ExpenseService::class)->record(new ExpenseData(
            financeCategoryId: (int) $this->incomeCategory()->getKey(),
            context: FinanceContext::General,
            title: 'Wrong side of the books',
            amount: '100.00',
            valueDate: Carbon::now(),
            paymentMethod: PaymentMethod::Cash,
        ));
    }

    #[Test]
    public function a_future_dated_expense_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/has not gone out yet/');

        $this->spend('100.00', ['on' => now()->addDay()->toDateString()]);
    }

    #[Test]
    public function a_heavily_back_dated_expense_needs_the_approve_permission(): void
    {
        $nobody = $this->createUserWithPermissions(['expenses.create']);

        try {
            $this->spend('100.00', ['on' => now()->subDays(90)->toDateString(), 'actor' => $nobody]);
            $this->fail('A 90-day back-date was accepted without the approve permission.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('already have reported on', $e->getMessage());
        }

        $approver = $this->createUserWithPermissions(['expenses.create', 'expenses.approve']);

        $backdated = $this->spend('100.00', [
            'on' => now()->subDays(90)->toDateString(), 'actor' => $approver, 'key' => 'backdated',
        ]);

        $this->assertSame(now()->subDays(90)->toDateString(), $backdated->expense_date->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Deciding
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function nobody_approves_their_own_expense_unless_the_business_says_so(): void
    {
        $actor = $this->createUserWithPermissions(['expenses.create', 'expenses.approve']);
        $expense = $this->spend('5000.00', ['actor' => $actor]);

        try {
            app(ExpenseService::class)->approve($expense, $actor);
            $this->fail('Somebody approved their own expense.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('cannot also approve it', $e->getMessage());
        }

        $this->setting('finance.expense_self_approval_allowed', true);

        $this->assertSame(ExpenseStatus::Approved, app(ExpenseService::class)->approve($expense->fresh(), $actor)->status);
    }

    /** Idempotent, so a double-clicked bulk action cannot double-count. */
    #[Test]
    public function approving_twice_changes_nothing_the_second_time(): void
    {
        $expense = $this->spend('5000.00');
        $approver = $this->createSuperAdmin();

        $first = app(ExpenseService::class)->approve($expense, $approver);
        $second = app(ExpenseService::class)->approve($first, $approver);

        $this->assertEquals($first->approved_at, $second->approved_at);
        $this->assertSame((int) $approver->getKey(), (int) $second->approved_by);
    }

    #[Test]
    public function rejecting_needs_a_reason_and_keeps_the_row(): void
    {
        $expense = $this->spend('5000.00');
        $approver = $this->createSuperAdmin();

        try {
            app(ExpenseService::class)->reject($expense, '   ', $approver);
            $this->fail('An expense was rejected with no reason.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('what they are told', $e->getMessage());
        }

        $rejected = app(ExpenseService::class)->reject($expense, 'No receipt and no explanation', $approver);

        $this->assertSame(ExpenseStatus::Rejected, $rejected->status);
        $this->assertSame('No receipt and no explanation', $rejected->rejection_reason);
        $this->assertFalse($rejected->status->countsInReports());
        $this->assertSame(1, Expense::query()->count(), 'The row stays — a rejection is a decision, not a deletion.');
    }

    #[Test]
    public function a_bulk_approval_names_its_rows_and_skips_what_moved(): void
    {
        $approver = $this->createSuperAdmin();

        $first = $this->spend('1000.00', ['key' => 'a']);
        $second = $this->spend('2000.00', ['key' => 'b']);
        $alreadyRejected = $this->spend('3000.00', ['key' => 'c']);

        app(ExpenseService::class)->reject($alreadyRejected, 'Duplicate of another claim', $approver);

        $result = app(ExpenseService::class)->approveMany(
            [(int) $first->getKey(), (int) $second->getKey(), (int) $alreadyRejected->getKey()],
            $approver,
        );

        $this->assertCount(2, $result->changed);
        $this->assertSame('3000.00', $result->total);
        $this->assertArrayHasKey((int) $alreadyRejected->getKey(), $result->skipped);
        $this->assertStringContainsString('moved after the page was loaded',
            $result->skipped[(int) $alreadyRejected->getKey()]);

        $this->assertSame(ExpenseStatus::Rejected, $alreadyRejected->fresh()->status,
            'A row that moved is reported, never forced.');
    }

    /*
    |--------------------------------------------------------------------------
    | Undoing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_partial_refund_moves_the_generated_net_amount(): void
    {
        $approver = $this->createSuperAdmin();
        $expense = app(ExpenseService::class)->approve($this->spend('50000.00'), $approver);

        $reversal = app(ExpenseService::class)->refund($expense, new RefundData(
            amount: '12000.00',
            reason: 'Supplier credited part of the invoice',
            type: ReversalType::PartialRefund,
        ), $approver);

        $expense->refresh();

        $this->assertSame('12000.00', (string) $expense->refunded_amount);
        $this->assertSame('38000.00', (string) $expense->net_amount,
            'Generated, so every report picks the refund up without remembering to.');
        $this->assertSame(ExpenseStatus::Approved, $expense->status, 'Part of it is still the company\'s cost.');
        $this->assertSame(setting('finance.payment_reversal_prefix').'000001', $reversal->reversal_no,
            'The same voucher series as the spine\'s payment reversals: one sequence, not two that look alike.');
    }

    #[Test]
    public function refunding_more_than_was_spent_is_refused(): void
    {
        $approver = $this->createSuperAdmin();
        $expense = app(ExpenseService::class)->approve($this->spend('10000.00'), $approver);

        app(ExpenseService::class)->refund($expense, new RefundData(
            amount: '6000.00', reason: 'Partly returned', type: ReversalType::PartialRefund,
        ), $approver);

        try {
            app(ExpenseService::class)->refund($expense->fresh(), new RefundData(
                amount: '6000.00', reason: 'And again', type: ReversalType::PartialRefund,
            ), $approver);

            $this->fail('An expense was refunded past what was spent.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Money cannot come back twice', $e->getMessage());
        }

        $this->assertSame('6000.00', (string) $expense->fresh()->refunded_amount);
    }

    /** A full refund closes the row, with the reversal's own reason as the void reason. */
    #[Test]
    public function a_full_refund_voids_the_expense_and_carries_one_explanation(): void
    {
        $approver = $this->createSuperAdmin();
        $expense = app(ExpenseService::class)->approve($this->spend('8000.00'), $approver);

        app(ExpenseService::class)->refund($expense, new RefundData(
            amount: '8000.00',
            reason: 'Order cancelled, supplier refunded in full',
            type: ReversalType::FullRefund,
        ), $approver);

        $expense->refresh();

        $this->assertSame(ExpenseStatus::Voided, $expense->status);
        $this->assertSame('0.00', (string) $expense->net_amount);
        $this->assertStringContainsString('Order cancelled', (string) $expense->void_reason);
        $this->assertFalse($expense->status->countsInReports());
    }

    #[Test]
    public function a_finance_reversal_can_never_be_deleted(): void
    {
        $approver = $this->createSuperAdmin();
        $expense = app(ExpenseService::class)->approve($this->spend('4000.00'), $approver);

        $reversal = app(ExpenseService::class)->refund($expense, new RefundData(
            amount: '1000.00', reason: 'Part returned', type: ReversalType::PartialRefund,
        ), $approver);

        try {
            $reversal->delete();
            $this->fail('A finance reversal was deleted.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('corrected by a further row', $e->getMessage());
        }

        $this->assertSame(1, FinanceReversal::query()->count());
    }

    #[Test]
    public function a_replayed_refund_refunds_once(): void
    {
        $approver = $this->createSuperAdmin();
        $expense = app(ExpenseService::class)->approve($this->spend('9000.00'), $approver);

        $data = new RefundData(
            amount: '3000.00', reason: 'Returned', type: ReversalType::PartialRefund,
            idempotencyKey: 'one-click',
        );

        app(ExpenseService::class)->refund($expense, $data, $approver);
        app(ExpenseService::class)->refund($expense->fresh(), $data, $approver);

        $this->assertSame(1, FinanceReversal::query()->count());
        $this->assertSame('3000.00', (string) $expense->fresh()->refunded_amount);
    }

    #[Test]
    public function recomputing_the_cache_agrees_with_the_reversals(): void
    {
        $approver = $this->createSuperAdmin();
        $expense = app(ExpenseService::class)->approve($this->spend('20000.00'), $approver);

        foreach (['2000.00', '3000.00'] as $index => $amount) {
            app(ExpenseService::class)->refund($expense->fresh(), new RefundData(
                amount: $amount, reason: 'Instalment '.$index, type: ReversalType::PartialRefund,
                idempotencyKey: 'rev-'.$index,
            ), $approver);
        }

        // Nudge the cache out of step the way a killed worker would.
        DB::table('expenses')->where('id', $expense->getKey())->update(['refunded_amount' => '1.00']);

        $this->assertSame('5000.00', (string) app(ExpenseService::class)->recomputeCaches($expense)->refunded_amount);
        $this->assertSame('15000.00', (string) $expense->fresh()->net_amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Other income
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function other_income_needs_no_approval_and_voids_with_a_reason(): void
    {
        $income = $this->receiveOther('25000.00');

        $this->assertSame('INC-000001', $income->income_no);
        $this->assertSame(IncomeStatus::Recorded, $income->status);
        $this->assertTrue($income->status->countsInReports());

        $actor = $this->createSuperAdmin();

        try {
            app(IncomeService::class)->void($income, '', $actor);
            $this->fail('A receipt was voided with no reason.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('may already have read', $e->getMessage());
        }

        $voided = app(IncomeService::class)->void($income, 'Recorded against the wrong client', $actor);

        $this->assertSame(IncomeStatus::Voided, $voided->status);
        $this->assertFalse($voided->status->countsInReports());
    }

    #[Test]
    public function an_expense_category_on_a_receipt_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not an income category/');

        app(IncomeService::class)->record(new ExpenseData(
            financeCategoryId: (int) $this->expenseCategory()->getKey(),
            context: FinanceContext::General,
            title: 'Wrong side again',
            amount: '100.00',
            valueDate: Carbon::now(),
            paymentMethod: PaymentMethod::Cash,
        ));
    }

    #[Test]
    public function income_and_expense_reversals_share_one_voucher_series(): void
    {
        $actor = $this->createSuperAdmin();

        $expense = app(ExpenseService::class)->approve($this->spend('5000.00'), $actor);
        $income = $this->receiveOther('4000.00');

        $first = app(ExpenseService::class)->refund($expense, new RefundData(
            amount: '1000.00', reason: 'Supplier credit', type: ReversalType::PartialRefund,
        ), $actor);

        $second = app(IncomeService::class)->refund($income, new RefundData(
            amount: '500.00', reason: 'Returned to the payer', type: ReversalType::PartialRefund,
        ), $actor);

        $prefix = (string) setting('finance.payment_reversal_prefix');

        $this->assertSame($prefix.'000001', $first->reversal_no);
        $this->assertSame($prefix.'000002', $second->reversal_no,
            'One sequence across money spent and money received: an auditor follows one, not two.');

        $this->assertSame('3500.00', (string) $income->fresh()->net_amount);
    }

    /** The reserved category is what payroll posts into, and it cannot be removed. */
    #[Test]
    public function the_salaries_category_cannot_be_deleted(): void
    {
        $salaries = $this->expenseCategory('salaries');

        $this->assertTrue($salaries->isReserved());
        $this->assertFalse($salaries->isDeactivatable());

        $this->expectExceptionMessageMatches('/largest line/');

        $salaries->delete();
    }

    /** `uq_exp_source` is the guard, so a replayed payroll event cannot post twice. */
    #[Test]
    public function a_replayed_payroll_run_cannot_post_a_second_salary_expense(): void
    {
        $category = $this->expenseCategory('salaries');

        $make = fn (string $key): Expense => app(ExpenseService::class)->record(new ExpenseData(
            financeCategoryId: (int) $category->getKey(),
            context: FinanceContext::General,
            title: 'Payroll for April',
            amount: '400000.00',
            valueDate: Carbon::now(),
            paymentMethod: PaymentMethod::BankTransfer,
            idempotencyKey: $key,
            sourceType: Expense::SOURCE_PAYROLL_RUN,
            sourceId: 7,
        ));

        $first = $make('payroll-7');

        $this->assertTrue($first->isDerived());

        // A different idempotency key — a genuinely replayed *event*, not a resubmitted form.
        $this->expectException(UniqueConstraintViolationException::class);

        $make('payroll-7-again');
    }

    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
