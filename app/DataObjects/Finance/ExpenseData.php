<?php

declare(strict_types=1);

namespace App\DataObjects\Finance;

use App\Enums\FinanceContext;
use App\Enums\PaymentMethod;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What somebody typed into the expense form (phase-13 §6.4), and the same object the income form uses.
 *
 * **`idempotencyKey` has no default of "none".** A double-submitted expense form is the ordinary
 * failure — somebody clicks Save, the page is slow, they click again — and the only thing that makes it
 * harmless is a key the form generated once. {@see key()} invents one when a caller genuinely has
 * nothing to offer (a console command, a seeder), which is the case where a replay cannot happen.
 */
final readonly class ExpenseData
{
    public function __construct(
        public int $financeCategoryId,
        public FinanceContext $context,
        public string $title,
        public string $amount,
        public CarbonInterface $valueDate,
        public PaymentMethod $paymentMethod,
        public ?int $branchId = null,
        public ?int $projectId = null,
        public ?int $clientId = null,
        public ?int $paymentMethodId = null,
        public ?string $description = null,
        /** The payee on an expense, the payer on an income. */
        public ?string $counterparty = null,
        public ?string $referenceNo = null,
        public ?string $receiptPath = null,
        public ?string $notes = null,
        public ?string $idempotencyKey = null,
        /** Set only by a derived row — today, a paid payroll run (D44). */
        public ?string $sourceType = null,
        public ?int $sourceId = null,
    ) {}

    public static function fromRequest(Request $request, string $dateField = 'expense_date'): self
    {
        return new self(
            financeCategoryId: $request->integer('finance_category_id'),
            context: FinanceContext::from((string) $request->input('context')),
            title: (string) $request->input('title'),
            amount: Money::of((string) $request->input('amount')),
            valueDate: Carbon::parse((string) $request->input($dateField)),
            paymentMethod: PaymentMethod::from((string) $request->input('payment_method')),
            branchId: $request->filled('branch_id') ? $request->integer('branch_id') : null,
            projectId: $request->filled('project_id') ? $request->integer('project_id') : null,
            clientId: $request->filled('client_id') ? $request->integer('client_id') : null,
            paymentMethodId: $request->filled('payment_method_id') ? $request->integer('payment_method_id') : null,
            description: $request->input('description'),
            counterparty: $request->input('paid_to', $request->input('received_from')),
            referenceNo: $request->input('reference_no'),
            notes: $request->input('notes'),
            idempotencyKey: $request->input('idempotency_key'),
        );
    }

    public function key(): string
    {
        $supplied = trim((string) $this->idempotencyKey);

        return $supplied === '' ? (string) Str::ulid() : mb_substr($supplied, 0, 64);
    }

    public function amount(): string
    {
        return Money::of($this->amount);
    }
}
