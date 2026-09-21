<?php

declare(strict_types=1);

namespace Tests\Feature\Financial\Concerns;

use App\DataObjects\Finance\ExpenseData;
use App\DataObjects\Finance\InvoiceData;
use App\Enums\DiscountMode;
use App\Enums\FinanceCategoryType;
use App\Enums\FinanceContext;
use App\Enums\PaymentMethod;
use App\Models\Crm\Client;
use App\Models\Finance\Expense;
use App\Models\Finance\FinanceCategory;
use App\Models\Finance\Income;
use App\Models\Finance\Invoice;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\IncomeService;
use App\Services\Finance\InvoiceService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures for the Phase 13 acceptance suite.
 *
 * Everything goes through the service that owns it — no direct inserts into `invoices`, `expenses` or
 * `incomes`. A fixture that wrote a row directly would be testing a shape the application never
 * produces, and the first thing it would stop catching is the service forgetting to derive something.
 */
trait BuildsInvoiceFixtures
{
    private int $invoiceFixtureSequence = 0;

    protected function billingClient(string $name = 'Acme Trading'): Client
    {
        $this->invoiceFixtureSequence++;

        $client = new Client;

        $client->forceFill([
            'client_code' => 'CL-T'.str_pad((string) $this->invoiceFixtureSequence, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'company_name' => $name,
            'email' => sprintf('buyer%d@example.test', $this->invoiceFixtureSequence),
            'status' => 'active',
        ])->save();

        return $client->refresh();
    }

    /**
     * A draft invoice with whatever lines were asked for.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function draftInvoice(?Client $client = null, array $lines = [], array $options = []): Invoice
    {
        $client ??= $this->billingClient();

        return app(InvoiceService::class)->create(new InvoiceData(
            clientId: (int) $client->getKey(),
            issueDate: Carbon::parse($options['issue_date'] ?? now()->toDateString()),
            lines: $lines === [] ? [$this->line()] : $lines,
            dueDate: isset($options['due_date']) ? Carbon::parse($options['due_date']) : null,
            title: $options['title'] ?? 'Development work',
            discountMode: $options['discount_mode'] ?? DiscountMode::None,
            discountRate: $options['discount_rate'] ?? null,
            discountFixed: $options['discount_fixed'] ?? null,
        ), $options['actor'] ?? null);
    }

    /**
     * One line, with sensible defaults so a test names only what it cares about.
     *
     * @return array<string, mixed>
     */
    protected function line(string $unitPrice = '10000.00', string $quantity = '1.0000', array $overrides = []): array
    {
        return array_merge([
            'description' => 'Consulting',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'is_taxable' => false,
        ], $overrides);
    }

    protected function expenseCategory(string $code = 'rent'): FinanceCategory
    {
        return FinanceCategory::query()
            ->where('type', FinanceCategoryType::Expense->value)
            ->where('code', $code)
            ->firstOrFail();
    }

    protected function incomeCategory(string $code = 'consulting'): FinanceCategory
    {
        return FinanceCategory::query()
            ->where('type', FinanceCategoryType::Income->value)
            ->where('code', $code)
            ->firstOrFail();
    }

    protected function spend(string $amount = '50000.00', array $options = []): Expense
    {
        $this->invoiceFixtureSequence++;

        return app(ExpenseService::class)->record(new ExpenseData(
            financeCategoryId: (int) ($options['category'] ?? $this->expenseCategory())->getKey(),
            context: $options['context'] ?? FinanceContext::General,
            title: $options['title'] ?? 'Office rent',
            amount: Money::of($amount),
            valueDate: Carbon::parse($options['on'] ?? now()->toDateString()),
            paymentMethod: $options['method'] ?? PaymentMethod::BankTransfer,
            idempotencyKey: $options['key'] ?? 'exp-'.$this->invoiceFixtureSequence,
        ), $options['actor'] ?? null);
    }

    protected function receiveOther(string $amount = '25000.00', array $options = []): Income
    {
        $this->invoiceFixtureSequence++;

        return app(IncomeService::class)->record(new ExpenseData(
            financeCategoryId: (int) ($options['category'] ?? $this->incomeCategory())->getKey(),
            context: $options['context'] ?? FinanceContext::SoftwareHouse,
            title: $options['title'] ?? 'Advisory retainer',
            amount: Money::of($amount),
            valueDate: Carbon::parse($options['on'] ?? now()->toDateString()),
            paymentMethod: $options['method'] ?? PaymentMethod::BankTransfer,
            idempotencyKey: $options['key'] ?? 'inc-'.$this->invoiceFixtureSequence,
        ), $options['actor'] ?? null);
    }

    /**
     * The five identities of §2.7, re-checked straight from the database.
     *
     * The service asserts them before writing; this asserts them after, so a column written wrongly
     * would be caught even though the arithmetic that produced it was right.
     */
    protected function assertInvoiceBalances(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();

        $lines = DB::table('invoice_items')->where('invoice_id', $invoice->getKey())->get();

        $lineTotals = Money::sum($lines->map(fn ($l): string => Money::of((string) $l->line_total))->all() ?: [Money::ZERO]);
        $allocated = Money::sum($lines->map(fn ($l): string => Money::of((string) $l->allocated_discount_amount))->all() ?: [Money::ZERO]);
        $taxable = Money::sum($lines->map(fn ($l): string => Money::of((string) $l->taxable_amount))->all() ?: [Money::ZERO]);

        $this->assertSame(
            (string) $invoice->total_amount,
            Money::add(
                Money::add(
                    Money::sub((string) $invoice->subtotal_amount, (string) $invoice->total_discount_amount),
                    (string) $invoice->tax_amount,
                ),
                (string) $invoice->round_off_amount,
            ),
            'total = subtotal − discounts + tax + round-off',
        );

        $this->assertSame((string) $invoice->total_amount, Money::add($lineTotals, (string) $invoice->round_off_amount),
            'total = the sum of the printed lines + round-off');
        $this->assertSame((string) $invoice->taxable_amount, $taxable, 'taxable = the sum of the taxable lines');
        $this->assertSame((string) $invoice->discount_amount, $allocated,
            'the apportioned shares sum to the invoice discount');
        $this->assertSame((string) $invoice->balance_amount,
            Money::sub((string) $invoice->total_amount, (string) $invoice->paid_amount),
            'balance = total − paid');
    }
}
