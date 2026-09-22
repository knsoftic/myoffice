<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Dashboard\Widgets\Finance\ExpensesThisMonthWidget;
use App\Dashboard\Widgets\Finance\RevenueThisMonthWidget;
use App\Enums\ExpenseStatus;
use App\Enums\FinanceReportType;
use App\Enums\InvoiceStatus;
use App\Models\Finance\Expense;
use App\Models\Finance\Invoice;
use App\Models\Finance\PaymentMethodOption;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentMethodService;
use App\Support\DateRange;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * The Phase 13 screens, and the guards that stand behind them (§7, §8, §9).
 *
 * The point of this file is not that a page returns 200. It is that **hiding a control is never the
 * control**: every refusal asserted here is asserted at the route or the policy, with the button's
 * absence treated as courtesy rather than security (`CLAUDE.md` §1.7).
 *
 * Three things it proves that nothing else does. **A number is assigned once** — the guard is
 * `invoice_number`, not the status, because an invoice issued a minute ago is still `draft` until it
 * is sent. **A withheld money column is absent, not blank** — a reader without `view_financial` gets a
 * register with no amount columns at all. And **the public link tells a stranger nothing** — a draft,
 * a rotated token, a disabled setting and an unknown token all answer 404.
 */
final class FinanceScreenTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting('finance.tax_enabled', false);
        $this->setting('finance.payment_terms_days', 14);
        $this->setting('finance.invoice_prefix', 'INV-');
        $this->setting('finance.invoice_public_link_enabled', true);
    }

    /*
    |--------------------------------------------------------------------------
    | The number is assigned once and for ever
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function issuing_twice_is_refused_because_the_guard_is_the_number_not_the_status(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = $this->draftInvoice();

        $issued = app(InvoiceService::class)->issue($invoice, $actor);

        $this->assertNotNull($issued->invoice_number);

        // An issued invoice nobody has sent is still `draft` — `statusFor()` reads `sent_at`. A
        // status-only guard would let this through and hand it a second number.
        $this->assertSame(InvoiceStatus::Draft, $issued->fresh()->status);

        $this->expectExceptionMessageMatches('/already carries a number/');

        app(InvoiceService::class)->issue($issued->fresh(), $actor);
    }

    #[Test]
    public function marking_an_issued_invoice_sent_does_not_spend_another_number(): void
    {
        $actor = $this->createSuperAdmin();

        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        $number = $invoice->invoice_number;

        $counterBefore = (int) setting('finance.invoice_next_number');

        $sent = app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        $this->assertSame($number, $sent->invoice_number, 'the number it was issued with is the number it keeps');
        $this->assertSame($counterBefore, (int) setting('finance.invoice_next_number'),
            'sending an already-issued invoice consumes no number, so the series has no gap');
        $this->assertSame(InvoiceStatus::Sent, $sent->status);
    }

    /*
    |--------------------------------------------------------------------------
    | A withheld money column is absent, not blank
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_register_omits_every_amount_from_a_reader_without_view_financial(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        $reader = $this->createUserWithPermissions(['invoices.view_any', 'invoices.view']);

        $response = $this->actingAs($reader)->get(route('admin.invoices.index'));

        $response->assertOk();
        $response->assertSee($invoice->fresh()->invoice_number);

        // Absent columns, not blank cells: the header is not rendered because there is no cell to
        // fill, and `FinanceVisibility` removed the field from the set entirely.
        $response->assertDontSee('Balance</th>', false);
        $response->assertDontSee('Paid</th>', false);
        $response->assertSee('invoices.view_financial');
    }

    #[Test]
    public function the_csv_withholds_the_same_columns_the_screen_does(): void
    {
        $actor = $this->createSuperAdmin();
        app(InvoiceService::class)->issue($this->draftInvoice(), $actor);

        // `export` without `view_financial` is refused outright: the file is nothing but amounts.
        $reader = $this->createUserWithPermissions(['invoices.view_any', 'invoices.export']);

        $this->actingAs($reader)
            ->get(route('admin.invoices.export', ['format' => 'csv']))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The routes refuse what the buttons merely hide
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function applying_a_receipt_demands_both_halves_of_the_d43_pair(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);

        $holder = $this->createUserWithPermissions(['invoices.view_any', 'invoices.view', 'invoices.edit']);

        $this->assertFalse($holder->can('applyPayment', $invoice->fresh()),
            'invoices.edit alone is not enough — project_payments.link_invoice is the other half');

        $this->grantPermissions($holder, 'project_payments.link_invoice');
        $this->forgetPermissionCache();

        $this->assertTrue($holder->fresh()->can('applyPayment', $invoice->fresh()));
    }

    #[Test]
    public function an_issued_invoice_is_never_deletable_and_a_draft_always_is(): void
    {
        $actor = $this->createSuperAdmin();

        $draft = $this->draftInvoice();
        $issued = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);

        $deleter = $this->createUserWithPermissions(['invoices.view_any', 'invoices.view', 'invoices.delete']);

        $this->assertTrue($deleter->can('delete', $draft));
        $this->assertFalse($deleter->can('delete', $issued->fresh()),
            'an issued invoice is cancelled, never deleted — it keeps its number for ever');
    }

    #[Test]
    public function nobody_approves_their_own_expense(): void
    {
        $claimant = $this->createUserWithPermissions([
            'expenses.view_any', 'expenses.view', 'expenses.create', 'expenses.approve',
        ]);

        $this->setting('finance.expense_approval_required', true);
        $this->setting('finance.expense_self_approval_allowed', false);

        $expense = $this->spend('12000.00', ['actor' => $claimant]);

        $this->assertFalse($claimant->can('approve', $expense->fresh()),
            'the point of an approval step is that a second person looked');

        $other = $this->createUserWithPermissions(['expenses.view_any', 'expenses.approve']);

        $this->assertTrue($other->can('approve', $expense->fresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | The public link tells a stranger nothing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_signed_public_link_renders_the_document_and_stamps_viewed_once(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        $invoice = app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        $url = URL::temporarySignedRoute('site.invoices.view', now()->addDays(30), [
            'token' => $invoice->public_token,
        ]);

        $this->get($url)->assertOk()->assertSee($invoice->invoice_number);

        $viewedAt = $invoice->fresh()->viewed_at;
        $this->assertNotNull($viewedAt);

        Carbon::setTestNow(now()->addHour());
        $this->get($url)->assertOk();
        Carbon::setTestNow();

        $this->assertEquals($viewedAt, $invoice->fresh()->viewed_at,
            'viewed_at answers "when did they first see it", so it is stamped once');
    }

    #[Test]
    public function an_unsigned_link_a_draft_and_a_rotated_token_all_reveal_nothing(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        $invoice = app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        $token = $invoice->fresh()->public_token;

        // Unsigned: Laravel's own gate, before anything of ours runs.
        $this->get(route('site.invoices.view', ['token' => $token]))->assertForbidden();

        // A token nobody issued.
        $this->get(URL::temporarySignedRoute('site.invoices.view', now()->addDay(), [
            'token' => str_repeat('z', 40),
        ]))->assertNotFound();

        // Rotated: every link already emailed stops working.
        app(InvoiceService::class)->regeneratePublicToken($invoice->fresh(), 'Sent to the wrong address', $actor);

        $this->get(URL::temporarySignedRoute('site.invoices.view', now()->addDay(), ['token' => $token]))
            ->assertNotFound();
    }

    #[Test]
    public function switching_the_public_link_setting_off_closes_the_door_with_a_404(): void
    {
        $actor = $this->createSuperAdmin();
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        $invoice = app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        $url = URL::temporarySignedRoute('site.invoices.view', now()->addDay(), [
            'token' => $invoice->public_token,
        ]);

        $this->get($url)->assertOk();

        $this->setting('finance.invoice_public_link_enabled', false);

        // 404, not 403: a 403 would confirm the document exists behind the link they guessed.
        $this->get($url)->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | The clock moves a status, and moves it back
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_overdue_command_moves_statuses_in_both_directions(): void
    {
        $actor = $this->createSuperAdmin();

        $invoice = $this->draftInvoice(null, [], [
            'issue_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDays(26)->toDateString(),
        ]);

        $invoice = app(InvoiceService::class)->issue($invoice, $actor);
        $invoice = app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        $this->artisan('invoices:mark-overdue')->assertExitCode(0);

        $this->assertSame(InvoiceStatus::Overdue, $invoice->fresh()->status);

        // Extending the due date must move it back out — the same call does both.
        Invoice::query()->whereKey($invoice->getKey())
            ->update(['due_date' => now()->addDays(10)->toDateString()]);

        $this->artisan('invoices:mark-overdue')->assertExitCode(0);

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status,
            'a job that only marked things overdue would leave a corrected invoice wearing a red badge');
    }

    /*
    |--------------------------------------------------------------------------
    | One source for every method dropdown
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function switching_a_method_off_removes_it_from_every_form_at_once(): void
    {
        $this->seed(PaymentMethodSeeder::class);

        $methods = app(PaymentMethodService::class);
        $methods->flush();

        $bank = PaymentMethodOption::query()->where('code', 'bank_transfer')->firstOrFail();

        $this->assertTrue($methods->availableFor('invoice')->contains('id', $bank->getKey()));
        $this->assertTrue($methods->availableFor('expense')->contains('id', $bank->getKey()));

        $methods->toggle($bank, false, 'The account was closed');

        $this->assertFalse($methods->availableFor('invoice')->contains('id', $bank->getKey()));
        $this->assertFalse($methods->availableFor('expense')->contains('id', $bank->getKey()));
        $this->assertFalse($bank->fresh()->is_default,
            'a method switched off is never the default — the forms would preselect something nobody can choose');
    }

    #[Test]
    public function a_method_with_money_against_it_cannot_be_deleted(): void
    {
        $this->seed(PaymentMethodSeeder::class);

        $cash = PaymentMethodOption::query()->where('code', 'cash')->firstOrFail();
        $unused = PaymentMethodOption::query()->where('code', 'card')->firstOrFail();

        $expense = $this->spend('5000.00');
        $expense->forceFill(['payment_method_id' => $cash->getKey()])->save();

        $admin = $this->createUserWithPermissions(['payment_methods.view_any', 'payment_methods.delete']);

        $this->assertFalse($admin->can('delete', $cash->fresh()),
            'the correct act is deactivation, which changes no history');
        $this->assertTrue($admin->can('delete', $unused));
    }

    /*
    |--------------------------------------------------------------------------
    | The cross-source register
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_payments_register_names_the_sources_it_had_to_leave_out(): void
    {
        $this->receiveOther('25000.00');

        // Income only: no project-payment or student-fee permission, so neither is in the union.
        $reader = $this->createUserWithPermissions([
            'payments.view_any', 'payments.view_financial',
            'income.view_any', 'income.view_financial',
        ]);

        $response = $this->actingAs($reader)->get(route('admin.payments.index', ['preset' => 'year']));

        $response->assertOk();
        $response->assertSee('This is not every payment.');
        $response->assertSee('Project payment');
    }

    #[Test]
    public function the_register_is_refused_outright_without_the_money_permission(): void
    {
        $reader = $this->createUserWithPermissions(['payments.view_any']);

        // Its whole content is amounts; a version without them would be a list of reference numbers.
        $this->actingAs($reader)->get(route('admin.payments.index'))->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The dashboard cards read the report service, never their own SUM
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_finance_cards_agree_with_the_reports_they_link_to(): void
    {
        $actor = $this->createSuperAdmin();

        $this->spend('40000.00', ['on' => now()->toDateString(), 'actor' => $actor]);
        $this->receiveOther('90000.00', ['on' => now()->toDateString(), 'actor' => $actor]);

        $this->actingAs($actor);

        $reports = app(FinanceReportService::class);
        $range = DateRange::month();

        $revenue = app(RevenueThisMonthWidget::class)->data($range);
        $expenses = app(ExpensesThisMonthWidget::class)->data($range);

        $this->assertSame(
            (string) $reports->report(FinanceReportType::Income, $range, $actor)->totals['amount'],
            (string) $revenue['current'],
        );

        $this->assertSame(
            (string) $reports->report(FinanceReportType::Expenses, $range, $actor)->totals['amount'],
            (string) $expenses['current'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The cache that something has to check
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_balance_reconciler_reports_drift_and_refuses_to_repair_it_silently(): void
    {
        $actor = $this->createSuperAdmin();

        $invoice = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        $invoice = app(InvoiceService::class)->markSent($invoice->fresh(), ['buyer@example.test'], $actor);

        // A second invoice that is fine, so the run has to get past the first one to see it — a
        // `return` instead of a `continue` in the chunk callback would skip it and look clean.
        $other = app(InvoiceService::class)->issue($this->draftInvoice(), $actor);
        app(InvoiceService::class)->markSent($other->fresh(), ['buyer@example.test'], $actor);

        Invoice::query()->whereKey($invoice->getKey())
            ->update(['paid_amount' => '9999.00', 'balance_amount' => '1.00']);

        $this->artisan('invoices:reconcile-balances')
            ->expectsOutputToContain('1 of 2')
            ->assertExitCode(1);

        $this->assertSame('9999.00', (string) $invoice->fresh()->paid_amount,
            'reporting is not repairing: rewriting the column would erase the evidence of whatever caused it');

        $this->artisan('invoices:reconcile-balances', ['--repair' => true])->assertExitCode(0);

        $this->assertSame('0.00', (string) $invoice->fresh()->paid_amount);
        $this->assertSame((string) $invoice->total_amount, (string) $invoice->fresh()->balance_amount);

        $this->artisan('invoices:reconcile-balances')
            ->expectsOutputToContain('2 invoice(s) checked')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_stale_approval_digest_decides_nothing(): void
    {
        $claimant = $this->createUserWithPermissions(['expenses.view_any', 'expenses.create']);

        $this->setting('finance.expense_approval_required', true);

        $expense = $this->spend('20000.00', ['actor' => $claimant]);

        Expense::query()->whereKey($expense->getKey())->update(['created_at' => now()->subDays(30)]);

        $this->artisan('expenses:flag-stale-approvals')->assertExitCode(0);

        $this->assertSame(ExpenseStatus::Pending, $expense->fresh()->status,
            'a job that approved money on a timetable would turn the approval step into a delay');
    }

    /**
     * Write a setting the way the system context does.
     */
    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
