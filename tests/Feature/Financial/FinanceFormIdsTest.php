<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Enums\FinanceContext;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Finance\Expense;
use App\Models\Finance\Income;
use App\Models\Finance\PaymentMethodOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Financial\Concerns\BuildsInvoiceFixtures;
use Tests\TestCase;

/**
 * The expense and income forms post every id as a string, exactly as a browser does.
 *
 * `ExpenseTest` builds `ExpenseData` itself with real integers, so it never saw what the
 * controllers hand over: `integer` validation accepts "3" without casting it, and the DTO's `?int`
 * parameters refuse a string under strict_types. Choosing a project, branch, client or payment
 * method on either form was a TypeError until the controllers cast the ids.
 */
final class FinanceFormIdsTest extends TestCase
{
    use BuildsInvoiceFixtures;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function an_expense_form_with_string_ids_is_recorded(): void
    {
        $branchId = (string) Branch::query()->value('id');
        $methodId = (string) PaymentMethodOption::query()->value('id');

        $this->actingAs($this->createSuperAdmin())
            ->post(route('admin.expenses.store'), [
                'finance_category_id' => (string) $this->expenseCategory()->getKey(),
                'context' => FinanceContext::General->value,
                'title' => 'Office rent',
                'amount' => '1500.00',
                'expense_date' => now()->toDateString(),
                'payment_method' => PaymentMethod::Cash->value,
                'payment_method_id' => $methodId,
                'branch_id' => $branchId,
                'idempotency_key' => 'form-ids-expense',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense = Expense::query()->sole();
        $this->assertSame((int) $branchId, (int) $expense->branch_id);
        $this->assertSame((int) $methodId, (int) $expense->payment_method_id);
    }

    #[Test]
    public function an_income_form_with_string_ids_is_recorded(): void
    {
        $branchId = (string) Branch::query()->value('id');
        $methodId = (string) PaymentMethodOption::query()->value('id');
        $clientId = (string) $this->billingClient()->getKey();

        $this->actingAs($this->createSuperAdmin())
            ->post(route('admin.income.store'), [
                'finance_category_id' => (string) $this->incomeCategory()->getKey(),
                'context' => FinanceContext::SoftwareHouse->value,
                'title' => 'Advisory retainer',
                'amount' => '2500.00',
                'received_on' => now()->toDateString(),
                'payment_method' => PaymentMethod::Cash->value,
                'payment_method_id' => $methodId,
                'branch_id' => $branchId,
                'client_id' => $clientId,
                'idempotency_key' => 'form-ids-income',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $income = Income::query()->sole();
        $this->assertSame((int) $clientId, (int) $income->client_id);
        $this->assertSame((int) $methodId, (int) $income->payment_method_id);
    }
}
