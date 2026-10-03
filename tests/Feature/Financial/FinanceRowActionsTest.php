<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\User;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\IncomeService;
use App\Services\Finance\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * Edit and Delete on the expense, income and invoice registers and pages (CLAUDE.md §1.3).
 *
 * Delete exists only where nothing has been decided: a pending expense, a recorded income nothing was
 * refunded or voided against, an unissued draft invoice with no receipt. Everything past that point is
 * voided, reversed or cancelled — never deleted — and the route answers 403 even for Super Admin, who
 * passes every policy.
 */
final class FinanceRowActionsTest extends TestCase
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
        $this->setting('finance.expense_prefix', 'EXP-');
        $this->setting('finance.income_prefix', 'INC-');
        $this->setting('finance.invoice_prefix', 'INV-');
        $this->setting('finance.tax_enabled', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Expenses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_pending_expense_offers_edit_and_delete_and_can_be_deleted(): void
    {
        $clerk = $this->clerk('expenses');
        $pending = $this->spend('5000.00');

        $this->actingAs($clerk)->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee(route('admin.expenses.edit', $pending), false)
            ->assertSee($this->deleteForm('admin.expenses.destroy', $pending), false);

        $this->actingAs($clerk)->get(route('admin.expenses.show', $pending))
            ->assertOk()
            ->assertSee($this->deleteForm('admin.expenses.destroy', $pending), false);

        $this->actingAs($clerk)->delete(route('admin.expenses.destroy', $pending))
            ->assertRedirect(route('admin.expenses.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('expenses', ['id' => $pending->getKey()]);
    }

    #[Test]
    public function an_approved_expense_offers_no_delete_and_the_route_refuses_it(): void
    {
        $clerk = $this->clerk('expenses');
        $approved = app(ExpenseService::class)->approve($this->spend('5000.00'), $this->createSuperAdmin());

        $this->actingAs($clerk)->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertDontSee($this->deleteForm('admin.expenses.destroy', $approved), false);

        $this->actingAs($clerk)->get(route('admin.expenses.show', $approved))
            ->assertOk()
            ->assertDontSee($this->deleteForm('admin.expenses.destroy', $approved), false);

        $this->actingAs($clerk)->delete(route('admin.expenses.destroy', $approved))->assertForbidden();
        $this->actingAs($this->createSuperAdmin())->delete(route('admin.expenses.destroy', $approved))->assertForbidden();

        $this->assertNotSoftDeleted('expenses', ['id' => $approved->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Other income
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_untouched_income_offers_edit_and_delete_and_can_be_deleted(): void
    {
        $clerk = $this->clerk('income');
        $income = $this->receiveOther('2500.00');

        $this->actingAs($clerk)->get(route('admin.income.index'))
            ->assertOk()
            ->assertSee(route('admin.income.edit', $income), false)
            ->assertSee($this->deleteForm('admin.income.destroy', $income), false);

        $this->actingAs($clerk)->get(route('admin.income.show', $income))
            ->assertOk()
            ->assertSee($this->deleteForm('admin.income.destroy', $income), false);

        $this->actingAs($clerk)->delete(route('admin.income.destroy', $income))
            ->assertRedirect(route('admin.income.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('incomes', ['id' => $income->getKey()]);
    }

    #[Test]
    public function a_voided_income_offers_no_delete_and_the_route_refuses_it(): void
    {
        $clerk = $this->clerk('income');
        $voided = app(IncomeService::class)->void($this->receiveOther('2500.00'), 'Entered twice', $this->createSuperAdmin());

        $this->actingAs($clerk)->get(route('admin.income.index'))
            ->assertOk()
            ->assertDontSee($this->deleteForm('admin.income.destroy', $voided), false);

        $this->actingAs($clerk)->get(route('admin.income.show', $voided))
            ->assertOk()
            ->assertDontSee($this->deleteForm('admin.income.destroy', $voided), false);

        $this->actingAs($clerk)->delete(route('admin.income.destroy', $voided))->assertForbidden();
        $this->actingAs($this->createSuperAdmin())->delete(route('admin.income.destroy', $voided))->assertForbidden();

        $this->assertNotSoftDeleted('incomes', ['id' => $voided->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unissued_draft_offers_edit_and_delete_and_can_be_deleted(): void
    {
        $clerk = $this->clerk('invoices');
        $draft = $this->draftInvoice();

        $this->actingAs($clerk)->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertSee(route('admin.invoices.edit', $draft), false)
            ->assertSee($this->deleteForm('admin.invoices.destroy', $draft), false);

        $this->actingAs($clerk)->get(route('admin.invoices.show', $draft))
            ->assertOk()
            ->assertSee($this->deleteForm('admin.invoices.destroy', $draft), false);

        $this->actingAs($clerk)->delete(route('admin.invoices.destroy', $draft))
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('invoices', ['id' => $draft->getKey()]);
    }

    #[Test]
    public function an_issued_invoice_offers_no_delete_and_the_route_refuses_it(): void
    {
        $clerk = $this->clerk('invoices');
        $issued = app(InvoiceService::class)->issue($this->draftInvoice(), $this->createSuperAdmin());

        $this->assertNotNull($issued->invoice_number);

        $this->actingAs($clerk)->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertDontSee($this->deleteForm('admin.invoices.destroy', $issued), false);

        $this->actingAs($clerk)->get(route('admin.invoices.show', $issued))
            ->assertOk()
            ->assertDontSee($this->deleteForm('admin.invoices.destroy', $issued), false);

        $this->actingAs($clerk)->delete(route('admin.invoices.destroy', $issued))->assertForbidden();
        $this->actingAs($this->createSuperAdmin())->delete(route('admin.invoices.destroy', $issued))->assertForbidden();

        $this->assertNotSoftDeleted('invoices', ['id' => $issued->getKey()]);
        $this->assertNotNull($issued->fresh()->invoice_number, 'An issued invoice keeps its number.');
    }

    #[Test]
    public function without_the_delete_permission_no_register_offers_it(): void
    {
        $reader = $this->createUserWithPermissions([
            'expenses.view_any', 'expenses.view', 'income.view_any', 'income.view',
            'invoices.view_any', 'invoices.view',
        ]);

        $expense = $this->spend('5000.00');
        $income = $this->receiveOther('2500.00');
        $draft = $this->draftInvoice();

        $this->actingAs($reader)->get(route('admin.expenses.index'))->assertOk()
            ->assertDontSee($this->deleteForm('admin.expenses.destroy', $expense), false)
            ->assertDontSee(route('admin.expenses.edit', $expense), false);
        $this->actingAs($reader)->get(route('admin.income.index'))->assertOk()
            ->assertDontSee($this->deleteForm('admin.income.destroy', $income), false);
        $this->actingAs($reader)->get(route('admin.invoices.index'))->assertOk()
            ->assertDontSee($this->deleteForm('admin.invoices.destroy', $draft), false);

        $this->actingAs($reader)->delete(route('admin.expenses.destroy', $expense))->assertForbidden();
        $this->actingAs($reader)->delete(route('admin.income.destroy', $income))->assertForbidden();
        $this->actingAs($reader)->delete(route('admin.invoices.destroy', $draft))->assertForbidden();
    }

    private function clerk(string $module): User
    {
        return $this->createUserWithPermissions([
            $module.'.view_any', $module.'.view', $module.'.edit', $module.'.delete',
        ]);
    }

    /** The confirm dialog's form, which the show link (same URL, GET) never produces. */
    private function deleteForm(string $route, mixed $model): string
    {
        return 'action="'.route($route, $model).'"';
    }

    protected function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
